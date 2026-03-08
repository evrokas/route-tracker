# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Route Tracker v3 is a PHP-based system that:
- Collects Google Maps Directions API data for recurring family trips
- Stores route statistics over time in SQLite (lean summary rows, no raw JSON)
- Identifies the best routes per day/time/season
- Sends alerts via email, Telegram, Viber, or Signal when traffic is heavy or better alternatives exist
- Runs a Departure Advisor that tells users what time to leave to arrive by a target time
- Provides a web dashboard and a web-based admin settings UI

## Directory Structure

```
tracker/
├── web/                    # Apache DocumentRoot (browser-accessed files only)
│   ├── .htaccess           # Security rules
│   ├── api.php             # JSON REST API
│   ├── dashboard.php       # Main dashboard
│   ├── settings.php        # Admin settings UI
│   ├── login.php           # Login page
│   ├── monitor.php         # Public monitoring page
│   ├── css/
│   │   ├── dashboard.css
│   │   └── settings.css
│   └── js/
│       ├── dashboard.js
│       └── settings.js
├── src/                    # PHP source (classes + CLI scripts)
│   ├── Config.php          # SQLite-backed config singleton
│   ├── AlertManager.php    # Multi-channel alert dispatch
│   ├── DepartureAdvisor.php # Advisor logic
│   ├── auth.php            # Session authentication
│   ├── collector.php       # CLI data collection
│   ├── advisor.php         # CLI cron entry point
│   └── schema.php          # CLI DB initialization
├── config/                 # Configuration templates
│   ├── settings.php        # Deployment-level constants (paths, timeouts, etc.)
│   └── apache.config
├── scripts/
│   └── install.sh
├── docs/
│   └── migration-v3.md
├── data/                   # Runtime data (gitignored)
├── plans/                  # Design docs
├── CLAUDE.md
└── README.md
```

**Path convention:** `web/` files use `$baseDir = dirname(__DIR__)` to get project root. `src/` files use `$baseDir = dirname(__DIR__)` for project root and `__DIR__` for sibling requires. DocumentRoot points at `web/` so `src/`, `data/`, and config files are outside the web root.

## Core Architecture

**Configuration Layer**
- `config/settings.php`: Deployment-level constants (data dir, DB filename, curl timeouts, cookie settings, API limits, debug flag). Optional — defaults apply if missing. Accessed via `Config::deploy('key')`.
- `src/Config.php`: Singleton backed by SQLite `settings` table; dot-notation getter maps `google_maps.api_key` → key `google_maps_api_key`. No YAML files.
- All user-facing config lives in the database — routes, channel profiles, alert profiles, and global settings.

**Data Collection**
- `src/collector.php`: CLI tool run via cron (or invoked by `src/advisor.php`). Determines active routes based on schedule windows, calls Google Maps Directions API, writes summary rows to `trips` table, evaluates traffic thresholds and fires alerts.
- Include guard: `collectorMain()` is only called when the script is the entry point, so `advisor.php` can `require_once` it safely.
- Supports `arrive` mode (target arrival time) and `depart` mode (fixed departure time).
- Schedule window uses `window_before_minutes` and `window_after_minutes` settings.

**Departure Advisor**
- `src/DepartureAdvisor.php`: Core class. Loads historical avg/stddev from `trips`, calls the Directions API live, calculates recommended departure time with a variance-based or fixed buffer, determines which alert stage is due, and dispatches via `AlertManager`.
- `src/advisor.php`: Cron entry point (every 5 min). Iterates advisor-enabled routes, runs `DepartureAdvisor`, and triggers collection for routes in window. Single cron replaces separate collector + advisor jobs.
- Advisor stages: `planning` (60–90 min to arrival), `window` (30–60 min), `reminder` (10–30 min to departure), `urgent` (0–10 min), `last_call` (−5–0 min).

**Alerting System — Two-Tier Profile Model**
- **Channel profiles**: Named credential sets per channel type (`telegram_profiles`, `email_profiles`, `signal_profiles`, `viber_profiles` tables).
- **Alert profiles**: Named bundles of channel profile bindings (`alert_profiles` table). Each binding specifies `type` + `profile_id`.
- Routes store `alert_profile_ids` (JSON array). AlertManager resolves profiles at dispatch time.
- `src/AlertManager.php`: `evaluateAndAlert()` and `sendToRoute()` both call `getRouteAlertProfiles()` then `dispatchProfile()`. All send methods accept a `$cfg` array (no global fallback).
- Two alert types: heavy traffic (`current > avg × (1 + threshold%)`), better alternative (>2 min savings). Rate-limited via `alert_counts.json`.

**Web Interface**
- `web/dashboard.php`: Session-authenticated HTML entry point (loads `js/dashboard.js` / `css/dashboard.css`).
- `web/api.php`: REST API; all data queries on `trips` table. Actions: `route_list`, `overview`, `by_day`, `by_month`, `timeline`, `best_routes`, `trips`, `advisor_status`, `get_settings`, `save_setting`, `save_route`, `delete_route`, `test_collection`, `run_advisor`, `db_stats`, `get_logs`, `export_trips`, `export_config`, `import_config`, `change_password`, `address_history`, `channel_profiles_list`, `channel_profiles_save`, `channel_profiles_delete`, `channel_profiles_test`, `alert_profiles_list`, `alert_profiles_save`, `alert_profiles_delete`, `alert_profiles_test`.
- `web/js/dashboard.js`: Vanilla JS client; tabs: Advisor (default), Overview, By Day, Trends, History.
- `web/settings.php` + `web/js/settings.js` + `web/css/settings.css`: 4-tab admin UI — General, Routes, Alerts, System.
- `src/auth.php`: Session-based authentication (unchanged).
- `web/login.php`: Uses `password_verify()` against hash stored in `settings` table.
- Google Maps navigation URL format: `https://www.google.com/maps/dir/?api=1&origin=<origin>&destination=<destination>&travelmode=driving`
- All API calls require an active session; no tokens in URLs.

**Database**
- SQLite with WAL mode; path: `data/routes.sqlite` relative to project root.
- `src/schema.php`: Creates 9 tables; `--init` seeds default settings (safe to re-run); `--reset` drops all and re-creates.

### Tables

| Table | Purpose |
|-------|---------|
| `settings` | Key/value store for all global config |
| `routes` | Route definitions (schedule JSON, advisor config, `alert_profile_ids`) |
| `trips` | Lean per-trip stats (no raw JSON, no step data) |
| `advisor_state` | Stage tracking per route/schedule/date |
| `telegram_profiles` | Telegram channel credential sets |
| `email_profiles` | SMTP credential sets |
| `signal_profiles` | Signal CLI/API credential sets |
| `viber_profiles` | Viber bot credential sets |
| `alert_profiles` | Bundles of channel profile bindings |

## Development Commands

**Initial Setup**
```bash
php src/schema.php --init        # Create tables + seed defaults (safe to re-run)
php src/schema.php --reset       # Drop all tables, recreate, re-seed (DELETES ALL DATA)
```

**Testing & Debugging**
```bash
php src/collector.php --test                        # Test API calls without saving
php src/collector.php --test --route=<route_id>    # Test specific route
php src/collector.php --schedule                    # Show active schedule
php src/advisor.php                                 # Manual advisor run
```

**Data Collection**
```bash
php src/collector.php                              # Normal run (active routes in window only)
php src/collector.php --force                      # Force-collect all routes now
php src/collector.php --force --route=<route_id>  # Force one route
```

**Cron (single job)**
```
*/5 * * * * php /var/www/html/apps/tracker/src/advisor.php >> data/advisor.log 2>&1
```

**Web Server**
```bash
php -S 0.0.0.0:8080 -t web    # Built-in PHP server for development
```

## Key Technical Details

**Requirements**
- PHP 7.4+ (8.x recommended)
- Extensions: `curl`, `sqlite3` (no PECL yaml required)

**Schedule System**
- Day names: `Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun`
- Groups: `Weekdays` (Mon–Fri), `Weekends` (Sat–Sun), `All` (Mon–Sun)
- Schedule entries: `{ days, arrive }` or `{ days, depart }` stored as JSON in `routes.schedule`
- Advisor only acts on `arrive`-mode schedule entries

**Authentication**
- Session-based; password hash stored in `settings` table under key `dashboard_password_hash`
- Default password: `changeme` (set by `src/schema.php --init`)
- Change via Settings → General → Change Password

**Config dot-notation**
- `Config::get('google_maps.api_key')` → looks up key `google_maps_api_key` in settings table
- `Config::load($baseDir)` (singleton); `Config::reset()` for tests
- `Config::deploy('key')` → deployment constants from `config/settings.php`

**Alert Logic**
- Thresholds in settings: `alert_traffic_threshold` (%), `alert_min_samples`, `alert_max_per_day`
- Heavy traffic: fires when `traffic_duration_seconds > avg × (1 + threshold/100)` with ≥ min_samples
- Better route: fires when best alternative saves >2 minutes vs. primary
- Rate limiting per `route_id` per calendar day via `data/alert_counts.json`

**Buffer from historical stddev (advisor)**
- stddev < 180 s → 5 min buffer
- stddev < 480 s → 10 min buffer
- stddev ≥ 480 s → 15 min buffer

**SQLite stddev (no native function)**
```sql
COALESCE(SQRT(AVG(col*col) - AVG(col)*AVG(col)), 0)
```

**Data Directory**
- Auto-created at `data/` (relative to project root)
- Contains: `routes.sqlite`, `collector.log`, `alerts.log`, `advisor.log`, `alert_counts.json`
- `data/` is outside the web root (`web/`) so it's inaccessible from the browser

## Common Patterns

**Adding/Editing Routes**
- Use Settings → Routes in the web UI, or POST to `web/api.php?action=save_route`
- Test collection: Settings → System → Run Collection Now (or `php src/collector.php --test --route=<id>`)

**Setting Up Alerts**
1. Settings → Alerts → Channel Profiles: add a Telegram/Email/Signal/Viber profile with credentials
2. Settings → Alerts → Alert Profiles: create a profile bundling one or more channel profiles
3. Settings → Routes → Edit route: assign alert profile(s) to the route
4. Test via the Test button on the channel/alert profile row

**Debugging Collection Issues**
- Check `data/collector.log` and `data/advisor.log`
- Use `--test` flag to see API responses without saving
- Verify timezone matches server: `date` output should match `timezone` setting
- Run `php src/collector.php --schedule` to inspect active schedule windows

**Debugging Alerts**
- Check `data/alerts.log` for dispatch results
- Rate limit state: `data/alert_counts.json`
- Ensure route has `alert_profile_ids` set and the profile is enabled
- Ensure channel profile credentials are correct; use Test button in Settings → Alerts

## Security Notes

- Never commit `data/` files with real credentials (already in `.gitignore`)
- DocumentRoot is `web/` — PHP classes, CLI scripts, and `data/` are outside the web root
- `web/.htaccess` provides an extra security layer for sensitive file types
- Restrict Google Maps API key by IP and API type in Cloud Console
- All dashboard and API endpoints enforce session auth via `Auth::requireLogin()` or `Auth::requireLoginOrJson()`
