#!/usr/bin/env php
<?php

/**
 * collector.php — Route Tracker v2
 * Main data collector. Run via cron or CLI.
 *
 * Usage:
 *   php collector.php                              # Collect active routes (scheduled window)
 *   php collector.php --force                      # Collect ALL routes now
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

// ─── Parse CLI args ───────────────────────────────────────────────────────────

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

// ─── Boot ─────────────────────────────────────────────────────────────────────

try {
    $config = Config::load($baseDir);
    date_default_timezone_set($config->getTimezone());
} catch (Exception $e) {
    die("Config error: " . $e->getMessage() . "\n");
}

$logFile   = $baseDir . '/data/collector.log';
$alertMgr  = new AlertManager($config);

// ─── Ensure data dir ──────────────────────────────────────────────────────────

@mkdir($baseDir . '/data', 0775, true);

// ─── Route: --schedule ────────────────────────────────────────────────────────

if ($schedule) {
    printSchedule($config);
    exit(0);
}

// ─── Route: --test-alerts ────────────────────────────────────────────────────

if ($testAlerts) {
    echo "Sending test alerts…\n\n";
    $alertMgr->sendTest($routeFilter);
    echo "\nDone.\n";
    exit(0);
}

// ─── Open database ────────────────────────────────────────────────────────────

try {
    $pdo = openDb($config);
} catch (Exception $e) {
    clog($logFile, "DB error: " . $e->getMessage());
    die("Database error: " . $e->getMessage() . "\n");
}

// ─── Determine which routes to collect ───────────────────────────────────────

if ($force || $test) {
    if ($routeFilter) {
        $route = $config->getRoute($routeFilter);
        if (!$route) {
            die("Route not found: {$routeFilter}\n");
        }
        $routesToProcess = [buildForceEntry($route, $config)];
    } else {
        $routesToProcess = [];
        foreach ($config->getAllRoutes() as $route) {
            $routesToProcess[] = buildForceEntry($route, $config);
        }
    }
} else {
    // Normal scheduled run
    $routesToProcess = $config->getActiveRoutes();
    if (empty($routesToProcess)) {
        clog($logFile, "No active routes in collection window. Exiting.");
        exit(0);
    }
}

// ─── Process each route ───────────────────────────────────────────────────────

foreach ($routesToProcess as $route) {
    processRoute($route, $pdo, $config, $alertMgr, $logFile, $test);
}

exit(0);

// ═════════════════════════════════════════════════════════════════════════════
// Functions
// ═════════════════════════════════════════════════════════════════════════════

function processRoute(array $route, PDO $pdo, Config $config, AlertManager $alertMgr, string $logFile, bool $testMode): void
{
    $routeId = $route['id'];
    clog($logFile, "Collecting: {$routeId} ({$route['label']})");

    // Build API request
    $params = [
        'origin'           => $route['origin'],
        'destination'      => $route['destination'],
        'mode'             => $route['travel_mode'] ?? 'driving',
        'departure_time'   => 'now',
        'language'         => $config->get('google_maps.language', 'el'),
        'region'           => $config->get('google_maps.region', 'gr'),
        'key'              => $config->getApiKey(),
    ];

    if ($config->requestAlternatives()) {
        $params['alternatives'] = 'true';
    }

    $url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);

    // Call Google Maps API
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

    // Save collection record
    $sched       = $route['_schedule']       ?? [];
    $schedMode   = $route['_schedule_mode']  ?? 'depart';
    $schedTime   = $route['_scheduled_time'] ?? date('H:i');
    $now         = new DateTime();
    $collectedAt = $now->format('Y-m-d H:i:s');

    $collId = insertCollection($pdo, [
        'route_id'       => $routeId,
        'route_label'    => $route['label'],
        'collected_at'   => $collectedAt,
        'scheduled_day'  => (int)$now->format('N'),
        'scheduled_time' => $schedTime,
        'schedule_mode'  => $schedMode,
        'day_of_week'    => $now->format('l'),
        'week_number'    => (int)$now->format('W'),
        'month'          => (int)$now->format('n'),
        'year'           => (int)$now->format('Y'),
        'origin'         => $route['origin'],
        'destination'    => $route['destination'],
        'travel_mode'    => $route['travel_mode'] ?? 'driving',
        'api_status'     => $status,
        'raw_response'   => $response,
    ]);

    if ($status !== 'OK') {
        clog($logFile, "API status={$status} for {$routeId}");
        $alertMgr->sendErrorAlert($route, "Google Maps API returned status: {$status}");
        return;
    }

    $apiRoutes = $data['routes'] ?? [];
    $savedRoutes = [];

    foreach ($apiRoutes as $idx => $apiRoute) {
        $leg        = $apiRoute['legs'][0] ?? [];
        $routeDbId  = insertRoute($pdo, $collId, $idx, $apiRoute, $leg);
        $savedRoutes[] = [
            'db_id'                     => $routeDbId,
            'route_index'               => $idx,
            'summary'                   => $apiRoute['summary'] ?? '',
            'duration_seconds'          => $leg['duration']['value']            ?? 0,
            'duration_in_traffic_seconds' => $leg['duration_in_traffic']['value'] ?? null,
        ];

        // Save steps
        $steps = $leg['steps'] ?? [];
        foreach ($steps as $si => $step) {
            insertStep($pdo, $routeDbId, $si, $step);
        }
    }

    // Historical average for primary route on this day/time
    $avgDuration = getHistoricalAverage($pdo, $routeId, (int)$now->format('N'), $schedTime);

    // Evaluate alerts
    $primary    = $savedRoutes[0] ?? null;
    $bestAlt    = null;
    $bestAltDur = null;

    if ($primary) {
        $curDur = $primary['duration_in_traffic_seconds'] ?? $primary['duration_seconds'];

        // Find best alternative
        foreach (array_slice($savedRoutes, 1) as $alt) {
            $altDur = $alt['duration_in_traffic_seconds'] ?? $alt['duration_seconds'];
            if ($bestAlt === null || $altDur < $bestAltDur) {
                $bestAlt    = $alt;
                $bestAltDur = $altDur;
            }
        }

        // Build route arrays for AlertManager
        $primaryArr = $apiRoutes[0]['legs'][0] ?? [];
        $primaryArr['summary'] = $apiRoutes[0]['summary'] ?? '';
        $bestAltArr = $bestAlt ? ($apiRoutes[$bestAlt['route_index']]['legs'][0] ?? []) : null;
        if ($bestAltArr && $bestAlt) {
            $bestAltArr['summary'] = $apiRoutes[$bestAlt['route_index']]['summary'] ?? '';
        }

        $alertMgr->evaluateAndAlert(
            $route, $route,
            $curDur, $avgDuration,
            $primaryArr, $bestAltArr, $bestAltDur
        );

        $curMin = round($curDur / 60, 1);
        clog($logFile, "Saved collection {$collId}: primary={$primary['summary']} {$curMin}min" .
             ($avgDuration ? " (avg=" . round($avgDuration/60, 1) . "min)" : " (no avg yet)"));
    }
}

// ─── API call ────────────────────────────────────────────────────────────────

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

// ─── DB inserts ──────────────────────────────────────────────────────────────

function openDb(Config $config): PDO
{
    $pdo = new PDO('sqlite:' . $config->getDbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    return $pdo;
}

function insertCollection(PDO $pdo, array $d): int
{
    $st = $pdo->prepare("
        INSERT INTO collections
            (route_id, route_label, collected_at, scheduled_day, scheduled_time,
             schedule_mode, day_of_week, week_number, month, year,
             origin, destination, travel_mode, api_status, raw_response)
        VALUES
            (:route_id, :route_label, :collected_at, :scheduled_day, :scheduled_time,
             :schedule_mode, :day_of_week, :week_number, :month, :year,
             :origin, :destination, :travel_mode, :api_status, :raw_response)
    ");
    $st->execute($d);
    return (int)$pdo->lastInsertId();
}

function insertRoute(PDO $pdo, int $collId, int $idx, array $apiRoute, array $leg): int
{
    $warnings = implode(' | ', $apiRoute['warnings'] ?? []);
    $st = $pdo->prepare("
        INSERT INTO routes
            (collection_id, route_index, summary,
             distance_meters, distance_text,
             duration_seconds, duration_text,
             duration_in_traffic_seconds, duration_in_traffic_text,
             start_address, end_address, warnings)
        VALUES
            (:collection_id, :route_index, :summary,
             :distance_meters, :distance_text,
             :duration_seconds, :duration_text,
             :duration_in_traffic_seconds, :duration_in_traffic_text,
             :start_address, :end_address, :warnings)
    ");
    $st->execute([
        ':collection_id'               => $collId,
        ':route_index'                 => $idx,
        ':summary'                     => $apiRoute['summary'] ?? '',
        ':distance_meters'             => $leg['distance']['value']               ?? 0,
        ':distance_text'               => $leg['distance']['text']                ?? '',
        ':duration_seconds'            => $leg['duration']['value']               ?? 0,
        ':duration_text'               => $leg['duration']['text']                ?? '',
        ':duration_in_traffic_seconds' => $leg['duration_in_traffic']['value']    ?? null,
        ':duration_in_traffic_text'    => $leg['duration_in_traffic']['text']     ?? null,
        ':start_address'               => $leg['start_address']                   ?? '',
        ':end_address'                 => $leg['end_address']                     ?? '',
        ':warnings'                    => $warnings,
    ]);
    return (int)$pdo->lastInsertId();
}

function insertStep(PDO $pdo, int $routeDbId, int $si, array $step): void
{
    // Strip HTML from instructions
    $instruction = strip_tags($step['html_instructions'] ?? '');

    // Extract road name (first quoted or "onto X" pattern)
    $roadName = '';
    if (preg_match('/(?:onto|via|on)\s+([^,<]+)/i', $instruction, $m)) {
        $roadName = trim($m[1]);
    }

    $st = $pdo->prepare("
        INSERT INTO route_steps
            (route_id, step_index, instruction, distance_meters, duration_seconds,
             travel_mode, road_name, start_lat, start_lng, end_lat, end_lng)
        VALUES
            (:route_id, :step_index, :instruction, :distance_meters, :duration_seconds,
             :travel_mode, :road_name, :start_lat, :start_lng, :end_lat, :end_lng)
    ");
    $st->execute([
        ':route_id'        => $routeDbId,
        ':step_index'      => $si,
        ':instruction'     => $instruction,
        ':distance_meters' => $step['distance']['value']  ?? null,
        ':duration_seconds'=> $step['duration']['value']  ?? null,
        ':travel_mode'     => $step['travel_mode']        ?? 'DRIVING',
        ':road_name'       => $roadName,
        ':start_lat'       => $step['start_location']['lat'] ?? null,
        ':start_lng'       => $step['start_location']['lng'] ?? null,
        ':end_lat'         => $step['end_location']['lat']   ?? null,
        ':end_lng'         => $step['end_location']['lng']   ?? null,
    ]);
}

// ─── Historical average ───────────────────────────────────────────────────────

function getHistoricalAverage(PDO $pdo, string $routeId, int $day, string $schedTime): ?int
{
    $st = $pdo->prepare("
        SELECT AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_dur,
               COUNT(*) AS samples
        FROM routes r
        JOIN collections c ON r.collection_id = c.id
        WHERE c.route_id      = :route_id
          AND c.scheduled_day = :day
          AND c.scheduled_time= :sched_time
          AND r.route_index   = 0
          AND c.api_status    = 'OK'
    ");
    $st->execute([':route_id' => $routeId, ':day' => $day, ':sched_time' => $schedTime]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $minSamples = 5; // hard-coded minimum; could come from config
    if (!$row || (int)$row['samples'] < $minSamples) {
        return null;
    }
    return (int)round($row['avg_dur']);
}

// ─── Force-build a route entry without a real schedule entry ─────────────────

function buildForceEntry(array $route, Config $config): array
{
    // Pick first schedule entry to get a scheduled time
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
        $leg    = $r['legs'][0] ?? [];
        $label  = $i === 0 ? '★ PRIMARY' : "  ALT " . $i;
        $dur    = $leg['duration']['text']              ?? '?';
        $traf   = $leg['duration_in_traffic']['text']   ?? 'n/a';
        $dist   = $leg['distance']['text']              ?? '?';
        $summ   = $r['summary'] ?? '(no summary)';
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

// ─── Print schedule and cron lines ────────────────────────────────────────────

function printSchedule(Config $config): void
{
    $schedule = $config->getFullSchedule();
    $dayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    $before   = $config->getCollectionWindowBefore();
    $after    = $config->getCollectionWindowAfter();
    $phpBin   = PHP_BINARY;
    $script   = realpath(__FILE__);

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

    echo "═══ Suggested Cron Lines ═══\n\n";

    // Build cron for each unique collect_at × days combination
    $cronGroups = [];
    foreach ($schedule as $day => $entries) {
        foreach ($entries as $e) {
            $key = $e['collect_at'] . '|' . $e['label'];
            if (!isset($cronGroups[$key])) {
                $cronGroups[$key] = ['collect_at' => $e['collect_at'], 'label' => $e['label'], 'days' => []];
            }
            $cronGroups[$key]['days'][] = $day;
        }
    }

    foreach ($cronGroups as $g) {
        [$h, $m] = explode(':', $g['collect_at']);
        $h = (int)$h; $m = (int)$m;
        $days = implode(',', array_unique($g['days']));

        // Generate minute marks for the window
        $minutes = [];
        for ($off = -$before; $off <= $after; $off += 5) {
            $mins = (($m + $off) % 60 + 60) % 60;
            $minutes[] = $mins;
        }
        sort($minutes);
        $minutes = array_unique($minutes);

        // Split minutes that wrap across the hour
        $thisHour = array_filter($minutes, fn($x) => $x >= $m - $before || $x <= $m + $after);

        $minuteStr = implode(',', $minutes);

        echo "# {$g['label']}\n";
        echo "{$minuteStr} {$h} * * {$days} {$phpBin} {$script}\n\n";
    }
}

// ─── Logging ─────────────────────────────────────────────────────────────────

function clog(string $logFile, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}
