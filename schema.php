#!/usr/bin/env php
<?php

/**
 * schema.php — Route Tracker v2
 * Run once to initialize (or update) the SQLite database.
 *
 * Usage:
 *   php schema.php              # Create/verify DB
 *   php schema.php --reset      # Drop all tables and recreate (DELETES ALL DATA)
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';

// ─── Parse args ──────────────────────────────────────────────────────────────

$reset = in_array('--reset', $argv ?? [], true);

// ─── Boot config ─────────────────────────────────────────────────────────────

try {
    $config = Config::load($baseDir);
} catch (Exception $e) {
    die("Config error: " . $e->getMessage() . "\n");
}

$dbPath = $config->getDbPath();
$dbDir  = dirname($dbPath);

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
    echo "Created directory: {$dbDir}\n";
}

// ─── Open DB ─────────────────────────────────────────────────────────────────

try {
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
} catch (Exception $e) {
    die("Cannot open database: " . $e->getMessage() . "\n");
}

echo "Database: {$dbPath}\n\n";

// ─── Optionally reset ─────────────────────────────────────────────────────────

if ($reset) {
    echo "⚠  RESET mode: dropping all tables…\n";
    $pdo->exec('DROP VIEW  IF EXISTS v_route_stats;');
    $pdo->exec('DROP TABLE IF EXISTS route_steps;');
    $pdo->exec('DROP TABLE IF EXISTS routes;');
    $pdo->exec('DROP TABLE IF EXISTS collections;');
    echo "Tables dropped.\n\n";
}

// ─── Schema ───────────────────────────────────────────────────────────────────

$pdo->exec("
CREATE TABLE IF NOT EXISTS collections (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id        TEXT    NOT NULL,
    route_label     TEXT,
    collected_at    DATETIME NOT NULL,
    scheduled_day   INTEGER NOT NULL,
    scheduled_time  TEXT    NOT NULL,
    schedule_mode   TEXT,
    day_of_week     TEXT    NOT NULL,
    week_number     INTEGER NOT NULL,
    month           INTEGER NOT NULL,
    year            INTEGER NOT NULL,
    origin          TEXT    NOT NULL,
    destination     TEXT    NOT NULL,
    travel_mode     TEXT    NOT NULL,
    api_status      TEXT,
    raw_response    TEXT
);
");
echo "✓ Table: collections\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS routes (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id               INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
    route_index                 INTEGER NOT NULL,
    summary                     TEXT,
    distance_meters             INTEGER NOT NULL DEFAULT 0,
    distance_text               TEXT,
    duration_seconds            INTEGER NOT NULL DEFAULT 0,
    duration_text               TEXT,
    duration_in_traffic_seconds INTEGER,
    duration_in_traffic_text    TEXT,
    start_address               TEXT,
    end_address                 TEXT,
    warnings                    TEXT
);
");
echo "✓ Table: routes\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS route_steps (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id         INTEGER NOT NULL REFERENCES routes(id) ON DELETE CASCADE,
    step_index       INTEGER NOT NULL,
    instruction      TEXT,
    distance_meters  INTEGER,
    duration_seconds INTEGER,
    travel_mode      TEXT,
    road_name        TEXT,
    start_lat        REAL,
    start_lng        REAL,
    end_lat          REAL,
    end_lng          REAL
);
");
echo "✓ Table: route_steps\n";

// ─── Indexes ─────────────────────────────────────────────────────────────────

$indexes = [
    'idx_collections_route'  => 'CREATE INDEX IF NOT EXISTS idx_collections_route  ON collections(route_id);',
    'idx_collections_day'    => 'CREATE INDEX IF NOT EXISTS idx_collections_day    ON collections(scheduled_day);',
    'idx_collections_month'  => 'CREATE INDEX IF NOT EXISTS idx_collections_month  ON collections(month, year);',
    'idx_collections_week'   => 'CREATE INDEX IF NOT EXISTS idx_collections_week   ON collections(week_number, year);',
    'idx_collections_date'   => 'CREATE INDEX IF NOT EXISTS idx_collections_date   ON collections(collected_at);',
    'idx_routes_collection'  => 'CREATE INDEX IF NOT EXISTS idx_routes_collection  ON routes(collection_id);',
    'idx_routes_summary'     => 'CREATE INDEX IF NOT EXISTS idx_routes_summary     ON routes(summary);',
    'idx_steps_route'        => 'CREATE INDEX IF NOT EXISTS idx_steps_route        ON route_steps(route_id);',
];

foreach ($indexes as $name => $sql) {
    $pdo->exec($sql);
}
echo "✓ Indexes created\n";

// ─── View ─────────────────────────────────────────────────────────────────────

$pdo->exec('DROP VIEW IF EXISTS v_route_stats;');
$pdo->exec("
CREATE VIEW v_route_stats AS
SELECT
    c.id           AS collection_id,
    c.route_id,
    c.route_label,
    c.scheduled_day,
    c.day_of_week,
    c.scheduled_time,
    c.schedule_mode,
    c.month,
    c.year,
    c.week_number,
    c.collected_at,
    r.id           AS route_db_id,
    r.route_index,
    r.summary      AS route_name,
    r.duration_seconds,
    r.duration_in_traffic_seconds,
    COALESCE(r.duration_in_traffic_seconds, r.duration_seconds) AS effective_duration,
    r.distance_meters,
    r.distance_text,
    r.duration_text,
    r.duration_in_traffic_text
FROM routes r
JOIN collections c ON r.collection_id = c.id;
");
echo "✓ View: v_route_stats\n";

// ─── Verify ──────────────────────────────────────────────────────────────────

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$views  = $pdo->query("SELECT name FROM sqlite_master WHERE type='view'  ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "\nDatabase ready.\n";
echo "Tables: " . implode(', ', $tables) . "\n";
echo "Views:  " . implode(', ', $views)  . "\n";
echo "\nPath: {$dbPath}\n";
