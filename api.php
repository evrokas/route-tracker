<?php

/**
 * api.php — Route Tracker v3
 * JSON REST API for the dashboard and settings UI.
 *
 * All actions require an active session.
 *
 * GET actions:
 *   ?action=route_list
 *   ?action=overview
 *   ?action=by_day
 *   ?action=by_month
 *   ?action=by_route_name
 *   ?action=by_week
 *   ?action=timeline        &limit=200
 *   ?action=best_routes
 *   ?action=collections     &limit=100
 *   ?action=advisor_status
 *   ?action=get_settings
 *   ?action=test_collection &route_id=xxx
 *   ?action=run_advisor
 *   ?action=get_logs        &type=collector|alerts|advisor
 *   ?action=db_stats
 *   ?action=export_trips
 *   ?action=export_config
 *   ?action=address_history
 *
 * POST actions (JSON body or form data):
 *   ?action=save_setting     { key, value }  or  { settings: {key:value,...} }
 *   ?action=save_route       { id, label, origin, ... }
 *   ?action=delete_route     { id }
 *   ?action=test_alert       { channel }
 *   ?action=change_password  { current, new_password, confirm }
 *   ?action=import_config    { version, settings:{}, routes:[] }
 *
 * Global GET filters (for data queries):
 *   &route_id=xxx
 *   &year=2025
 *   &month=9
 *   &day=4   (ISO 1=Mon..7=Sun)
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';
require_once $baseDir . '/auth.php';

// ─── Headers ──────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

function jsonOut(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $msg, int $code = 400): void
{
    http_response_code($code);
    jsonOut(['error' => $msg]);
}

// ─── Boot ─────────────────────────────────────────────────────────────────────

try {
    $config = Config::load($baseDir);
    date_default_timezone_set($config->getTimezone());
} catch (Exception $e) {
    jsonError('Server configuration error: ' . $e->getMessage(), 500);
}

require_once $baseDir . '/auth.php';
Auth::requireLoginOrJson();

$pdo    = $config->getPdo();
$action = $_GET['action'] ?? 'overview';

// ─── Input helpers ────────────────────────────────────────────────────────────

$routeId = isset($_GET['route_id']) ? trim($_GET['route_id']) : null;
$year    = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month   = isset($_GET['month']) ? (int)$_GET['month'] : null;
$day     = isset($_GET['day'])   ? (int)$_GET['day']   : null;
$limit   = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;

function getPostData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
    }
    return $_POST ?: [];
}

// ─── WHERE clause builder ─────────────────────────────────────────────────────

function buildWhere(array &$params, ?string $routeId, ?int $year, ?int $month, ?int $day, string $prefix = 't'): string
{
    $where = ['1=1'];
    if ($routeId) {
        $where[] = "{$prefix}.route_id = :route_id";
        $params[':route_id'] = $routeId;
    }
    if ($year) {
        $where[] = "{$prefix}.year = :year";
        $params[':year'] = $year;
    }
    if ($month) {
        $where[] = "{$prefix}.month = :month";
        $params[':month'] = $month;
    }
    if ($day) {
        $where[] = "{$prefix}.scheduled_day = :day";
        $params[':day'] = $day;
    }
    $where[] = "{$prefix}.api_status = 'OK'";
    return implode(' AND ', $where);
}

function formatRows(array $rows): array
{
    foreach ($rows as &$row) {
        foreach (['avg_duration','min_duration','max_duration'] as $f) {
            if (isset($row[$f])) $row[$f] = (float)round($row[$f]);
        }
        if (isset($row['avg_distance_meters'])) {
            $row['avg_distance_meters'] = (int)round($row['avg_distance_meters']);
        }
    }
    return $rows;
}

// ═════════════════════════════════════════════════════════════════════════════
// READ actions
// ═════════════════════════════════════════════════════════════════════════════

// ─── route_list ───────────────────────────────────────────────────────────────

if ($action === 'route_list') {
    // ?all=1 → include inactive routes (used by settings page)
    $all = !empty($_GET['all']);
    if ($all) {
        $routes = $config->getAllRoutes();
    } else {
        $routes = $config->getAllActiveRoutes();
    }

    // Return full route data so settings UI can populate edit forms
    $result = array_map(function($r) {
        return [
            'id'                   => $r['id'],
            'label'                => $r['label'],
            'origin'               => $r['origin'] ?? '',
            'destination'          => $r['destination'] ?? '',
            'travel_mode'          => $r['travel_mode'] ?? 'driving',
            'schedule'             => $r['schedule'] ?? [],
            'advisor_enabled'      => (bool)(int)($r['advisor_enabled'] ?? 0),
            'advisor_start_before' => (int)($r['advisor_start_before'] ?? 90),
            'advisor_buffer_mode'  => $r['advisor_buffer_mode'] ?? 'auto',
            'advisor_fixed_buffer' => (int)($r['advisor_fixed_buffer'] ?? 10),
            'advisor_stages'       => $r['advisor_stages'] ?? ['planning','window','reminder','urgent','last_call'],
            'alert_channels'       => $r['alert_channels'] ?? [],
            'active'               => (bool)(int)($r['active'] ?? 1),
        ];
    }, $routes);

    // Fall back to route IDs with trip data if no routes configured yet
    if (empty($result)) {
        try {
            $rows = $pdo->query("SELECT DISTINCT route_id FROM trips WHERE api_status='OK' ORDER BY route_id")
                        ->fetchAll(PDO::FETCH_COLUMN);
            $result = array_map(fn($id) => [
                'id' => $id, 'label' => $id, 'origin' => '', 'destination' => '',
                'travel_mode' => 'driving', 'schedule' => [], 'advisor_enabled' => false,
                'advisor_start_before' => 90, 'advisor_buffer_mode' => 'auto',
                'advisor_fixed_buffer' => 10,
                'advisor_stages' => ['planning','window','reminder','urgent','last_call'],
                'alert_channels' => [], 'active' => true,
            ], $rows);
        } catch (Exception $e) {}
    }

    jsonOut(['routes' => $result, 'generated_at' => date('c')]);
}

// ─── address_history ──────────────────────────────────────────────────────────

if ($action === 'address_history') {
    // Return all distinct origin + destination values ever used in routes, sorted.
    $rows = $pdo->query("
        SELECT DISTINCT origin AS addr FROM routes WHERE origin  != ''
        UNION
        SELECT DISTINCT destination        FROM routes WHERE destination != ''
        ORDER BY addr COLLATE NOCASE
    ")->fetchAll(PDO::FETCH_COLUMN);

    jsonOut(['addresses' => array_values($rows)]);
}

// ─── overview ─────────────────────────────────────────────────────────────────

if ($action === 'overview') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label  AS route_label,
            r.origin,
            r.destination,
            COUNT(*)                             AS total_collections,
            AVG(t.traffic_duration_seconds)      AS avg_duration,
            MIN(t.traffic_duration_seconds)      AS min_duration,
            MAX(t.traffic_duration_seconds)      AS max_duration,
            t.scheduled_time,
            t.schedule_mode,
            MIN(t.collected_at)                  AS first_seen,
            MAX(t.collected_at)                  AS last_seen,
            (SELECT t2.id FROM trips t2
             WHERE t2.route_id = t.route_id AND t2.api_status = 'OK'
             ORDER BY t2.collected_at DESC LIMIT 1)           AS latest_collection_id,
            (SELECT t2.collected_at FROM trips t2
             WHERE t2.route_id = t.route_id AND t2.api_status = 'OK'
             ORDER BY t2.collected_at DESC LIMIT 1)           AS latest_collected_at
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
        GROUP BY t.route_id, t.scheduled_time, t.schedule_mode
        ORDER BY t.route_id, t.scheduled_time
    ");
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$row) {
        $dbRoute          = $config->getRoute($row['route_id']);
        $row['schedule']  = $dbRoute['schedule'] ?? [];
        $row['avg_duration'] = (float)round($row['avg_duration']);
        $row['min_duration'] = (int)$row['min_duration'];
        $row['max_duration'] = (int)$row['max_duration'];
    }
    unset($row);

    jsonOut(['overview' => $data, 'generated_at' => date('c')]);
}

// ─── by_day ───────────────────────────────────────────────────────────────────

if ($action === 'by_day') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label       AS route_label,
            t.scheduled_day,
            t.day_of_week,
            t.scheduled_time,
            t.schedule_mode,
            t.primary_summary AS route_name,
            COUNT(*)      AS sample_count,
            AVG(t.traffic_duration_seconds) AS avg_duration,
            MIN(t.traffic_duration_seconds) AS min_duration,
            MAX(t.traffic_duration_seconds) AS max_duration,
            AVG(t.distance_meters)          AS avg_distance
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
          AND t.primary_summary IS NOT NULL
        GROUP BY t.route_id, t.scheduled_day, t.primary_summary
        ORDER BY t.route_id, t.scheduled_day, avg_duration ASC
    ");
    $st->execute($params);
    jsonOut(['by_day' => formatRows($st->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── by_month ─────────────────────────────────────────────────────────────────

if ($action === 'by_month') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label AS route_label,
            t.year, t.month,
            COUNT(*) AS sample_count,
            AVG(t.traffic_duration_seconds) AS avg_duration,
            MIN(t.traffic_duration_seconds) AS min_duration,
            MAX(t.traffic_duration_seconds) AS max_duration
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
        GROUP BY t.route_id, t.year, t.month
        ORDER BY t.route_id, t.year, t.month
    ");
    $st->execute($params);
    jsonOut(['by_month' => formatRows($st->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── by_route_name ────────────────────────────────────────────────────────────

if ($action === 'by_route_name') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label        AS route_label,
            t.primary_summary AS route_name,
            COUNT(*)       AS sample_count,
            AVG(t.traffic_duration_seconds) AS avg_duration,
            MIN(t.traffic_duration_seconds) AS min_duration,
            MAX(t.traffic_duration_seconds) AS max_duration,
            AVG(t.distance_meters)          AS avg_distance_meters
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
          AND t.primary_summary IS NOT NULL AND t.primary_summary != ''
        GROUP BY t.route_id, t.primary_summary
        ORDER BY t.route_id, avg_duration ASC
    ");
    $st->execute($params);
    jsonOut(['by_route_name' => formatRows($st->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── by_week ──────────────────────────────────────────────────────────────────

if ($action === 'by_week') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label AS route_label,
            t.year, t.week_number,
            COUNT(*) AS sample_count,
            AVG(t.traffic_duration_seconds) AS avg_duration,
            MIN(t.traffic_duration_seconds) AS min_duration,
            MAX(t.traffic_duration_seconds) AS max_duration
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
        GROUP BY t.route_id, t.year, t.week_number
        ORDER BY t.route_id, t.year, t.week_number
    ");
    $st->execute($params);
    jsonOut(['by_week' => formatRows($st->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── timeline ─────────────────────────────────────────────────────────────────

if ($action === 'timeline') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label  AS route_label,
            t.collected_at,
            t.day_of_week,
            t.scheduled_time,
            t.schedule_mode,
            t.primary_summary  AS route_name,
            0                  AS route_index,
            t.traffic_duration_seconds AS duration,
            t.distance_meters
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
        ORDER BY t.collected_at ASC
        LIMIT :lim
    ");
    $params[':lim'] = $limit;
    $st->execute($params);
    jsonOut(['timeline' => formatRows($st->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── best_routes ──────────────────────────────────────────────────────────────

if ($action === 'best_routes') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.route_id,
            r.label   AS route_label,
            t.scheduled_day,
            t.day_of_week,
            t.scheduled_time,
            t.schedule_mode,
            t.primary_summary AS route_name,
            COUNT(*)  AS sample_count,
            AVG(t.traffic_duration_seconds) AS avg_duration,
            MIN(t.traffic_duration_seconds) AS min_duration,
            MAX(t.traffic_duration_seconds) AS max_duration
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
          AND t.primary_summary IS NOT NULL AND t.primary_summary != ''
        GROUP BY t.route_id, t.scheduled_day, t.primary_summary
        ORDER BY t.route_id, t.scheduled_day, avg_duration ASC
    ");
    $st->execute($params);
    $all = formatRows($st->fetchAll(PDO::FETCH_ASSOC));

    $grouped = [];
    foreach ($all as $row) {
        $key = $row['route_id'] . '|' . $row['scheduled_day'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'route_id'       => $row['route_id'],
                'route_label'    => $row['route_label'],
                'scheduled_day'  => $row['scheduled_day'],
                'day_of_week'    => $row['day_of_week'],
                'scheduled_time' => $row['scheduled_time'],
                'schedule_mode'  => $row['schedule_mode'],
                'best_route'     => $row,
                'alternatives'   => [],
            ];
        } else {
            $grouped[$key]['alternatives'][] = $row;
        }
    }

    jsonOut(['best_routes' => array_values($grouped), 'generated_at' => date('c')]);
}

// ─── collections (trips list for History tab) ─────────────────────────────────

if ($action === 'collections') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $st = $pdo->prepare("
        SELECT
            t.id,
            t.route_id,
            r.label    AS route_label,
            r.origin,
            r.destination,
            t.collected_at,
            t.scheduled_day,
            t.day_of_week,
            t.scheduled_time,
            t.schedule_mode,
            t.api_status,
            t.primary_summary,
            t.traffic_duration_seconds,
            t.best_alt_summary,
            t.best_alt_seconds,
            CASE WHEN t.primary_summary IS NOT NULL
                 THEN t.primary_summary || ' (' || ROUND(t.traffic_duration_seconds/60.0,1) || ' min)'
                 ELSE NULL
            END AS routes_summary
        FROM trips t
        LEFT JOIN routes r ON r.id = t.route_id
        WHERE {$where}
        ORDER BY t.collected_at DESC
        LIMIT :lim
    ");
    $params[':lim'] = $limit;
    $st->execute($params);
    jsonOut(['collections' => $st->fetchAll(PDO::FETCH_ASSOC), 'generated_at' => date('c')]);
}

// ─── advisor_status ───────────────────────────────────────────────────────────

if ($action === 'advisor_status') {
    require_once $baseDir . '/AlertManager.php';
    require_once $baseDir . '/DepartureAdvisor.php';

    $alertMgr = new AlertManager($config);
    $advisor  = new DepartureAdvisor($config, $alertMgr);
    $status   = $advisor->getStatus();
    if ($routeId) {
        $status = array_values(array_filter($status, fn($r) => $r['route_id'] === $routeId));
    }
    jsonOut(['advisor' => $status, 'generated_at' => date('c')]);
}

// ─── get_settings ────────────────────────────────────────────────────────────

if ($action === 'get_settings') {
    // Return all settings except password hash
    $st   = $pdo->query("SELECT key, value FROM settings ORDER BY key");
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    unset($rows['dashboard_password_hash']);
    jsonOut(['settings' => $rows, 'generated_at' => date('c')]);
}

// ─── get_logs ────────────────────────────────────────────────────────────────

if ($action === 'get_logs') {
    $type   = $_GET['type'] ?? 'collector';
    $map    = [
        'collector' => $baseDir . '/data/collector.log',
        'alerts'    => $baseDir . '/data/alerts.log',
        'advisor'   => $baseDir . '/data/advisor.log',
    ];

    if (!isset($map[$type])) {
        jsonError('Unknown log type', 400);
    }

    $path  = $map[$type];
    $lines = [];

    if (file_exists($path)) {
        $all   = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($all, -50); // last 50 lines
    }

    jsonOut(['log' => $lines, 'type' => $type, 'generated_at' => date('c')]);
}

// ─── db_stats ─────────────────────────────────────────────────────────────────

if ($action === 'db_stats') {
    $tripCount  = $pdo->query("SELECT COUNT(*) FROM trips")->fetchColumn();
    $routeCount = $pdo->query("SELECT COUNT(*) FROM routes WHERE active=1")->fetchColumn();
    $firstTrip  = $pdo->query("SELECT MIN(collected_at) FROM trips")->fetchColumn();
    $lastTrip   = $pdo->query("SELECT MAX(collected_at) FROM trips")->fetchColumn();
    $dbSize     = file_exists($config->getDbPath()) ? filesize($config->getDbPath()) : 0;

    jsonOut([
        'trip_count'   => (int)$tripCount,
        'route_count'  => (int)$routeCount,
        'first_trip'   => $firstTrip ?: null,
        'last_trip'    => $lastTrip  ?: null,
        'db_size_bytes' => (int)$dbSize,
        'generated_at' => date('c'),
    ]);
}

// ─── export_trips ────────────────────────────────────────────────────────────

if ($action === 'export_trips') {
    $rows = $pdo->query("
        SELECT t.*, r.label AS route_label, r.origin, r.destination
        FROM trips t LEFT JOIN routes r ON r.id = t.route_id
        ORDER BY t.collected_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trips_export_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

// ─── export_config ────────────────────────────────────────────────────────────

if ($action === 'export_config') {
    // All settings as flat key→value map
    $settings = $pdo->query("SELECT key, value FROM settings ORDER BY key")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

    // All routes with JSON fields re-encoded for portability
    $routes = array_map(function ($r) {
        return [
            'id'                   => $r['id'],
            'label'                => $r['label'],
            'origin'               => $r['origin'],
            'destination'          => $r['destination'],
            'travel_mode'          => $r['travel_mode'],
            'schedule'             => $r['schedule'],        // already decoded array
            'advisor_enabled'      => (int)$r['advisor_enabled'],
            'advisor_start_before' => (int)$r['advisor_start_before'],
            'advisor_buffer_mode'  => $r['advisor_buffer_mode'],
            'advisor_fixed_buffer' => (int)$r['advisor_fixed_buffer'],
            'advisor_stages'       => $r['advisor_stages'],  // already decoded array
            'alert_channels'       => $r['alert_channels'],  // already decoded array
            'active'               => (int)$r['active'],
            'created_at'           => $r['created_at'],
        ];
    }, $config->getAllRoutes());

    $backup = [
        'version'     => 3,
        'app'         => 'Route Tracker',
        'exported_at' => date('c'),
        'settings'    => $settings,
        'routes'      => $routes,
    ];

    $filename = 'tracker_config_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── import_config ────────────────────────────────────────────────────────────

if ($action === 'import_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body) || ($body['version'] ?? 0) !== 3) {
        jsonError('Invalid backup file: expected Route Tracker v3 format (version: 3)');
    }

    $settingsUpdated = 0;
    $routesImported  = 0;

    $pdo->beginTransaction();
    try {
        // Restore settings
        if (!empty($body['settings']) && is_array($body['settings'])) {
            $st = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
            foreach ($body['settings'] as $key => $value) {
                $st->execute([':key' => (string)$key, ':value' => (string)$value]);
                $settingsUpdated++;
            }
        }

        // Restore routes
        if (!empty($body['routes']) && is_array($body['routes'])) {
            $st = $pdo->prepare("
                INSERT OR REPLACE INTO routes
                    (id, label, origin, destination, travel_mode, schedule,
                     advisor_enabled, advisor_start_before, advisor_buffer_mode,
                     advisor_fixed_buffer, advisor_stages, alert_channels, active,
                     created_at, updated_at)
                VALUES
                    (:id, :label, :origin, :destination, :travel_mode, :schedule,
                     :advisor_enabled, :advisor_start_before, :advisor_buffer_mode,
                     :advisor_fixed_buffer, :advisor_stages, :alert_channels, :active,
                     :created_at, :updated_at)
            ");
            foreach ($body['routes'] as $r) {
                if (empty($r['id']) || empty($r['label'])) continue;
                // Normalise JSON fields: accept either arrays (from export) or raw JSON strings
                $encodeIfArray = fn($v, $default) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? $default);
                $st->execute([
                    ':id'                   => $r['id'],
                    ':label'                => $r['label'],
                    ':origin'               => $r['origin']               ?? '',
                    ':destination'          => $r['destination']          ?? '',
                    ':travel_mode'          => $r['travel_mode']          ?? 'driving',
                    ':schedule'             => $encodeIfArray($r['schedule']        ?? null, '[]'),
                    ':advisor_enabled'      => (int)($r['advisor_enabled']      ?? 0),
                    ':advisor_start_before' => (int)($r['advisor_start_before']  ?? 90),
                    ':advisor_buffer_mode'  => $r['advisor_buffer_mode']  ?? 'auto',
                    ':advisor_fixed_buffer' => (int)($r['advisor_fixed_buffer']  ?? 10),
                    ':advisor_stages'       => $encodeIfArray($r['advisor_stages']  ?? null, '["planning","window","reminder","urgent","last_call"]'),
                    ':alert_channels'       => $encodeIfArray($r['alert_channels'] ?? null, '[]'),
                    ':active'               => (int)($r['active'] ?? 1),
                    ':created_at'           => $r['created_at'] ?? date('c'),
                    ':updated_at'           => date('c'),
                ]);
                $routesImported++;
            }
        }

        $pdo->commit();
        Config::reset();

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Import failed: ' . $e->getMessage());
    }

    jsonOut([
        'ok'               => true,
        'settings_updated' => $settingsUpdated,
        'routes_imported'  => $routesImported,
    ]);
}

// ─── test_collection ──────────────────────────────────────────────────────────

if ($action === 'test_collection') {
    $rid   = $routeId ?? ($_GET['route_id'] ?? null);
    if (!$rid) {
        jsonError('route_id is required', 400);
    }

    $route = $config->getRoute($rid);
    if (!$route) {
        jsonError("Route not found: {$rid}", 404);
    }

    require_once $baseDir . '/collector.php';

    $params = [
        'origin'         => $route['origin'],
        'destination'    => $route['destination'],
        'mode'           => $route['travel_mode'] ?? 'driving',
        'departure_time' => 'now',
        'language'       => $config->getSetting('google_maps_language', 'el'),
        'region'         => $config->getSetting('google_maps_region', 'gr'),
        'key'            => $config->getApiKey(),
        'alternatives'   => 'true',
    ];

    $url      = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);
    $response = callApi($url);

    if ($response === null) {
        jsonError('cURL error calling Google Maps API', 500);
    }

    $data   = json_decode($response, true);
    $status = $data['status'] ?? 'UNKNOWN';

    $routes = [];
    foreach ($data['routes'] ?? [] as $i => $r) {
        $leg     = $r['legs'][0] ?? [];
        $routes[] = [
            'index'    => $i,
            'summary'  => $r['summary'] ?? '',
            'duration' => $leg['duration']['text'] ?? '?',
            'traffic'  => $leg['duration_in_traffic']['text'] ?? null,
            'distance' => $leg['distance']['text'] ?? '?',
        ];
    }

    jsonOut([
        'status'    => $status,
        'route_id'  => $rid,
        'label'     => $route['label'],
        'routes'    => $routes,
        'error'     => $data['error_message'] ?? null,
        'tested_at' => date('c'),
    ]);
}

// ─── run_advisor ─────────────────────────────────────────────────────────────

if ($action === 'run_advisor') {
    require_once $baseDir . '/AlertManager.php';
    require_once $baseDir . '/collector.php';
    require_once $baseDir . '/DepartureAdvisor.php';

    $alertMgr = new AlertManager($config);
    $advisor  = new DepartureAdvisor($config, $alertMgr);
    $logFile  = $baseDir . '/data/advisor.log';
    $collLog  = $baseDir . '/data/collector.log';

    $processed = [];

    foreach ($config->getAllActiveRoutes() as $route) {
        if (!empty($route['advisor_enabled'])) {
            foreach ($route['schedule'] ?? [] as $sched) {
                if (isset($sched['arrive'])) {
                    $advisor->run($route, $sched);
                    $processed[] = $route['id'];
                }
            }
        }
    }

    jsonOut(['ran_for' => array_unique($processed), 'run_at' => date('c')]);
}

// ═════════════════════════════════════════════════════════════════════════════
// WRITE actions (POST)
// ═════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ─── save_setting ─────────────────────────────────────────────────────────

    if ($action === 'save_setting') {
        $body = getPostData();

        if (isset($body['settings']) && is_array($body['settings'])) {
            $pairs = $body['settings'];
        } elseif (isset($body['key'])) {
            $pairs = [$body['key'] => $body['value'] ?? ''];
        } else {
            jsonError('Expected {key, value} or {settings: {}}', 400);
        }

        // Never allow overwriting password hash via this endpoint
        unset($pairs['dashboard_password_hash']);

        $config->setSettings($pairs);
        jsonOut(['ok' => true, 'saved' => array_keys($pairs)]);
    }

    // ─── change_password ──────────────────────────────────────────────────────

    if ($action === 'change_password') {
        $body    = getPostData();
        $current = $body['current']      ?? '';
        $newPw   = $body['new_password'] ?? '';
        $confirm = $body['confirm']      ?? '';

        if (!password_verify($current, $config->getDashboardPasswordHash())) {
            jsonError('Current password is incorrect', 403);
        }
        if (strlen($newPw) < 6) {
            jsonError('New password must be at least 6 characters', 400);
        }
        if ($newPw !== $confirm) {
            jsonError('Passwords do not match', 400);
        }

        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $config->setSetting('dashboard_password_hash', $hash);
        jsonOut(['ok' => true]);
    }

    // ─── save_route ───────────────────────────────────────────────────────────

    if ($action === 'save_route') {
        $body = getPostData();

        $id = trim($body['id'] ?? '');
        if (!$id || !preg_match('/^[a-z0-9_\-]+$/', $id)) {
            jsonError('Route ID must be lowercase alphanumeric/underscore/dash', 400);
        }

        $label       = trim($body['label']       ?? '');
        $origin      = trim($body['origin']       ?? '');
        $destination = trim($body['destination']  ?? '');

        if (!$label || !$origin || !$destination) {
            jsonError('label, origin, and destination are required', 400);
        }

        $schedule       = $body['schedule']        ?? [];
        $alertChannels  = $body['alert_channels']  ?? [];
        $advisorStages  = $body['advisor_stages']  ?? ['planning','window','reminder','urgent','last_call'];

        $now = date('Y-m-d H:i:s');

        // Check if exists
        $existing = $pdo->prepare("SELECT id FROM routes WHERE id = :id");
        $existing->execute([':id' => $id]);
        $isUpdate = (bool)$existing->fetchColumn();

        if ($isUpdate) {
            $st = $pdo->prepare("
                UPDATE routes SET
                    label = :label,
                    origin = :origin,
                    destination = :destination,
                    travel_mode = :travel_mode,
                    schedule = :schedule,
                    advisor_enabled = :advisor_enabled,
                    advisor_start_before = :advisor_start_before,
                    advisor_buffer_mode = :advisor_buffer_mode,
                    advisor_fixed_buffer = :advisor_fixed_buffer,
                    advisor_stages = :advisor_stages,
                    alert_channels = :alert_channels,
                    active = :active,
                    updated_at = :updated_at
                WHERE id = :id
            ");
        } else {
            $st = $pdo->prepare("
                INSERT INTO routes
                    (id, label, origin, destination, travel_mode, schedule,
                     advisor_enabled, advisor_start_before, advisor_buffer_mode,
                     advisor_fixed_buffer, advisor_stages, alert_channels, active,
                     created_at, updated_at)
                VALUES
                    (:id, :label, :origin, :destination, :travel_mode, :schedule,
                     :advisor_enabled, :advisor_start_before, :advisor_buffer_mode,
                     :advisor_fixed_buffer, :advisor_stages, :alert_channels, :active,
                     :created_at, :updated_at)
            ");
        }

        $params = [
            ':id'                   => $id,
            ':label'                => $label,
            ':origin'               => $origin,
            ':destination'          => $destination,
            ':travel_mode'          => $body['travel_mode']          ?? 'driving',
            ':schedule'             => json_encode(is_array($schedule) ? $schedule : []),
            ':advisor_enabled'      => (int)(bool)($body['advisor_enabled'] ?? 0),
            ':advisor_start_before' => (int)($body['advisor_start_before'] ?? 90),
            ':advisor_buffer_mode'  => $body['advisor_buffer_mode']   ?? 'auto',
            ':advisor_fixed_buffer' => (int)($body['advisor_fixed_buffer'] ?? 10),
            ':advisor_stages'       => json_encode(is_array($advisorStages) ? $advisorStages : []),
            ':alert_channels'       => json_encode(is_array($alertChannels) ? $alertChannels : []),
            ':active'               => (int)(bool)($body['active'] ?? 1),
            ':updated_at'           => $now,
        ];

        if (!$isUpdate) {
            $params[':created_at'] = $now;
        }

        $st->execute($params);

        // Invalidate Config route cache
        Config::reset();
        $config = Config::load($baseDir);

        jsonOut(['ok' => true, 'id' => $id, 'created' => !$isUpdate]);
    }

    // ─── delete_route ─────────────────────────────────────────────────────────

    if ($action === 'delete_route') {
        $body = getPostData();
        $id   = trim($body['id'] ?? '');

        if (!$id) {
            jsonError('Route ID is required', 400);
        }

        $st = $pdo->prepare("DELETE FROM routes WHERE id = :id");
        $st->execute([':id' => $id]);
        $deleted = $st->rowCount();

        Config::reset();
        $config = Config::load($baseDir);

        jsonOut(['ok' => $deleted > 0, 'deleted' => $deleted > 0]);
    }

    // ─── test_alert ───────────────────────────────────────────────────────────

    if ($action === 'test_alert') {
        $body    = getPostData();
        $channel = trim($body['channel'] ?? '');

        if (!$channel) {
            jsonError('channel is required', 400);
        }

        require_once $baseDir . '/AlertManager.php';
        $alertMgr = new AlertManager($config);
        $result   = $alertMgr->sendTestChannel($channel);

        jsonOut($result);
    }
}

// ─── Unknown action ───────────────────────────────────────────────────────────

jsonError("Unknown action: {$action}");
