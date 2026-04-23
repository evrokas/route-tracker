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
 *   ?action=timeline           &limit=200
 *   ?action=best_routes
 *   ?action=collections        &limit=100
 *   ?action=advisor_status
 *   ?action=get_settings
 *   ?action=test_collection    &route_id=xxx
 *   ?action=run_advisor
 *   ?action=get_logs           &type=collector|alerts|advisor
 *   ?action=db_stats
 *   ?action=export_trips
 *   ?action=export_config
 *   ?action=address_history
 *   ?action=channel_profiles_list
 *   ?action=alert_profiles_list
 *
 * POST actions (JSON body or form data):
 *   ?action=save_setting          { key, value }  or  { settings: {key:value,...} }
 *   ?action=save_route            { id, label, origin, ... }
 *   ?action=create_quick_trip     { destination, arrive, origin?, label?, alert_profile_ids? }
 *   ?action=cleanup_quick_trips   (no body — deletes all expired/inactive one-time routes)
 *   ?action=delete_route          { id }
 *   ?action=change_password       { current, new_password, confirm }
 *   ?action=import_config         { version, settings:{}, routes:[], channel_profiles:{}, alert_profiles:[] }
 *   ?action=channel_profiles_save { type, id, label, ... }
 *   ?action=channel_profiles_delete { type, id }
 *   ?action=channel_profiles_test { type, id }
 *   ?action=alert_profiles_save   { id, label, channels:[{type,profile_id},...], enabled }
 *   ?action=alert_profiles_delete { id }
 *   ?action=alert_profiles_test   { id }
 *
 * Global GET filters (for data queries):
 *   &route_id=xxx
 *   &year=2025
 *   &month=9
 *   &day=4   (ISO 1=Mon..7=Sun)
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/src/Config.php';
require_once $baseDir . '/src/auth.php';

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
    error_log('Route Tracker api.php: ' . $e->getMessage());
    jsonError('Server configuration error.', 500);
}

$action = $_GET['action'] ?? 'overview';

// ─── ErnsAuth proxy actions (no auth required — used during login) ───────────

$ernsauthUrl = Config::deploy('ernsauth_url', '');
$ernsauthActions = ['ernsauth_create_challenge', 'ernsauth_poll_challenge',
                    'ernsauth_send_otp', 'ernsauth_verify_otp',
                    'ernsauth_request_reset', 'ernsauth_verify_reset'];

if ($ernsauthUrl && in_array($action, $ernsauthActions, true)) {
    require_once $baseDir . '/lib/auth/ErnsAuthClient.php';
    require_once $baseDir . '/src/auth.php';
    Auth::startSession();

    $client = new ErnsAuthClient($ernsauthUrl, Config::deploy('ernsauth_api_key', ''));
    $body = file_get_contents('php://input');
    $input = $body ? (json_decode($body, true) ?: []) : $_POST;

    try {
        switch ($action) {
            case 'ernsauth_create_challenge':
                $result = $client->createChallenge(
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                );
                jsonOut($result);

            case 'ernsauth_poll_challenge':
                $challengeId = $_GET['challenge_id'] ?? ($input['challenge_id'] ?? '');
                $result = $client->pollChallenge($challengeId);
                // If approved, exchange code and create local session
                if (($result['status'] ?? '') === 'approved' && !empty($result['auth_code'])) {
                    $user = $client->exchangeCode($result['auth_code']);
                    session_regenerate_id(true);
                    $_SESSION['rt_authed'] = true;
                    $result['authenticated'] = true;
                    unset($result['auth_code']);
                }
                jsonOut($result);

            case 'ernsauth_send_otp':
                $email = trim($input['email'] ?? '');
                $result = $client->sendOtp($email);
                jsonOut($result);

            case 'ernsauth_verify_otp':
                $otpId = $input['otp_id'] ?? '';
                $code = $input['code'] ?? '';
                $user = $client->verifyOtp($otpId, $code);
                session_regenerate_id(true);
                $_SESSION['rt_authed'] = true;
                jsonOut(['success' => true, 'user' => $user]);

            case 'ernsauth_request_reset':
                $email = trim($input['email'] ?? '');
                $result = $client->requestPasswordReset($email);
                jsonOut($result);

            case 'ernsauth_verify_reset':
                $result = $client->verifyPasswordReset(
                    $input['email'] ?? '',
                    $input['code'] ?? '',
                    $input['new_password'] ?? ''
                );
                jsonOut($result);
        }
    } catch (RuntimeException $e) {
        jsonError($e->getMessage());
    }
}

// ─── Auth-required actions ───────────────────────────────────────────────────

require_once $baseDir . '/src/auth.php';
Auth::requireLoginOrJson();

$pdo = $config->getPdo();

// ─── Input helpers ────────────────────────────────────────────────────────────

$routeId = isset($_GET['route_id']) ? trim($_GET['route_id']) : null;
$year    = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month   = isset($_GET['month']) ? (int)$_GET['month'] : null;
$day     = isset($_GET['day'])   ? (int)$_GET['day']   : null;
$limit   = isset($_GET['limit']) ? min((int)$_GET['limit'], Config::deploy('api_max_limit', 500)) : Config::deploy('api_default_limit', 100);

function getPostData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
    }
    return $_POST ?: [];
}

function verifyCsrf(): void
{
    $token    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token missing or invalid']);
        exit;
    }
}

function validateEnum(mixed $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? (string)$value : $default;
}

function validateSchedule(mixed $raw): array
{
    if (!is_array($raw)) {
        jsonError('schedule must be an array', 400);
    }

    $validDays   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun','Weekdays','Weekends','All'];
    $timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $cleaned     = [];

    foreach ($raw as $i => $entry) {
        if (!is_array($entry)) {
            jsonError("schedule[{$i}]: each entry must be an object", 400);
        }

        $days = $entry['days'] ?? null;
        if (is_string($days)) {
            $days = [$days];
        }
        if (!is_array($days) || empty($days)) {
            jsonError("schedule[{$i}]: 'days' must be a non-empty string or array", 400);
        }
        foreach ($days as $d) {
            if (!in_array($d, $validDays, true)) {
                jsonError("schedule[{$i}]: unknown day value '{$d}'", 400);
            }
        }

        $hasArrive = isset($entry['arrive']) && preg_match($timePattern, $entry['arrive']);
        $hasDepart = isset($entry['depart']) && preg_match($timePattern, $entry['depart']);

        if (!$hasArrive && !$hasDepart) {
            jsonError("schedule[{$i}]: must have 'arrive' or 'depart' in HH:MM format", 400);
        }
        if ($hasArrive && $hasDepart) {
            jsonError("schedule[{$i}]: cannot have both 'arrive' and 'depart'", 400);
        }

        $normalized = ['days' => $entry['days']];
        if ($hasArrive) $normalized['arrive'] = $entry['arrive'];
        if ($hasDepart) $normalized['depart'] = $entry['depart'];
        $cleaned[] = $normalized;
    }

    return $cleaned;
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
            'alert_profile_ids'    => $r['alert_profile_ids'] ?? [],
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
                'alert_profile_ids' => [], 'active' => true,
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
    require_once $baseDir . '/src/AlertManager.php';
    require_once $baseDir . '/src/DepartureAdvisor.php';

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
    $dataDir = $baseDir . '/' . Config::deploy('data_dir');
    $map    = [
        'collector' => $dataDir . '/collector.log',
        'alerts'    => $dataDir . '/alerts.log',
        'advisor'   => $dataDir . '/advisor.log',
    ];

    if (!isset($map[$type])) {
        jsonError('Unknown log type', 400);
    }

    $path  = $map[$type];
    $lines = [];

    if (file_exists($path)) {
        $all   = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($all, -Config::deploy('log_tail_lines', 50));
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
    $includeCredentials = !empty($_GET['credentials']);

    // All settings as flat key→value map
    $settings = $pdo->query("SELECT key, value FROM settings ORDER BY key")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

    // Redact sensitive settings unless explicitly requested
    if (!$includeCredentials) {
        foreach (['google_maps_api_key', 'dashboard_password_hash'] as $k) {
            if (array_key_exists($k, $settings)) {
                $settings[$k] = '***REDACTED***';
            }
        }
    }

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
            'advisor_stages'       => $r['advisor_stages'],     // already decoded array
            'alert_profile_ids'    => $r['alert_profile_ids'],  // already decoded array
            'active'               => (int)$r['active'],
            'created_at'           => $r['created_at'],
        ];
    }, $config->getAllRoutes());

    // Channel profiles — redact credentials by default
    $channelProfiles = $config->getAllChannelProfiles();
    if (!$includeCredentials) {
        $sensitiveByType = [
            'telegram' => ['bot_token'],
            'email'    => ['smtp_pass'],
            'viber'    => ['auth_token'],
        ];
        foreach ($sensitiveByType as $type => $fields) {
            foreach ($channelProfiles[$type] ?? [] as &$profile) {
                foreach ($fields as $field) {
                    if (array_key_exists($field, $profile)) {
                        $profile[$field] = '***REDACTED***';
                    }
                }
            }
            unset($profile);
        }
    }

    $backup = [
        'version'          => 3,
        'app'              => 'Route Tracker',
        'exported_at'      => date('c'),
        '_warning'         => $includeCredentials
            ? 'WARNING: This file contains plaintext credentials. Store it securely and delete after use.'
            : 'Credentials have been redacted. To export with plaintext credentials, use ?credentials=1.',
        'settings'         => $settings,
        'routes'           => $routes,
        'channel_profiles' => $channelProfiles,
        'alert_profiles'   => $config->getAllAlertProfiles(),
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
    verifyCsrf();
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON: ' . json_last_error_msg());
    }
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
                     advisor_fixed_buffer, advisor_stages, alert_profile_ids, active,
                     created_at, updated_at)
                VALUES
                    (:id, :label, :origin, :destination, :travel_mode, :schedule,
                     :advisor_enabled, :advisor_start_before, :advisor_buffer_mode,
                     :advisor_fixed_buffer, :advisor_stages, :alert_profile_ids, :active,
                     :created_at, :updated_at)
            ");
            foreach ($body['routes'] as $r) {
                if (empty($r['id']) || empty($r['label'])) continue;
                $encodeIfArray = fn($v, $default) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? $default);
                $profileIds = $r['alert_profile_ids'] ?? $r['alert_channels'] ?? null;
                $st->execute([
                    ':id'                   => $r['id'],
                    ':label'                => $r['label'],
                    ':origin'               => $r['origin']              ?? '',
                    ':destination'          => $r['destination']         ?? '',
                    ':travel_mode'          => validateEnum($r['travel_mode'] ?? 'driving', ['driving','walking','bicycling','transit'], 'driving'),
                    ':schedule'             => $encodeIfArray($r['schedule']      ?? null, '[]'),
                    ':advisor_enabled'      => (int)($r['advisor_enabled']        ?? 0),
                    ':advisor_start_before' => (int)($r['advisor_start_before']   ?? 90),
                    ':advisor_buffer_mode'  => validateEnum($r['advisor_buffer_mode'] ?? 'auto', ['auto','fixed'], 'auto'),
                    ':advisor_fixed_buffer' => (int)($r['advisor_fixed_buffer']   ?? 10),
                    ':advisor_stages'       => $encodeIfArray($r['advisor_stages'] ?? null, '["planning","window","reminder","urgent","last_call"]'),
                    ':alert_profile_ids'    => $encodeIfArray($profileIds,          '[]'),
                    ':active'               => (int)($r['active'] ?? 1),
                    ':created_at'           => $r['created_at'] ?? date('c'),
                    ':updated_at'           => date('c'),
                ]);
                $routesImported++;
            }
        }

        // Restore channel profiles
        $profilesImported = 0;
        if (!empty($body['channel_profiles']) && is_array($body['channel_profiles'])) {
            foreach ($body['channel_profiles'] as $type => $profiles) {
                foreach ((array)$profiles as $p) {
                    if (empty($p['id'])) continue;
                    try { $config->saveChannelProfile($type, $p); $profilesImported++; }
                    catch (Exception $e) { /* skip unknown types */ }
                }
            }
        }

        // Restore alert profiles
        $alertProfilesImported = 0;
        if (!empty($body['alert_profiles']) && is_array($body['alert_profiles'])) {
            foreach ($body['alert_profiles'] as $p) {
                if (empty($p['id'])) continue;
                $config->saveAlertProfile($p);
                $alertProfilesImported++;
            }
        }

        $pdo->commit();
        Config::reset();

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Import failed: ' . $e->getMessage());
    }

    jsonOut([
        'ok'                      => true,
        'settings_updated'        => $settingsUpdated,
        'routes_imported'         => $routesImported,
        'profiles_imported'       => $profilesImported,
        'alert_profiles_imported' => $alertProfilesImported,
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

    require_once $baseDir . '/src/collector.php';

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
    require_once $baseDir . '/src/AlertManager.php';
    require_once $baseDir . '/src/collector.php';
    require_once $baseDir . '/src/DepartureAdvisor.php';

    $alertMgr = new AlertManager($config);
    $advisor  = new DepartureAdvisor($config, $alertMgr);
    $dataDir  = $baseDir . '/' . Config::deploy('data_dir');
    $logFile  = $dataDir . '/advisor.log';
    $collLog  = $dataDir . '/collector.log';

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
    verifyCsrf();

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
        if (strlen($newPw) < 12) {
            jsonError('New password must be at least 12 characters', 400);
        }
        if ($newPw !== $confirm) {
            jsonError('Passwords do not match', 400);
        }

        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $config->setSetting('dashboard_password_hash', $hash);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

        $schedule        = validateSchedule($body['schedule'] ?? []);
        $alertProfileIds = $body['alert_profile_ids'] ?? [];
        $advisorStages   = $body['advisor_stages']    ?? ['planning','window','reminder','urgent','last_call'];

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
                    alert_profile_ids = :alert_profile_ids,
                    active = :active,
                    updated_at = :updated_at
                WHERE id = :id
            ");
        } else {
            $st = $pdo->prepare("
                INSERT INTO routes
                    (id, label, origin, destination, travel_mode, schedule,
                     advisor_enabled, advisor_start_before, advisor_buffer_mode,
                     advisor_fixed_buffer, advisor_stages, alert_profile_ids, active,
                     created_at, updated_at)
                VALUES
                    (:id, :label, :origin, :destination, :travel_mode, :schedule,
                     :advisor_enabled, :advisor_start_before, :advisor_buffer_mode,
                     :advisor_fixed_buffer, :advisor_stages, :alert_profile_ids, :active,
                     :created_at, :updated_at)
            ");
        }

        $params = [
            ':id'                   => $id,
            ':label'                => $label,
            ':origin'               => $origin,
            ':destination'          => $destination,
            ':travel_mode'          => validateEnum($body['travel_mode'] ?? 'driving', ['driving','walking','bicycling','transit'], 'driving'),
            ':schedule'             => json_encode($schedule),
            ':advisor_enabled'      => (int)(bool)($body['advisor_enabled'] ?? 0),
            ':advisor_start_before' => (int)($body['advisor_start_before'] ?? 90),
            ':advisor_buffer_mode'  => validateEnum($body['advisor_buffer_mode'] ?? 'auto', ['auto','fixed'], 'auto'),
            ':advisor_fixed_buffer' => (int)($body['advisor_fixed_buffer'] ?? 10),
            ':advisor_stages'       => json_encode(is_array($advisorStages) ? $advisorStages : []),
            ':alert_profile_ids'    => json_encode(is_array($alertProfileIds) ? $alertProfileIds : []),
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

    // ─── create_quick_trip ────────────────────────────────────────────────────

    if ($action === 'create_quick_trip') {
        $body = getPostData();

        $destination = trim($body['destination'] ?? '');
        $arrive      = trim($body['arrive']      ?? '');

        if (!$destination) {
            jsonError('destination is required', 400);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $arrive)) {
            jsonError('arrive must be HH:MM', 400);
        }

        $origin          = trim($body['origin'] ?? '');
        $label           = trim($body['label']  ?? '') ?: "Quick Trip — {$arrive}";
        $alertProfileIds = $body['alert_profile_ids'] ?? [];

        // Today's day abbreviation (Mon, Tue, …)
        $dayAbbr  = date('D');
        $id       = 'qt_' . bin2hex(random_bytes(8));
        $now      = date('Y-m-d H:i:s');
        $schedule = json_encode([['days' => $dayAbbr, 'arrive' => $arrive]]);

        $st = $pdo->prepare("
            INSERT INTO routes
                (id, label, origin, destination, travel_mode, schedule,
                 advisor_enabled, advisor_start_before, advisor_buffer_mode,
                 advisor_fixed_buffer, advisor_stages, alert_profile_ids,
                 active, one_time, one_time_used, created_at, updated_at)
            VALUES
                (:id, :label, :origin, :destination, 'driving', :schedule,
                 1, 90, 'auto', 10, :advisor_stages, :alert_profile_ids,
                 1, 1, 0, :created_at, :updated_at)
        ");
        $st->execute([
            ':id'                => $id,
            ':label'             => $label,
            ':origin'            => $origin,
            ':destination'       => $destination,
            ':schedule'          => $schedule,
            ':advisor_stages'    => json_encode(['planning','window','reminder','urgent','last_call']),
            ':alert_profile_ids' => json_encode(is_array($alertProfileIds) ? $alertProfileIds : []),
            ':created_at'        => $now,
            ':updated_at'        => $now,
        ]);

        Config::reset();

        jsonOut(['ok' => true, 'id' => $id]);
    }

    // ─── cleanup_quick_trips ──────────────────────────────────────────────────
    // Deletes one_time routes that are expired (one_time_used=1) or inactive
    // (active=0). Also catches legacy qt_* routes (predating the one_time column)
    // that are inactive. Active, unexpired quick trips are NOT deleted.

    if ($action === 'cleanup_quick_trips') {
        $st = $pdo->prepare("
            DELETE FROM routes
            WHERE (one_time = 1 AND (one_time_used = 1 OR active = 0))
               OR (active = 0 AND id LIKE 'qt_%')
        ");
        $st->execute();
        $deleted = $st->rowCount();

        Config::reset();

        jsonOut(['ok' => true, 'deleted' => $deleted]);
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

    // ─── channel_profiles_save ────────────────────────────────────────────────

    if ($action === 'channel_profiles_save') {
        $body = getPostData();
        $type = trim($body['type'] ?? '');
        $id   = trim($body['id']   ?? '');

        if (!in_array($type, ['telegram','email','signal','viber'], true)) {
            jsonError('Invalid channel type', 400);
        }
        if (!$id || !preg_match('/^[a-z0-9_-]+$/', $id)) {
            jsonError('Profile ID must be lowercase alphanumeric/underscore/dash', 400);
        }
        if (empty($body['label'])) {
            jsonError('label is required', 400);
        }

        $config->saveChannelProfile($type, $body);
        jsonOut(['ok' => true, 'id' => $id]);
    }

    // ─── channel_profiles_delete ─────────────────────────────────────────────

    if ($action === 'channel_profiles_delete') {
        $body = getPostData();
        $type = trim($body['type'] ?? '');
        $id   = trim($body['id']   ?? '');

        if (!in_array($type, ['telegram','email','signal','viber'], true)) {
            jsonError('Invalid channel type', 400);
        }
        if (!$id) {
            jsonError('id is required', 400);
        }

        // Guard: cannot delete if used by an alert profile
        foreach ($config->getAllAlertProfiles() as $ap) {
            foreach ($ap['channels'] as $binding) {
                if ($binding['type'] === $type && $binding['profile_id'] === $id) {
                    jsonError("Cannot delete: profile '{$id}' is used by alert profile '{$ap['id']}'", 409);
                }
            }
        }

        $config->deleteChannelProfile($type, $id);
        jsonOut(['ok' => true]);
    }

    // ─── channel_profiles_test ───────────────────────────────────────────────

    if ($action === 'channel_profiles_test') {
        require_once $baseDir . '/src/AlertManager.php';
        $body = getPostData();
        $type = trim($body['type'] ?? '');
        $id   = trim($body['id']   ?? '');

        $alertMgr = new AlertManager($config);
        $result   = $alertMgr->sendTestChannelProfile($type, $id);
        jsonOut($result);
    }

    // ─── alert_profiles_save ─────────────────────────────────────────────────

    if ($action === 'alert_profiles_save') {
        $body = getPostData();
        $id   = trim($body['id'] ?? '');

        if (!$id || !preg_match('/^[a-z0-9_-]+$/', $id)) {
            jsonError('Profile ID must be lowercase alphanumeric/underscore/dash', 400);
        }
        if (empty($body['label'])) {
            jsonError('label is required', 400);
        }

        $config->saveAlertProfile($body);
        jsonOut(['ok' => true, 'id' => $id]);
    }

    // ─── alert_profiles_delete ───────────────────────────────────────────────

    if ($action === 'alert_profiles_delete') {
        $body = getPostData();
        $id   = trim($body['id'] ?? '');

        if (!$id) {
            jsonError('id is required', 400);
        }

        // Guard: cannot delete if assigned to a route
        foreach ($config->getAllRoutes() as $route) {
            if (in_array($id, $route['alert_profile_ids'] ?? [], true)) {
                jsonError("Cannot delete: profile '{$id}' is assigned to route '{$route['id']}'", 409);
            }
        }

        $config->deleteAlertProfile($id);
        jsonOut(['ok' => true]);
    }

    // ─── alert_profiles_test ─────────────────────────────────────────────────

    if ($action === 'alert_profiles_test') {
        require_once $baseDir . '/src/AlertManager.php';
        $body = getPostData();
        $id   = trim($body['id'] ?? '');

        $alertMgr = new AlertManager($config);
        $result   = $alertMgr->sendTestAlertProfile($id);
        jsonOut($result);
    }
}

// ─── channel_profiles_list ────────────────────────────────────────────────────

if ($action === 'channel_profiles_list') {
    jsonOut(['channel_profiles' => $config->getAllChannelProfiles(), 'generated_at' => date('c')]);
}

// ─── alert_profiles_list ──────────────────────────────────────────────────────

if ($action === 'alert_profiles_list') {
    jsonOut(['alert_profiles' => $config->getAllAlertProfiles(), 'generated_at' => date('c')]);
}

// ─── Unknown action ───────────────────────────────────────────────────────────

jsonError("Unknown action: {$action}");
