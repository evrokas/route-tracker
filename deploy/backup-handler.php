#!/usr/bin/env php
<?php
/**
 * Route Tracker's zops backup handler. See ../../zops/docs/HANDLERS.md.
 *
 * Elements shipped:
 *   - db      the SQLite database (data/routes.sqlite), hot-backed-up via
 *             zops's SqliteDump helper -- VACUUM INTO, safe under WAL
 *             mode, PRAGMA integrity_check/foreign_key_check-verified,
 *             gzipped
 *   - state   data/alert_counts.json -- small, but losing it re-fires
 *             every alert this app has already sent today, since it's
 *             the only record of per-route daily alert counts (see
 *             AlertManager's own rate-limiting). Absent on a fresh
 *             install (no alerts sent yet), in which case this element
 *             is simply omitted rather than failing the run.
 *   - config  config/settings.php, if present -- deployment-level
 *             constants only (paths, timeouts); the one real secret this
 *             app has, the Google Maps API key, lives in the `settings`
 *             SQLite table (already covered by the db element), not here
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';

use ZOps\Handler\ConfigBundle;
use ZOps\Handler\Ctx;
use ZOps\Handler\Handler;
use ZOps\Handler\SqliteDump;

Handler::backup([
    'id' => 'route-tracker',
    'name' => 'Route Tracker',
    'prepare' => function (Ctx $c): void {
        $appRoot = dirname(__DIR__);
        $settingsFile = $appRoot . '/config/settings.php';
        $userSettings = is_file($settingsFile) ? (array) require $settingsFile : [];
        $dataDir = $appRoot . '/' . ($userSettings['data_dir'] ?? 'data');
        $dbPath = $dataDir . '/' . ($userSettings['db_filename'] ?? 'routes.sqlite');

        $c->add(SqliteDump::zip($c, 'db', $dbPath));

        $countFile = $dataDir . '/alert_counts.json';
        if (is_file($countFile)) {
            $target = $c->elementsDir() . '/alert_counts.json';
            copy($countFile, $target);
            $c->add([
                'name' => 'state', 'type' => 'file', 'path' => $target,
                'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target),
                'verified' => true,
            ]);
        }

        if (is_file($settingsFile)) {
            $c->add(ConfigBundle::zip($c, 'config', [$settingsFile]));
        }

        $stats = ['routes' => 0, 'trips' => 0, 'active_routes' => 0];
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stats['routes'] = (int) $pdo->query('SELECT COUNT(*) FROM routes')->fetchColumn();
            $stats['active_routes'] = (int) $pdo->query('SELECT COUNT(*) FROM routes WHERE active = 1')->fetchColumn();
            $stats['trips'] = (int) $pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
        } catch (Throwable $e) {
            // Non-fatal for the stats block -- SqliteDump::zip() above
            // either already succeeded (the backup itself is fine) or
            // already threw and aborted the run before this point.
        }
        $c->setStats($stats);
    },
]);
