<?php
/**
 * config/settings.php — Route Tracker v3
 * Deployment-level constants. Edit these for your environment.
 *
 * User-facing settings (API keys, thresholds, timezone, routes) are managed
 * via the Settings UI and stored in the SQLite database — not here.
 *
 * This file is optional. If missing, all values fall back to the defaults below.
 */

return [
    // ─── Paths ───────────────────────────────────────────────────────
    'data_dir'              => 'data',              // relative to project root
    'db_filename'           => 'routes.sqlite',     // inside data_dir

    // ─── Curl / HTTP ─────────────────────────────────────────────────
    'curl_timeout'          => 30,                  // Google Maps API calls (seconds)
    'curl_timeout_alerts'   => 15,                  // Alert dispatch calls (seconds)

    // ─── Authentication ──────────────────────────────────────────────
    'remember_me_cookie'    => 'rt_remember',       // cookie name
    'remember_me_ttl'       => 30 * 24 * 3600,      // 30 days in seconds

    // ─── API / UI limits ─────────────────────────────────────────────
    'log_tail_lines'        => 50,                  // lines shown in Settings → Logs
    'api_default_limit'     => 100,                 // default row limit for API queries
    'api_max_limit'         => 500,                 // maximum row limit for API queries

    // ─── Filesystem ──────────────────────────────────────────────────
    'dir_permissions'       => 0775,                // data directory permissions

    // ─── Advisor buffer thresholds (seconds of stddev) ───────────────
    'buffer_stddev_low'     => 180,                 // below this → 5 min buffer
    'buffer_stddev_high'    => 480,                 // below this → 10 min buffer; above → 15 min

    // ─── Debug ───────────────────────────────────────────────────────
    'debug'                 => false,               // enable verbose error output
];
