<?php

/**
 * api.php — Route Tracker v2
 * JSON REST API for the dashboard.
 *
 * Requires an active session (login via login.php / dashboard.php).
 *
 * Note: route_list queries the DB for routes that have actual data.
 *
 * Global filters:
 *   &route_id=son_learning
 *   &year=2025
 *   &month=9
 *   &day=4        (ISO 1=Mon..7=Sun)
 *
 * Actions:
 *   ?action=route_list
 *   ?action=overview
 *   ?action=by_day
 *   ?action=by_month
 *   ?action=by_route_name
 *   ?action=by_week
 *   ?action=timeline
 *   ?action=best_routes
 *   ?action=collections&limit=50
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';

// ─── CORS / JSON output headers ───────────────────────────────────────────────

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

// ─── Auth — session-based (no token in URL or HTML) ──────────────────────────

require_once $baseDir . '/auth.php';
Auth::requireLoginOrJson();

// ─── Open DB ─────────────────────────────────────────────────────────────────

try {
    $pdo = new PDO('sqlite:' . $config->getDbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL;');
} catch (Exception $e) {
    jsonError('Database error: ' . $e->getMessage(), 500);
}

// ─── Read inputs ─────────────────────────────────────────────────────────────

$action  = $_GET['action']   ?? 'overview';
$routeId = isset($_GET['route_id']) ? trim($_GET['route_id']) : null;
$year    = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month   = isset($_GET['month']) ? (int)$_GET['month'] : null;
$day     = isset($_GET['day'])   ? (int)$_GET['day']   : null;
$limit   = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;

// ─── Route: route_list ── runs after DB open so it can return real data ──────

// ─── Helper: build WHERE clause ───────────────────────────────────────────────

function buildWhere(array &$params, ?string $routeId, ?int $year, ?int $month, ?int $day, string $prefix = 'c'): string
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

// ─── Route: route_list — queries DB for routes with actual data ──────────────

if ($action === 'route_list') {
    // Get distinct route IDs + labels from the database (only routes with real data)
    try {
        $rows = $pdo->query("
            SELECT DISTINCT route_id, route_label
            FROM collections
            WHERE api_status = 'OK'
            ORDER BY route_id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        jsonError('Database query failed: ' . $e->getMessage(), 500);
    }

    // Merge with YAML for label overrides (YAML label takes priority, DB is fallback)
    $yamlRoutes = $config->getAllRoutes();
    $yamlLabels = [];
    foreach ($yamlRoutes as $r) {
        $yamlLabels[$r['id']] = $r['label'];
    }

    $routes = [];
    foreach ($rows as $row) {
        $routes[] = [
            'id'    => $row['route_id'],
            'label' => $yamlLabels[$row['route_id']] ?? $row['route_label'] ?? $row['route_id'],
        ];
    }

    // If DB has no data yet (fresh install), fall back to YAML routes
    if (empty($routes)) {
        foreach ($yamlRoutes as $r) {
            $routes[] = ['id' => $r['id'], 'label' => $r['label']];
        }
    }

    jsonOut(['routes' => $routes, 'generated_at' => date('c')]);
}

// ─── Route: overview ──────────────────────────────────────────────────────────

if ($action === 'overview') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label,
            COUNT(DISTINCT c.id)                                              AS total_collections,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration,
            c.scheduled_time, c.schedule_mode,
            MIN(c.collected_at) AS first_seen,
            MAX(c.collected_at) AS last_seen,
            (
                SELECT c2.id FROM collections c2
                WHERE c2.route_id = c.route_id AND c2.api_status = 'OK'
                ORDER BY c2.collected_at DESC
                LIMIT 1
            ) AS latest_collection_id,
            (
                SELECT c2.collected_at FROM collections c2
                WHERE c2.route_id = c.route_id AND c2.api_status = 'OK'
                ORDER BY c2.collected_at DESC
                LIMIT 1
            ) AS latest_collected_at
        FROM collections c
        JOIN routes r ON r.collection_id = c.id AND r.route_index = 0
        WHERE {$where}
        GROUP BY c.route_id, c.scheduled_time, c.schedule_mode
        ORDER BY c.route_id, c.scheduled_time
    ");
    $rows->execute($params);
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Add schedule info from YAML
    foreach ($data as &$row) {
        $yamlRoute = $config->getRoute($row['route_id']);
        $row['schedule'] = $yamlRoute['schedule'] ?? [];
        $row['avg_duration'] = (float)round($row['avg_duration']);
        $row['min_duration'] = (int)$row['min_duration'];
        $row['max_duration'] = (int)$row['max_duration'];
    }
    unset($row);

    jsonOut(['overview' => $data, 'generated_at' => date('c')]);
}

// ─── Route: by_day ────────────────────────────────────────────────────────────

if ($action === 'by_day') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label, c.scheduled_day, c.day_of_week,
            c.scheduled_time, c.schedule_mode,
            r.summary AS route_name,
            COUNT(*)  AS sample_count,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration,
            AVG(r.distance_meters) AS avg_distance
        FROM collections c
        JOIN routes r ON r.collection_id = c.id
        WHERE {$where}
        GROUP BY c.route_id, c.scheduled_day, r.summary
        ORDER BY c.route_id, c.scheduled_day, avg_duration ASC
    ");
    $rows->execute($params);
    $data = formatRows($rows->fetchAll(PDO::FETCH_ASSOC));

    jsonOut(['by_day' => $data, 'generated_at' => date('c')]);
}

// ─── Route: by_month ──────────────────────────────────────────────────────────

if ($action === 'by_month') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label, c.year, c.month,
            COUNT(DISTINCT c.id) AS sample_count,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration
        FROM collections c
        JOIN routes r ON r.collection_id = c.id AND r.route_index = 0
        WHERE {$where}
        GROUP BY c.route_id, c.year, c.month
        ORDER BY c.route_id, c.year, c.month
    ");
    $rows->execute($params);
    jsonOut(['by_month' => formatRows($rows->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── Route: by_route_name ─────────────────────────────────────────────────────

if ($action === 'by_route_name') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label,
            r.summary AS route_name,
            COUNT(*)  AS sample_count,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration,
            AVG(r.distance_meters) AS avg_distance_meters
        FROM collections c
        JOIN routes r ON r.collection_id = c.id
        WHERE {$where}
          AND r.summary IS NOT NULL AND r.summary != ''
        GROUP BY c.route_id, r.summary
        ORDER BY c.route_id, avg_duration ASC
    ");
    $rows->execute($params);
    jsonOut(['by_route_name' => formatRows($rows->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── Route: by_week ───────────────────────────────────────────────────────────

if ($action === 'by_week') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label, c.year, c.week_number,
            COUNT(DISTINCT c.id) AS sample_count,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration
        FROM collections c
        JOIN routes r ON r.collection_id = c.id AND r.route_index = 0
        WHERE {$where}
        GROUP BY c.route_id, c.year, c.week_number
        ORDER BY c.route_id, c.year, c.week_number
    ");
    $rows->execute($params);
    jsonOut(['by_week' => formatRows($rows->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── Route: timeline ─────────────────────────────────────────────────────────

if ($action === 'timeline') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label, c.collected_at, c.day_of_week,
            c.scheduled_time, c.schedule_mode,
            r.summary AS route_name,
            r.route_index,
            COALESCE(r.duration_in_traffic_seconds, r.duration_seconds) AS duration,
            r.distance_meters
        FROM collections c
        JOIN routes r ON r.collection_id = c.id
        WHERE {$where}
        ORDER BY c.collected_at ASC, r.route_index ASC
        LIMIT :lim
    ");
    $params[':lim'] = $limit;
    $rows->execute($params);
    jsonOut(['timeline' => formatRows($rows->fetchAll(PDO::FETCH_ASSOC)), 'generated_at' => date('c')]);
}

// ─── Route: best_routes ───────────────────────────────────────────────────────

if ($action === 'best_routes') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    // Best route per route_id + scheduled_day combination
    $rows = $pdo->prepare("
        SELECT
            c.route_id, c.route_label, c.scheduled_day, c.day_of_week,
            c.scheduled_time, c.schedule_mode,
            r.summary AS route_name,
            COUNT(*)  AS sample_count,
            AVG(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS avg_duration,
            MIN(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS min_duration,
            MAX(COALESCE(r.duration_in_traffic_seconds, r.duration_seconds)) AS max_duration
        FROM collections c
        JOIN routes r ON r.collection_id = c.id
        WHERE {$where}
          AND r.summary IS NOT NULL AND r.summary != ''
        GROUP BY c.route_id, c.scheduled_day, r.summary
        ORDER BY c.route_id, c.scheduled_day, avg_duration ASC
    ");
    $rows->execute($params);
    $all = formatRows($rows->fetchAll(PDO::FETCH_ASSOC));

    // Group: best route per route_id + day, alternatives as sub-array
    $grouped = [];
    foreach ($all as $row) {
        $key = $row['route_id'] . '|' . $row['scheduled_day'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'route_id'      => $row['route_id'],
                'route_label'   => $row['route_label'],
                'scheduled_day' => $row['scheduled_day'],
                'day_of_week'   => $row['day_of_week'],
                'scheduled_time'=> $row['scheduled_time'],
                'schedule_mode' => $row['schedule_mode'],
                'best_route'    => $row,
                'alternatives'  => [],
            ];
        } else {
            $grouped[$key]['alternatives'][] = $row;
        }
    }

    jsonOut(['best_routes' => array_values($grouped), 'generated_at' => date('c')]);
}

// ─── Route: collections ───────────────────────────────────────────────────────

if ($action === 'collections') {
    $params = [];
    $where  = buildWhere($params, $routeId, $year, $month, $day);

    $rows = $pdo->prepare("
        SELECT
            c.id, c.route_id, c.route_label, c.collected_at,
            c.scheduled_day, c.day_of_week, c.scheduled_time, c.schedule_mode,
            c.api_status,
            GROUP_CONCAT(r.summary || ' (' || ROUND(COALESCE(r.duration_in_traffic_seconds,r.duration_seconds)/60.0,1) || ' min)', ' | ') AS routes_summary
        FROM collections c
        LEFT JOIN routes r ON r.collection_id = c.id
        WHERE {$where}
        GROUP BY c.id
        ORDER BY c.collected_at DESC
        LIMIT :lim
    ");
    $params[':lim'] = $limit;
    $rows->execute($params);
    jsonOut(['collections' => $rows->fetchAll(PDO::FETCH_ASSOC), 'generated_at' => date('c')]);
}

// ─── Route: route_map ────────────────────────────────────────────────────────
// Returns all routes + decoded polylines for a specific collection (for map view)

if ($action === 'route_map') {
    $collId = isset($_GET['collection_id']) ? (int)$_GET['collection_id'] : null;
    if (!$collId) jsonError('collection_id is required', 400);

    // Collection metadata
    $st = $pdo->prepare("SELECT * FROM collections WHERE id = :id");
    $st->execute([':id' => $collId]);
    $collection = $st->fetch(PDO::FETCH_ASSOC);
    if (!$collection) jsonError('Collection not found', 404);

    // All routes for this collection
    $st = $pdo->prepare("SELECT * FROM routes WHERE collection_id = :id ORDER BY route_index");
    $st->execute([':id' => $collId]);
    $routes = $st->fetchAll(PDO::FETCH_ASSOC);

    // Decode overview polyline from stored raw_response (smooth road-following curves)
    $rawPolylines = [];
    if (!empty($collection['raw_response'])) {
        $raw = json_decode($collection['raw_response'], true);
        foreach ($raw['routes'] ?? [] as $i => $apiRoute) {
            $enc = $apiRoute['overview_polyline']['points'] ?? null;
            if ($enc) $rawPolylines[$i] = decodePolyline($enc);
        }
    }

    // Attach steps + polyline to each route
    foreach ($routes as &$route) {
        $st = $pdo->prepare("
            SELECT step_index, instruction, distance_meters, duration_seconds,
                   road_name, start_lat, start_lng, end_lat, end_lng
            FROM route_steps WHERE route_id = :id ORDER BY step_index
        ");
        $st->execute([':id' => $route['id']]);
        $route['steps']    = $st->fetchAll(PDO::FETCH_ASSOC);
        $route['polyline'] = $rawPolylines[$route['route_index']] ?? null;

        // Fall back to step waypoints if no overview polyline
        if (empty($route['polyline']) && !empty($route['steps'])) {
            $pts = [];
            foreach ($route['steps'] as $s) {
                if ($s['start_lat'] !== null) $pts[] = [(float)$s['start_lat'], (float)$s['start_lng']];
                if ($s['end_lat']   !== null) $pts[] = [(float)$s['end_lat'],   (float)$s['end_lng']];
            }
            $route['polyline'] = $pts;
        }
    }
    unset($route);

    // Nearby collections for the same route+day (for "browse other days" selector)
    $st = $pdo->prepare("
        SELECT id, collected_at, scheduled_time, schedule_mode,
               (SELECT ROUND(COALESCE(r2.duration_in_traffic_seconds, r2.duration_seconds)/60.0, 1)
                FROM routes r2 WHERE r2.collection_id = c.id AND r2.route_index = 0) AS primary_min
        FROM collections c
        WHERE route_id    = :route_id
          AND scheduled_time = :sched_time
          AND api_status  = 'OK'
        ORDER BY collected_at DESC
        LIMIT 30
    ");
    $st->execute([
        ':route_id'   => $collection['route_id'],
        ':sched_time' => $collection['scheduled_time'],
    ]);
    $siblings = $st->fetchAll(PDO::FETCH_ASSOC);

    // Strip raw_response from output (it's large and not needed by the client)
    unset($collection['raw_response']);

    jsonOut([
        'collection' => $collection,
        'routes'     => $routes,
        'siblings'   => $siblings,
    ]);
}

// ─── Unknown action ───────────────────────────────────────────────────────────

jsonError("Unknown action: {$action}");

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatRows(array $rows): array
{
    foreach ($rows as &$row) {
        foreach (['avg_duration', 'min_duration', 'max_duration'] as $f) {
            if (isset($row[$f])) {
                $row[$f] = (float)round($row[$f]);
            }
        }
        if (isset($row['avg_distance_meters'])) {
            $row['avg_distance_meters'] = (int)round($row['avg_distance_meters']);
        }
    }
    return $rows;
}

/**
 * Decode a Google Maps encoded polyline string into an array of [lat, lng] pairs.
 * Algorithm: https://developers.google.com/maps/documentation/utilities/polylinealgorithm
 */
function decodePolyline(string $encoded): array
{
    $points = [];
    $index  = 0;
    $len    = strlen($encoded);
    $lat    = 0;
    $lng    = 0;

    while ($index < $len) {
        // Decode one coordinate component (lat or lng)
        $decode = function() use (&$index, $len, $encoded): int {
            $result = 0;
            $shift  = 0;
            do {
                $b      = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift  += 5;
            } while ($b >= 0x20 && $index < $len);
            return ($result & 1) ? ~($result >> 1) : ($result >> 1);
        };

        $lat += $decode();
        $lng += $decode();
        $points[] = [round($lat * 1e-5, 6), round($lng * 1e-5, 6)];
    }
    return $points;
}
