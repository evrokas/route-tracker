#!/usr/bin/env php
<?php

/**
 * collector.php — Route Tracker v3
 * Main data collector. Run via cron or CLI.
 *
 * Usage:
 *   php collector.php                              # Collect active routes (scheduled window)
 *   php collector.php --force                      # Collect ALL active routes now
 *   php collector.php --force --route=dad_work     # Force-collect one route
 *   php collector.php --test                       # Call API, show results, don't save
 *   php collector.php --test --route=son_learning  # Test one route
 *   php collector.php --schedule                   # Print schedule + cron lines
 *   php collector.php --test-alerts                # Send test alerts
 *   php collector.php --test-alerts --route=dad_work
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';
require_once $baseDir . '/AlertManager.php';

// Only run CLI logic if executed directly (not included from advisor.php)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    collectorMain($baseDir);
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// CLI entry point
// ═════════════════════════════════════════════════════════════════════════════

function collectorMain(string $baseDir): void
{
    global $argv;

    $args       = array_slice($argv ?? [], 1);
    $force      = in_array('--force',       $args, true);
    $test       = in_array('--test',        $args, true);
    $testAlerts = in_array('--test-alerts', $args, true);
    $schedule   = in_array('--schedule',    $args, true);

    $routeFilter = null;
    foreach ($args as $a) {
        if (str_starts_with($a, '--route=')) {
            $routeFilter = substr($a, 8);
        }
    }

    try {
        $config = Config::load($baseDir);
        date_default_timezone_set($config->getTimezone());
    } catch (Exception $e) {
        die("Config error: " . $e->getMessage() . "\n");
    }

    $logFile  = $baseDir . '/data/collector.log';
    $alertMgr = new AlertManager($config);

    @mkdir($baseDir . '/data', 0775, true);

    if ($schedule) {
        printSchedule($config);
        return;
    }

    if ($testAlerts) {
        echo "Sending test alerts…\n\n";
        $alertMgr->sendTest($routeFilter);
        echo "\nDone.\n";
        return;
    }

    $pdo = $config->getPdo();

    if ($force || $test) {
        if ($routeFilter) {
            $route = $config->getRoute($routeFilter);
            if (!$route) {
                die("Route not found: {$routeFilter}\n");
            }
            $routesToProcess = [buildForceEntry($route)];
        } else {
            $routesToProcess = [];
            foreach ($config->getAllActiveRoutes() as $route) {
                $routesToProcess[] = buildForceEntry($route);
            }
        }
    } else {
        $routesToProcess = $config->getActiveRoutes();
        if (empty($routesToProcess)) {
            clog($logFile, "No active routes in collection window. Exiting.");
            return;
        }
    }

    foreach ($routesToProcess as $route) {
        processRoute($route, $pdo, $config, $alertMgr, $logFile, $test);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Core functions (also used by advisor.php)
// ═════════════════════════════════════════════════════════════════════════════

function processRoute(array $route, PDO $pdo, Config $config, AlertManager $alertMgr, string $logFile, bool $testMode): void
{
    $routeId = $route['id'];
    clog($logFile, "Collecting: {$routeId} ({$route['label']})");

    $params = [
        'origin'         => $route['origin'],
        'destination'    => $route['destination'],
        'mode'           => $route['travel_mode'] ?? 'driving',
        'departure_time' => 'now',
        'language'       => $config->getSetting('google_maps_language', 'el'),
        'region'         => $config->getSetting('google_maps_region', 'gr'),
        'key'            => $config->getApiKey(),
    ];

    if ($config->requestAlternatives()) {
        $params['alternatives'] = 'true';
    }

    $url      = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);
    $response = callApi($url);

    if ($response === null) {
        $msg = "cURL error calling Google Maps API";
        clog($logFile, "ERROR: {$msg}");
        if (!$testMode) {
            $alertMgr->sendErrorAlert($route, $msg);
        }
        return;
    }

    $data   = json_decode($response, true);
    $status = $data['status'] ?? 'UNKNOWN';

    if ($testMode) {
        printTestResult($route, $data);
        return;
    }

    $schedMode = $route['_schedule_mode']  ?? 'depart';
    $schedTime = $route['_scheduled_time'] ?? date('H:i');
    $now       = new DateTime();

    if ($status !== 'OK') {
        clog($logFile, "API status={$status} for {$routeId}");
        insertTrip($pdo, [
            'route_id'                 => $routeId,
            'collected_at'             => $now->format('Y-m-d H:i:s'),
            'scheduled_day'            => (int)$now->format('N'),
            'day_of_week'              => $now->format('D'),
            'scheduled_time'           => $schedTime,
            'schedule_mode'            => $schedMode,
            'year'                     => (int)$now->format('Y'),
            'month'                    => (int)$now->format('n'),
            'week_number'              => (int)$now->format('W'),
            'duration_seconds'         => null,
            'traffic_duration_seconds' => null,
            'distance_meters'          => null,
            'primary_summary'          => null,
            'best_alt_seconds'         => null,
            'best_alt_summary'         => null,
            'api_status'               => $status,
        ]);
        $alertMgr->sendErrorAlert($route, "Google Maps API returned status: {$status}");
        return;
    }

    $apiRoutes = $data['routes'] ?? [];
    if (empty($apiRoutes)) {
        clog($logFile, "No routes returned for {$routeId}");
        return;
    }

    $primary        = $apiRoutes[0];
    $leg            = $primary['legs'][0] ?? [];
    $primaryDuration = $leg['duration']['value']            ?? 0;
    $primaryTraffic  = $leg['duration_in_traffic']['value'] ?? $primaryDuration;
    $primarySummary  = $primary['summary'] ?? '';
    $primaryDistance = $leg['distance']['value'] ?? 0;

    $bestAltSec     = null;
    $bestAltSummary = null;

    foreach (array_slice($apiRoutes, 1) as $alt) {
        $altLeg = $alt['legs'][0] ?? [];
        $altDur = $altLeg['duration_in_traffic']['value'] ?? ($altLeg['duration']['value'] ?? PHP_INT_MAX);
        if ($bestAltSec === null || $altDur < $bestAltSec) {
            $bestAltSec     = $altDur;
            $bestAltSummary = $alt['summary'] ?? '';
        }
    }

    $tripId = insertTrip($pdo, [
        'route_id'                 => $routeId,
        'collected_at'             => $now->format('Y-m-d H:i:s'),
        'scheduled_day'            => (int)$now->format('N'),
        'day_of_week'              => $now->format('D'),
        'scheduled_time'           => $schedTime,
        'schedule_mode'            => $schedMode,
        'year'                     => (int)$now->format('Y'),
        'month'                    => (int)$now->format('n'),
        'week_number'              => (int)$now->format('W'),
        'duration_seconds'         => $primaryDuration,
        'traffic_duration_seconds' => $primaryTraffic,
        'distance_meters'          => $primaryDistance,
        'primary_summary'          => $primarySummary,
        'best_alt_seconds'         => $bestAltSec,
        'best_alt_summary'         => $bestAltSummary,
        'api_status'               => 'OK',
    ]);

    $minSamples  = (int)$config->getSetting('alert_min_samples', 5);
    $avgDuration = getHistoricalAverage($pdo, $routeId, (int)$now->format('N'), $schedTime, $minSamples);

    $primaryArr          = $leg;
    $primaryArr['summary'] = $primarySummary;
    $bestAltArr          = null;

    if ($bestAltSec !== null && !empty($apiRoutes[1])) {
        $bestAltLeg              = $apiRoutes[1]['legs'][0] ?? [];
        $bestAltArr              = $bestAltLeg;
        $bestAltArr['summary']   = $bestAltSummary;
    }

    $alertMgr->evaluateAndAlert(
        $route, $route,
        $primaryTraffic, $avgDuration,
        $primaryArr, $bestAltArr, $bestAltSec
    );

    $minTraffic = round($primaryTraffic / 60, 1);
    clog($logFile, "Saved trip {$tripId}: {$primarySummary} {$minTraffic}min" .
         ($avgDuration ? " (avg=" . round($avgDuration / 60, 1) . "min)" : " (no avg yet)"));
}

// ─── API call ─────────────────────────────────────────────────────────────────

function callApi(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "cURL error: {$error}\n";
        return null;
    }
    return $resp ?: null;
}

// ─── DB insert ────────────────────────────────────────────────────────────────

function insertTrip(PDO $pdo, array $d): int
{
    $st = $pdo->prepare("
        INSERT INTO trips
            (route_id, collected_at, scheduled_day, day_of_week, scheduled_time,
             schedule_mode, year, month, week_number,
             duration_seconds, traffic_duration_seconds, distance_meters,
             primary_summary, best_alt_seconds, best_alt_summary, api_status)
        VALUES
            (:route_id, :collected_at, :scheduled_day, :day_of_week, :scheduled_time,
             :schedule_mode, :year, :month, :week_number,
             :duration_seconds, :traffic_duration_seconds, :distance_meters,
             :primary_summary, :best_alt_seconds, :best_alt_summary, :api_status)
    ");
    $st->execute($d);
    return (int)$pdo->lastInsertId();
}

// ─── Historical average ───────────────────────────────────────────────────────

function getHistoricalAverage(PDO $pdo, string $routeId, int $day, string $schedTime, int $minSamples = 5): ?int
{
    $st = $pdo->prepare("
        SELECT AVG(traffic_duration_seconds) AS avg_dur,
               COUNT(*) AS samples
        FROM trips
        WHERE route_id      = :route_id
          AND scheduled_day = :day
          AND scheduled_time= :sched_time
          AND api_status    = 'OK'
    ");
    $st->execute([':route_id' => $routeId, ':day' => $day, ':sched_time' => $schedTime]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['samples'] < $minSamples) {
        return null;
    }
    return (int)round($row['avg_dur']);
}

// ─── Force-build a route entry without a real schedule entry ─────────────────

function buildForceEntry(array $route): array
{
    $sched = $route['schedule'][0] ?? [];
    $time  = $sched['arrive'] ?? $sched['depart'] ?? date('H:i');
    $mode  = isset($sched['arrive']) ? 'arrive' : 'depart';

    return array_merge($route, [
        '_schedule'       => $sched,
        '_schedule_mode'  => $mode,
        '_scheduled_time' => $time,
        '_collect_at'     => date('H:i'),
    ]);
}

// ─── Print test result ────────────────────────────────────────────────────────

function printTestResult(array $route, array $data): void
{
    $status = $data['status'] ?? 'UNKNOWN';
    echo "\n══════════════════════════════════════════\n";
    echo "Route: {$route['label']}\n";
    echo "Status: {$status}\n";
    echo "══════════════════════════════════════════\n";

    if ($status !== 'OK') {
        echo "Error message: " . ($data['error_message'] ?? '(none)') . "\n";
        return;
    }

    foreach ($data['routes'] ?? [] as $i => $r) {
        $leg   = $r['legs'][0] ?? [];
        $label = $i === 0 ? '★ PRIMARY' : "  ALT " . $i;
        $dur   = $leg['duration']['text']            ?? '?';
        $traf  = $leg['duration_in_traffic']['text'] ?? 'n/a';
        $dist  = $leg['distance']['text']            ?? '?';
        $summ  = $r['summary'] ?? '(no summary)';
        echo "\n{$label}: {$summ}\n";
        echo "  Distance:         {$dist}\n";
        echo "  Duration:         {$dur}\n";
        echo "  Traffic duration: {$traf}\n";
        echo "  From: " . ($leg['start_address'] ?? '?') . "\n";
        echo "  To:   " . ($leg['end_address']   ?? '?') . "\n";
        if (!empty($r['warnings'])) {
            echo "  ⚠ " . implode("\n  ⚠ ", $r['warnings']) . "\n";
        }
    }
    echo "\n";
}

// ─── Print schedule and cron lines ───────────────────────────────────────────

function printSchedule(Config $config): void
{
    $schedule = $config->getFullSchedule();
    $dayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    $phpBin   = PHP_BINARY;
    $script   = realpath(__FILE__);
    $advisor  = dirname($script) . '/advisor.php';

    echo "\n═══ Full Collection Schedule ═══\n\n";

    foreach ($schedule as $day => $entries) {
        echo ($dayNames[$day] ?? $day) . ":\n";
        usort($entries, fn($a, $b) => strcmp($a['collect_at'], $b['collect_at']));
        foreach ($entries as $e) {
            $icon = $e['mode'] === 'arrive' ? '🏢' : '🚗';
            echo "  {$icon} {$e['time']} ({$e['mode']}) — {$e['label']}";
            if ($e['mode'] === 'arrive') {
                echo " [collect ~{$e['collect_at']}]";
            }
            echo "\n";
        }
        echo "\n";
    }

    echo "═══ Suggested Cron Line (v3 — single advisor.php job) ═══\n\n";
    echo "# Route Tracker v3 — advisor runs every 5 min\n";
    echo "*/5 * * * * {$phpBin} {$advisor} >> " . dirname($script) . "/data/advisor.log 2>&1\n\n";
}

// ─── Logging ──────────────────────────────────────────────────────────────────

function clog(string $logFile, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}
