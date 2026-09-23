#!/usr/bin/env php
<?php
/**
 * Route Tracker's zops health handler. See ../../zops/docs/HANDLERS.md.
 *
 * Unlike the web-app handlers in this suite, this app's real failure mode
 * is invisible over HTTP: a route-tracker with a broken cron still serves
 * its dashboard fine, just against stale data. So the checks here are
 * about the cron pipeline (advisor.log freshness, recent trip rows), not
 * a web route.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';

use ZOps\Handler\Handler;
use ZOps\Handler\HealthCtx;

Handler::health([
    'id' => 'route-tracker',
    'name' => 'Route Tracker',
    'check' => function (HealthCtx $h): void {
        $appRoot = dirname(__DIR__);
        $settingsFile = $appRoot . '/config/settings.php';
        $userSettings = is_file($settingsFile) ? (array) require $settingsFile : [];
        $dataDir = $appRoot . '/' . ($userSettings['data_dir'] ?? 'data');
        $dbPath = $dataDir . '/' . ($userSettings['db_filename'] ?? 'routes.sqlite');

        $pdo = null;
        if (!is_file($dbPath)) {
            $h->fail('db.connect', 'SQLite database opens', "not found: {$dbPath}");
        } else {
            try {
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $quickCheck = $pdo->query('PRAGMA quick_check')->fetchColumn();
                ($quickCheck === 'ok')
                    ? $h->ok('db.connect', 'SQLite database opens + quick_check')
                    : $h->fail('db.connect', 'SQLite database opens + quick_check', (string) $quickCheck);
            } catch (Throwable $e) {
                $h->fail('db.connect', 'SQLite database opens + quick_check', $e->getMessage());
            }
        }

        is_dir($dataDir) && $h->writeProbe($dataDir)
            ? $h->ok('fs.data.write', 'data/ writable')
            : $h->fail('fs.data.write', 'data/ writable', is_dir($dataDir) ? 'write probe failed' : 'directory does not exist');

        // advisor.php runs every 5 minutes via cron (see CLAUDE.md) and
        // appends to this log on every run, success or not -- its mtime
        // going stale is the single clearest "the cron stopped running
        // entirely" signal available, since a broken cron produces no
        // HTTP symptom at all.
        $advisorLog = $dataDir . '/advisor.log';
        if (!is_file($advisorLog)) {
            $h->warn('cron.advisor.fresh', 'advisor.log updated recently', 'log file does not exist yet -- cron may never have run');
        } else {
            $ageMinutes = (int) round((time() - filemtime($advisorLog)) / 60);
            ($ageMinutes <= 15)
                ? $h->ok('cron.advisor.fresh', 'advisor.log updated recently')
                : $h->fail('cron.advisor.fresh', 'advisor.log updated recently', "last write {$ageMinutes} minutes ago -- expected every 5 minutes");
        }

        if ($pdo !== null) {
            try {
                $apiKey = $pdo->prepare("SELECT value FROM settings WHERE key = 'google_maps_api_key'");
                $apiKey->execute();
                $keyValue = (string) $apiKey->fetchColumn();
                ($keyValue !== '')
                    ? $h->ok('config.google_maps_key', 'Google Maps API key configured')
                    : $h->fail('config.google_maps_key', 'Google Maps API key configured', 'settings.google_maps_api_key is empty');
            } catch (Throwable $e) {
                $h->fail('config.google_maps_key', 'Google Maps API key configured', $e->getMessage());
            }

            $h->info('active_routes', (int) $pdo->query('SELECT COUNT(*) FROM routes WHERE active = 1')->fetchColumn());
            $h->info('trips_collected_today', (int) $pdo->query("SELECT COUNT(*) FROM trips WHERE date(collected_at) = date('now')")->fetchColumn());

            $lastTrip = $pdo->query('SELECT MAX(collected_at) FROM trips')->fetchColumn();
            if ($lastTrip) {
                $h->info('last_collection_at', $lastTrip);
                $ageMinutes = (int) round((time() - strtotime((string) $lastTrip)) / 60);
                ($ageMinutes <= 60)
                    ? $h->ok('collection.fresh', 'Most recent trip collected within the last hour')
                    : $h->warn('collection.fresh', 'Most recent trip collected within the last hour', "last collection {$ageMinutes} minutes ago");
            } else {
                $h->warn('collection.fresh', 'Most recent trip collected within the last hour', 'no trips recorded yet');
            }
        }

        $countFile = $dataDir . '/alert_counts.json';
        $alertsToday = 0;
        if (is_file($countFile)) {
            $counts = json_decode((string) file_get_contents($countFile), true);
            $today = date('Y-m-d');
            if (is_array($counts) && isset($counts[$today]) && is_array($counts[$today])) {
                $alertsToday = array_sum($counts[$today]);
            }
        }
        $h->info('alerts_sent_today', $alertsToday);
    },
]);
