# Route Tracker v3 — Full Rebuild Plan

## Context

The rebuild centers on **Enhancement 1: Departure Time Advisor** from
`plans/route-tracker-enhancements.md` — the core value proposition is answering
"What time should I leave to arrive by 07:30?" rather than passively collecting
data. The system runs a countdown advisor that checks live traffic in a window
before the target arrival, calculates the latest safe departure with a
variance-based buffer, and sends progressive alerts (Planning → Window →
Reminder → Urgent → Last Call).

Two structural changes drive the rebuild:
1. **No YAML files** — all configuration (API keys, alert channels, routes,
   schedules) is stored in SQLite and managed through a new web settings UI.
2. **Lean database** — only statistical summaries per trip are stored (no raw
   JSON blobs, no turn-by-turn steps). `map.php` is dropped since step-polyline
   data will not be stored; the already-implemented Google Maps nav links (🧭)
   replace it for navigation.

The auth system, AlertManager dispatch logic, and SimpleChart/vanilla-JS
approach are preserved.

---

## New Database Schema (4 tables)

### `settings` — replaces config.yaml + alerts.yaml
```sql
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
```
Seeded with defaults on first run:

| Key | Default |
|-----|---------|
| `google_maps_api_key` | _(empty)_ |
| `google_maps_language` | `el` |
| `google_maps_region` | `gr` |
| `timezone` | `Europe/Athens` |
| `window_before_minutes` | `15` |
| `window_after_minutes` | `5` |
| `request_alternatives` | `1` |
| `dashboard_password_hash` | _(hashed "changeme")_ |
| `alert_traffic_threshold` | `30` |
| `alert_min_samples` | `5` |
| `alert_max_per_day` | `3` |
| `email_enabled` | `0` |
| `email_host`, `email_port` (587), `email_user`, `email_pass`, `email_from`, `email_to` | _(empty)_ |
| `telegram_enabled` | `0` |
| `telegram_bot_token`, `telegram_chat_ids` | _(empty)_ |
| `viber_enabled` | `0` |
| `viber_auth_token`, `viber_receiver_ids` | _(empty)_ |
| `signal_enabled` | `0` |
| `signal_api_url`, `signal_sender`, `signal_recipients` | _(empty)_ |

---

### `routes` — replaces routes.yaml
```sql
CREATE TABLE IF NOT EXISTS routes (
    id                     TEXT    PRIMARY KEY,   -- slug e.g. "vangelis_work"
    label                  TEXT    NOT NULL,
    origin                 TEXT    NOT NULL,
    destination            TEXT    NOT NULL,
    travel_mode            TEXT    DEFAULT 'driving',
    schedule               TEXT    DEFAULT '[]',  -- JSON [{days:"Weekdays",arrive:"07:30"}]
    advisor_enabled        INTEGER DEFAULT 0,
    advisor_start_before   INTEGER DEFAULT 90,    -- minutes before arrival to start
    advisor_buffer_mode    TEXT    DEFAULT 'auto',-- 'auto' (from stddev) or 'fixed'
    advisor_fixed_buffer   INTEGER DEFAULT 10,    -- minutes, used if mode=fixed
    advisor_stages         TEXT    DEFAULT '["planning","window","reminder","urgent","last_call"]',
    alert_channels         TEXT    DEFAULT '[]',  -- JSON ["telegram","email"]
    active                 INTEGER DEFAULT 1,
    created_at             TEXT,
    updated_at             TEXT
);
```

---

### `trips` — replaces collections + routes + route_steps (lean stats only)
```sql
CREATE TABLE IF NOT EXISTS trips (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id                 TEXT    NOT NULL,
    collected_at             TEXT    NOT NULL,
    scheduled_day            INTEGER,  -- ISO 1-7
    day_of_week              TEXT,     -- "Mon","Tue",...
    scheduled_time           TEXT,     -- "HH:MM" (target arrive or depart)
    schedule_mode            TEXT,     -- "arrive" or "depart"
    year                     INTEGER,
    month                    INTEGER,
    week_number              INTEGER,
    duration_seconds         INTEGER,
    traffic_duration_seconds INTEGER,  -- primary statistic
    distance_meters          INTEGER,
    primary_summary          TEXT,     -- e.g. "Συγγρού"
    best_alt_seconds         INTEGER,
    best_alt_summary         TEXT,
    api_status               TEXT DEFAULT 'OK',
    FOREIGN KEY (route_id) REFERENCES routes(id)
);
CREATE INDEX IF NOT EXISTS idx_trips_route   ON trips(route_id);
CREATE INDEX IF NOT EXISTS idx_trips_day     ON trips(route_id, scheduled_day);
CREATE INDEX IF NOT EXISTS idx_trips_month   ON trips(route_id, year, month);
CREATE INDEX IF NOT EXISTS idx_trips_date    ON trips(collected_at);
```

---

### `advisor_state` — tracks alert stages fired per route per day
```sql
CREATE TABLE IF NOT EXISTS advisor_state (
    route_id              TEXT NOT NULL,
    schedule_key          TEXT NOT NULL,  -- "07:30_arrive"
    date                  TEXT NOT NULL,  -- "YYYY-MM-DD"
    stages_fired          TEXT DEFAULT '[]',  -- JSON ["planning","window"]
    recommended_departure TEXT,           -- "06:47"
    live_duration_seconds INTEGER,
    last_check            TEXT,
    PRIMARY KEY (route_id, schedule_key, date)
);
```

---

## File Plan

### Files to REWRITE

| File | Key Changes |
|------|-------------|
| `schema.php` | New 4-table schema; seed default settings on `--init`; `--reset` drops all |
| `Config.php` | Load from `settings` table via PDO instead of YAML; same dot-notation getter interface; `getAllRoutes()` / `getActiveRoutes()` query `routes` table |

### Files to REFACTOR

| File | Key Changes |
|------|-------------|
| `collector.php` | Write to `trips` table (no raw_response, no steps); read routes from DB via Config; keep `--test`, `--force`, `--schedule` flags |
| `AlertManager.php` | Remove YAML dependency; read channel config from Config (DB-backed); dispatch logic unchanged |
| `api.php` | Update all queries to `trips` table; add new admin endpoints (`save_setting`, `save_route`, `delete_route`, `test_collection`, `test_alert`, `advisor_status`); remove `route_map` action |
| `dashboard.js` | Add "Advisor" tab (`renderAdvisor()`); update `renderHistory()` to query `trips`; remove map links (or keep Google Maps nav links only) |
| `dashboard.php` | Add "Advisor" tab button; remove YAML config-error banner |
| `dashboard.css` | Add settings page styles; advisor card styles |

### Files to CREATE

| File | Purpose |
|------|---------|
| `DepartureAdvisor.php` | Core advisor class: buffer calc, stage detection, state tracking, alert dispatch |
| `advisor.php` | Cron entry point (every 5 min); iterates advisor-enabled routes; also runs collector for routes in window |
| `settings.php` | Multi-tab web admin UI (General / Routes / Alerts / System) |
| `settings.js` | AJAX handlers for settings page: save, test alert, test collection, route CRUD |
| `settings.css` | Settings page styles (or merged into dashboard.css) |

### Files to KEEP unchanged

| File | Reason |
|------|--------|
| `auth.php` | Session auth is solid, no changes needed |
| `login.php` | Already reads password from Config; will work once Config reads from DB |

### Files to DROP

| File | Reason |
|------|--------|
| `map.php` | Polyline rendering required route_steps; not stored in v3; 🧭 nav links cover the use case |
| `config.yaml` | Replaced by settings table |
| `routes.yaml` | Replaced by routes table |
| `alerts.yaml` | Replaced by settings table |

---

## Settings UI (`settings.php`) — Tab Design

### Tab 1: General
- Google Maps API key (password input) + [Test API] button
- Timezone (select dropdown from PHP's timezone list)
- Language / Region (text inputs, e.g. `el` / `gr`)
- Collection window: before/after minutes
- Change dashboard password (old → new → confirm)
- [Save General Settings] button

### Tab 2: Routes
- Table: ID | Label | Origin → Destination | Schedule summary | Advisor | Active | Actions
- Per-row: [Edit] [Delete] [Toggle Active]
- [Add New Route] expands a form:
  - Label, Origin, Destination, Travel Mode (select)
  - Schedule builder: rows of [Days dropdown] [arrive|depart toggle] [HH:MM time] [− remove]
    - Days options: Weekdays / Weekends / All / Mon / Tue / Wed / Thu / Fri / Sat / Sun
  - Advisor section (collapsible):
    - Enable checkbox
    - Start X minutes before arrival (default 90)
    - Buffer mode: Auto (from historical variance) / Fixed
    - Fixed buffer minutes (shown when mode=Fixed)
    - Alert stages: checkboxes for Planning / Window / Reminder / Urgent / Last Call
  - Alert channels: multi-checkboxes per enabled channel
  - [Save Route] / [Cancel]

### Tab 3: Alerts
- Per-channel cards (Telegram, Email, Viber, Signal):
  - Enable toggle
  - Channel-specific fields (bot token, chat IDs, SMTP host/port/user/pass, etc.)
  - [Test Channel] button → AJAX call to `api.php?action=test_alert&channel=telegram`
- Global thresholds: traffic_threshold %, min_samples, max_alerts_per_day
- [Save Alert Settings]

### Tab 4: System
- [Run Collection Now] → `api.php?action=test_collection` (dropdown to pick route)
- [Run Advisor Now] → `api.php?action=run_advisor`
- Recent log entries (last 20 lines from `data/collector.log` and `data/alerts.log`)
- DB stats: trip count, date range, routes count
- [Export Trips CSV] → download

---

## DepartureAdvisor.php — Core Logic

```php
class DepartureAdvisor {
    // Determine buffer minutes from historical stddev
    private function bufferSeconds(float $stddevSec): int {
        if ($stddevSec < 180) return 300;   // <3 min stddev → 5 min buffer
        if ($stddevSec < 480) return 600;   // <8 min stddev → 10 min buffer
        return 900;                          // ≥8 min stddev → 15 min buffer
    }

    // Map current timing to an alert stage name (or null)
    private function currentStage(int $minsToArrival, int $minsToDeparture): ?string {
        if ($minsToArrival >= 60 && $minsToArrival <= 90) return 'planning';
        if ($minsToArrival >= 30 && $minsToArrival < 60)  return 'window';
        if ($minsToDeparture >= 10 && $minsToDeparture <= 30) return 'reminder';
        if ($minsToDeparture >= 0  && $minsToDeparture < 10)  return 'urgent';
        if ($minsToDeparture >= -5 && $minsToDeparture < 0)   return 'last_call';
        return null;
    }

    public function run(array $route, array $schedEntry): void {
        // 1. Parse arrival target from schedEntry (arrive mode only for advisor)
        // 2. Check if today is a scheduled day for this entry
        // 3. Check if now is within advisor window (start_before_minutes before arrival)
        // 4. Load historical avg + stddev from trips table for this route/day/scheduled_time
        // 5. Call Google Directions API with departure_time=now
        // 6. Extract live traffic duration from primary route
        // 7. Calculate buffer (auto from stddev, or fixed)
        // 8. recommended_departure = arrival_time - live_duration - buffer
        // 9. Calculate minsToArrival and minsToDeparture
        // 10. Determine current stage
        // 11. Load advisor_state for today
        // 12. If stage not yet fired and stage in configured stages → dispatch alert
        // 13. Update advisor_state (stages_fired, recommended_departure, last_check)
    }
}
```

**Alert message format per stage:**
- `planning`: "Traffic looks normal. Leave by 06:55 to arrive by 07:30 via Συγγρού (33 min)."
- `window`: "Recommended departure: 06:50. Live estimate: 36 min via Χαμοστέρνας."
- `reminder`: "Leave in 28 min (by 06:50) for 07:30 arrival. 🧭 [Navigate]"
- `urgent`: "⚠️ Leave in 8 min! Traffic building — 40 min via Πειραιώς."
- `last_call`: "🚨 Departure time! Best: Ποσειδώνος (32 min). 🧭 [Navigate]"

---

## advisor.php — Cron Entry Point

```
*/5 * * * * php /var/www/html/apps/tracker/advisor.php >> data/advisor.log 2>&1
```

Flow:
1. Open DB, load Config
2. Fetch all routes where `active = 1`
3. For each route:
   a. Parse `schedule` JSON; for each schedule entry:
      - If `advisor_enabled` and entry has `arrive` mode → run `DepartureAdvisor::run()`
   b. If route is in collection window → call collector's `processRoute()`
4. One cron job replaces the separate collector cron and advisor cron

---

## API Endpoints (updated)

### Existing endpoints updated for `trips` table:
| Action | Query change |
|--------|-------------|
| `overview` | `SELECT route_id, AVG(traffic_duration_seconds)... FROM trips GROUP BY route_id, scheduled_time` |
| `by_day` | `FROM trips GROUP BY route_id, scheduled_day` |
| `by_month` | `FROM trips GROUP BY route_id, year, month` |
| `timeline` | `FROM trips ORDER BY collected_at DESC LIMIT 200` |
| `best_routes` | `FROM trips GROUP BY route_id, scheduled_day, primary_summary` |
| `collections` → renamed `trips` | `FROM trips ORDER BY collected_at DESC LIMIT :lim` |
| `route_list` | `FROM routes WHERE active = 1` (no YAML fallback needed) |

### New endpoints:
| Action | Method | Purpose |
|--------|--------|---------|
| `advisor_status` | GET | Current advisor_state for all routes: recommendation, stages fired, time until departure |
| `save_setting` | POST | Upsert one or many keys into settings table |
| `save_route` | POST | Create or update a route row (JSON body) |
| `delete_route` | POST | Delete route by id |
| `test_collection` | GET | Run API call for a route without saving; return result JSON |
| `test_alert` | POST | Send test message on a channel |
| `run_advisor` | GET | Trigger advisor check immediately (for System tab) |

### Removed:
- `route_map` — dropped with map.php

---

## Dashboard Changes

### New "Advisor" tab (default/first tab)
Cards per route with advisor enabled:
```
┌────────────────────────────────────────────┐
│ Vangelis → Work          [Weekdays 07:30]  │
│ Leave by  06:47  (in 23 min)               │
│ Live: 38 min via Συγγρού  (+8% vs avg)     │
│ Buffer: 5 min (consistent route)           │
│ Stages: ✓ Planning  ✓ Window  • Reminder   │
│                         [🧭 Navigate Now]  │
└────────────────────────────────────────────┘
```

### Updated tabs:
- **Overview** — unchanged layout; data from `trips` table; remove 🗺️ map links
- **By Day** — unchanged
- **Trends** — unchanged
- **History** — renamed from "History"; data from `trips` table; keep 🧭 nav links; remove 🗺️ map links

---

## Implementation Order

1. **`schema.php`** — New 4-table schema + settings seed; test with `php schema.php --init`
2. **`Config.php`** — Rewrite to load from DB; preserve all public method signatures
3. **`collector.php`** — Update to write `trips` table; use DB-backed Config
4. **`AlertManager.php`** — Remove YAML dependency; read from Config (now DB-backed)
5. **`DepartureAdvisor.php`** — New class; implement run() with full stage logic
6. **`advisor.php`** — New cron entry; wire up DepartureAdvisor + collector
7. **`api.php`** — Update queries; add new endpoints
8. **`settings.php` + `settings.js`** — New admin UI with 4 tabs
9. **`dashboard.js`** — Add Advisor tab renderer; update History; remove map links
10. **`dashboard.php`** — Add Advisor tab button

---

## Verification

1. `php schema.php --init` → 4 tables created, defaults seeded
2. Open `settings.php` → add Google Maps API key, create a route with advisor enabled,
   configure Telegram
3. Settings → System tab → "Test Collection" → trip row appears in DB
4. `php advisor.php` → advisor runs, Telegram message received
5. `php collector.php --force` → trip saved, alert evaluated
6. Dashboard Advisor tab → shows recommendation card with countdown
7. Dashboard Overview / By Day / Trends / History → data renders correctly
8. `php collector.php --schedule` → prints cron line suggestion (single advisor.php job)
9. Settings → Alerts tab → "Test Telegram" → test message received
10. Settings → Routes tab → add/edit/delete a route → changes persist after reload

---

## Notes

- **No migration path** from v2 DB — fresh start (schema is incompatible)
- **YAML extension** (`pecl yaml`) no longer required; remove from `install.sh`
- **`data/advisor_state.json`** from Enhancement 1 plan is replaced by
  the `advisor_state` DB table (more robust, no file locking issues)
- **Login.php** reads password via `Config::get('dashboard_password_hash')`;
  once Config reads from DB this works automatically — no code change needed
- The plan document should also be saved to `plans/rebuild-v3.md` in the project
  repo after approval
