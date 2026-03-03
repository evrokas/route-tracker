#!/usr/bin/env php
<?php

/**
 * schema.php — Route Tracker v3
 * Initialize or reset the SQLite database (4 tables).
 *
 * Usage:
 *   php schema.php          # Alias for --init
 *   php schema.php --init   # Create tables + seed default settings (safe to re-run)
 *   php schema.php --reset  # Drop all tables, recreate, re-seed (DELETES ALL DATA)
 */

$baseDir = __DIR__;

$init  = in_array('--init',  $argv ?? [], true);
$reset = in_array('--reset', $argv ?? [], true);

// Default to --init if no flag given
if (!$init && !$reset) {
    $init = true;
}

// ─── Data directory + DB path ─────────────────────────────────────────────────

$dbDir  = $baseDir . '/data';
$dbPath = $dbDir   . '/routes.sqlite';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
    echo "Created directory: {$dbDir}\n";
}

// ─── Open DB ──────────────────────────────────────────────────────────────────

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
    $pdo->exec('DROP TABLE IF EXISTS advisor_state;');
    $pdo->exec('DROP TABLE IF EXISTS trips;');
    $pdo->exec('DROP TABLE IF EXISTS routes;');
    $pdo->exec('DROP TABLE IF EXISTS settings;');
    echo "Tables dropped.\n\n";
    $init = true;
}

// ─── Schema ───────────────────────────────────────────────────────────────────

$pdo->exec("
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
");
echo "✓ Table: settings\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS routes (
    id                   TEXT    PRIMARY KEY,
    label                TEXT    NOT NULL,
    origin               TEXT    NOT NULL,
    destination          TEXT    NOT NULL,
    travel_mode          TEXT    DEFAULT 'driving',
    schedule             TEXT    DEFAULT '[]',
    advisor_enabled      INTEGER DEFAULT 0,
    advisor_start_before INTEGER DEFAULT 90,
    advisor_buffer_mode  TEXT    DEFAULT 'auto',
    advisor_fixed_buffer INTEGER DEFAULT 10,
    advisor_stages       TEXT    DEFAULT '[\"planning\",\"window\",\"reminder\",\"urgent\",\"last_call\"]',
    alert_channels       TEXT    DEFAULT '[]',
    active               INTEGER DEFAULT 1,
    created_at           TEXT,
    updated_at           TEXT
);
");
echo "✓ Table: routes\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS trips (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id                 TEXT    NOT NULL,
    collected_at             TEXT    NOT NULL,
    scheduled_day            INTEGER,
    day_of_week              TEXT,
    scheduled_time           TEXT,
    schedule_mode            TEXT,
    year                     INTEGER,
    month                    INTEGER,
    week_number              INTEGER,
    duration_seconds         INTEGER,
    traffic_duration_seconds INTEGER,
    distance_meters          INTEGER,
    primary_summary          TEXT,
    best_alt_seconds         INTEGER,
    best_alt_summary         TEXT,
    api_status               TEXT DEFAULT 'OK',
    FOREIGN KEY (route_id) REFERENCES routes(id)
);
");
echo "✓ Table: trips\n";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_route   ON trips(route_id);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_day     ON trips(route_id, scheduled_day);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_month   ON trips(route_id, year, month);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_date    ON trips(collected_at);");
echo "✓ Indexes: trips\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS advisor_state (
    route_id              TEXT NOT NULL,
    schedule_key          TEXT NOT NULL,
    date                  TEXT NOT NULL,
    stages_fired          TEXT DEFAULT '[]',
    recommended_departure TEXT,
    live_duration_seconds INTEGER,
    last_check            TEXT,
    PRIMARY KEY (route_id, schedule_key, date)
);
");
echo "✓ Table: advisor_state\n";

// ─── Seed default settings ────────────────────────────────────────────────────

if ($init) {
    $defaults = [
        'google_maps_api_key'     => '',
        'google_maps_language'    => 'el',
        'google_maps_region'      => 'gr',
        'timezone'                => 'Europe/Athens',
        'window_before_minutes'   => '15',
        'window_after_minutes'    => '5',
        'request_alternatives'    => '1',
        'dashboard_password_hash' => password_hash('changeme', PASSWORD_DEFAULT),
        'alert_traffic_threshold' => '30',
        'alert_min_samples'       => '5',
        'alert_max_per_day'       => '3',
        'email_enabled'           => '0',
        'email_host'              => '',
        'email_port'              => '587',
        'email_user'              => '',
        'email_pass'              => '',
        'email_from'              => '',
        'email_to'                => '',
        'telegram_enabled'        => '0',
        'telegram_bot_token'      => '',
        'telegram_chat_ids'       => '',
        'viber_enabled'           => '0',
        'viber_auth_token'        => '',
        'viber_receiver_ids'      => '',
        'signal_enabled'          => '0',
        'signal_api_url'          => '',
        'signal_sender'           => '',
        'signal_recipients'       => '',
    ];

    $st = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)");
    foreach ($defaults as $key => $value) {
        $st->execute([':key' => $key, ':value' => $value]);
    }
    echo "✓ Default settings seeded (existing values preserved)\n";
}

// ─── Verify ───────────────────────────────────────────────────────────────────

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
              ->fetchAll(PDO::FETCH_COLUMN);

echo "\nDatabase ready.\n";
echo "Tables: " . implode(', ', $tables) . "\n";
echo "Path:   {$dbPath}\n";
