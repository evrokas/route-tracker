#!/usr/bin/env php
<?php

/**
 * schema.php — Route Tracker v3
 * Initialize or reset the SQLite database.
 *
 * Usage:
 *   php schema.php          # Alias for --init
 *   php schema.php --init   # Create tables + seed default settings (safe to re-run)
 *   php schema.php --reset  # Drop all tables, recreate, re-seed (DELETES ALL DATA)
 */

$baseDir = dirname(__DIR__);
require_once __DIR__ . '/Config.php';
Config::loadDeployConfig($baseDir);

$init  = in_array('--init',  $argv ?? [], true);
$reset = in_array('--reset', $argv ?? [], true);

// Default to --init if no flag given
if (!$init && !$reset) {
    $init = true;
}

// ─── Data directory + DB path ─────────────────────────────────────────────────

$dbDir  = $baseDir . '/' . Config::deploy('data_dir');
$dbPath = $dbDir   . '/' . Config::deploy('db_filename');

if (!is_dir($dbDir)) {
    mkdir($dbDir, Config::deploy('dir_permissions'), true);
    echo "Created directory: {$dbDir}\n";
}

// ─── Open DB ──────────────────────────────────────────────────────────────────

try {
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA busy_timeout=5000;');
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
    $pdo->exec('DROP TABLE IF EXISTS alert_profiles;');
    $pdo->exec('DROP TABLE IF EXISTS telegram_profiles;');
    $pdo->exec('DROP TABLE IF EXISTS email_profiles;');
    $pdo->exec('DROP TABLE IF EXISTS signal_profiles;');
    $pdo->exec('DROP TABLE IF EXISTS viber_profiles;');
    $pdo->exec('DROP TABLE IF EXISTS monitoring_tokens;');
    $pdo->exec('DROP TABLE IF EXISTS remember_tokens;');
    echo "Tables dropped.\n\n";
    $init = true;
}

// ─── Core tables ──────────────────────────────────────────────────────────────

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
    alert_profile_ids    TEXT    DEFAULT '[]',
    active               INTEGER DEFAULT 1,
    one_time             INTEGER DEFAULT 0,
    one_time_used        INTEGER DEFAULT 0,
    created_at           TEXT,
    updated_at           TEXT
);
");
echo "✓ Table: routes\n";

// Column definitions kept in variables so the migration block below can rebuild
// these tables with the exact same schema.
$tripsDdl = "
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
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
";
$pdo->exec("CREATE TABLE IF NOT EXISTS trips ({$tripsDdl});");
echo "✓ Table: trips\n";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_route   ON trips(route_id);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_day     ON trips(route_id, scheduled_day);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_month   ON trips(route_id, year, month);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_date    ON trips(collected_at);");
echo "✓ Indexes: trips\n";

$advisorStateDdl = "
    route_id              TEXT NOT NULL,
    schedule_key          TEXT NOT NULL,
    date                  TEXT NOT NULL,
    stages_fired          TEXT DEFAULT '[]',
    recommended_departure TEXT,
    live_duration_seconds INTEGER,
    last_check            TEXT,
    PRIMARY KEY (route_id, schedule_key, date),
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
";
$pdo->exec("CREATE TABLE IF NOT EXISTS advisor_state ({$advisorStateDdl});");
echo "✓ Table: advisor_state\n";

// ─── Channel profile tables ───────────────────────────────────────────────────

$pdo->exec("
CREATE TABLE IF NOT EXISTS telegram_profiles (
    id         TEXT PRIMARY KEY,
    label      TEXT NOT NULL,
    bot_token  TEXT NOT NULL DEFAULT '',
    chat_ids   TEXT NOT NULL DEFAULT '',
    enabled    INTEGER DEFAULT 1,
    created_at TEXT,
    updated_at TEXT
);
");
echo "✓ Table: telegram_profiles\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS email_profiles (
    id              TEXT PRIMARY KEY,
    label           TEXT NOT NULL,
    smtp_host       TEXT NOT NULL DEFAULT '',
    smtp_port       INTEGER DEFAULT 587,
    smtp_encryption TEXT DEFAULT 'tls',
    smtp_user       TEXT NOT NULL DEFAULT '',
    smtp_pass       TEXT NOT NULL DEFAULT '',
    from_address    TEXT NOT NULL DEFAULT '',
    from_name       TEXT DEFAULT 'Route Tracker',
    recipients      TEXT NOT NULL DEFAULT '',
    enabled         INTEGER DEFAULT 1,
    created_at      TEXT,
    updated_at      TEXT
);
");
echo "✓ Table: email_profiles\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS signal_profiles (
    id                TEXT PRIMARY KEY,
    label             TEXT NOT NULL,
    api_url           TEXT NOT NULL DEFAULT '',
    sender_number     TEXT NOT NULL DEFAULT '',
    recipient_numbers TEXT NOT NULL DEFAULT '',
    enabled           INTEGER DEFAULT 1,
    created_at        TEXT,
    updated_at        TEXT
);
");
echo "✓ Table: signal_profiles\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS viber_profiles (
    id           TEXT PRIMARY KEY,
    label        TEXT NOT NULL,
    auth_token   TEXT NOT NULL DEFAULT '',
    receiver_ids TEXT NOT NULL DEFAULT '',
    enabled      INTEGER DEFAULT 1,
    created_at   TEXT,
    updated_at   TEXT
);
");
echo "✓ Table: viber_profiles\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS alert_profiles (
    id         TEXT PRIMARY KEY,
    label      TEXT NOT NULL,
    channels   TEXT NOT NULL DEFAULT '[]',
    enabled    INTEGER DEFAULT 1,
    created_at TEXT,
    updated_at TEXT
);
");
echo "✓ Table: alert_profiles\n";

$monitoringTokensDdl = "
    token        TEXT PRIMARY KEY,
    route_id     TEXT NOT NULL,
    schedule_key TEXT NOT NULL,
    date         TEXT NOT NULL,
    arrive_time  TEXT NOT NULL,
    route_label  TEXT NOT NULL,
    expires_at   TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
";
$pdo->exec("CREATE TABLE IF NOT EXISTS monitoring_tokens ({$monitoringTokensDdl});");
echo "✓ Table: monitoring_tokens\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS remember_tokens (
    token_hash TEXT    PRIMARY KEY,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
");
echo "✓ Table: remember_tokens\n";

// ─── Migrations (safe to re-run on existing DBs) ──────────────────────────────

// Add alert_profile_ids column to existing routes tables that only have alert_channels
try {
    $pdo->exec("ALTER TABLE routes ADD COLUMN alert_profile_ids TEXT DEFAULT '[]'");
    echo "✓ Migration: added alert_profile_ids to routes\n";
} catch (Exception $e) {
    // Column already exists — no action needed
}

try {
    $pdo->exec("ALTER TABLE routes ADD COLUMN one_time INTEGER DEFAULT 0");
    echo "✓ Migration: added one_time to routes\n";
} catch (Exception $e) {
    // Column already exists — no action needed
}

try {
    $pdo->exec("ALTER TABLE routes ADD COLUMN one_time_used INTEGER DEFAULT 0");
    echo "✓ Migration: added one_time_used to routes\n";
} catch (Exception $e) {
    // Column already exists — no action needed
}

// Add ON DELETE CASCADE to every table that references routes(id). Older DBs had
// trips with a plain (RESTRICT) FK and advisor_state / monitoring_tokens with no
// FK at all, so deleting a route either failed or left orphaned rows that grew
// the DB forever. SQLite can't ALTER a constraint, so rebuild any table whose
// definition doesn't already contain "ON DELETE CASCADE".
//
// Procedure follows https://sqlite.org/lang_altertable.html#otheralter:
// foreign_keys OFF (must be outside a transaction) → rebuild inside a txn → check.
$cascadeTargets = [
    'trips'             => $tripsDdl,
    'advisor_state'     => $advisorStateDdl,
    'monitoring_tokens' => $monitoringTokensDdl,
];

foreach ($cascadeTargets as $table => $ddl) {
    $existingSql = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table)
    )->fetchColumn();

    // Table missing (fresh create handled it) or already cascading → nothing to do.
    if ($existingSql === false || stripos($existingSql, 'ON DELETE CASCADE') !== false) {
        continue;
    }

    // Column list to copy (intersection of old and new columns, in old order).
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    $colList = implode(', ', array_map(fn($c) => '"' . $c . '"', $cols));

    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec("DROP TABLE IF EXISTS {$table}_new;");
        $pdo->exec("CREATE TABLE {$table}_new ({$ddl});");
        $pdo->exec("INSERT INTO {$table}_new ({$colList}) SELECT {$colList} FROM {$table};");
        $pdo->exec("DROP TABLE {$table};");
        $pdo->exec("ALTER TABLE {$table}_new RENAME TO {$table};");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('PRAGMA foreign_keys=ON');
        die("Migration failed rebuilding {$table}: " . $e->getMessage() . "\n");
    }

    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    $pdo->exec('PRAGMA foreign_keys=ON');
    if ($violations) {
        die("Migration left foreign key violations in {$table}; aborting.\n");
    }
    echo "✓ Migration: rebuilt {$table} with ON DELETE CASCADE\n";
}

// trips indexes are dropped along with the table during a rebuild — recreate them.
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_route   ON trips(route_id);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_day     ON trips(route_id, scheduled_day);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_month   ON trips(route_id, year, month);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_trips_date    ON trips(collected_at);");

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
        'app_url'                 => '',
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
