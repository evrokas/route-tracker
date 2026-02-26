# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Route Tracker v2 is a PHP-based system that:
- Collects Google Maps Directions API data for recurring family trips
- Stores route statistics over time in SQLite
- Identifies the best routes per day/time/season
- Sends alerts via email, Telegram, Viber, or Signal when traffic is heavy or better alternatives exist
- Provides a web dashboard for data visualization

## Core Architecture

**Configuration Layer**
- `Config.php`: Singleton YAML loader with schedule logic, day-of-week expansion (e.g., "Weekdays" → Mon-Fri), and dot-notation getter (`get('google_maps.api_key')`)
- `config.yaml`: Global settings (API keys, database path, timezone, collection windows)
- `routes.yaml`: Route definitions with flexible schedules (depart/arrive times, day groups)
- `alerts.yaml`: Multi-channel alert configuration with per-route rate limiting

**Data Collection**
- `collector.php`: CLI tool run via cron; determines active routes based on schedule windows, calls Google Maps API, stores route data in SQLite, evaluates traffic thresholds
- Schedule system uses `window_before_minutes` and `window_after_minutes` to determine collection windows
- Supports "arrive" mode (calculates departure time) and "depart" mode (collects at exact time)

**Alerting System**
- `AlertManager.php`: Handles alert logic and multi-channel dispatch
- Two alert types: heavy traffic (current > avg + threshold%) and better alternative routes (>2 min savings)
- Rate limiting via `alert_counts.json` (max per route per day)
- Minimum sample requirement before alerts fire

**Web Interface**
- `dashboard.php`: Session-authenticated HTML entry point (loads dashboard.js/css)
- `api.php`: REST API with actions: route_list, overview, by_day, by_month, timeline, best_routes, collections, route_map
- `auth.php`: Session-based authentication (password from config.yaml, no tokens in URLs)
- `dashboard.js`: Vanilla JS client with Canvas charts and tabbed interface
- `map.php`: Interactive map view for a stored collection (`?collection_id=<id>`); uses Leaflet.js + OpenStreetMap tiles (free, no API key). Draws polylines for all routes (primary + alternatives) with distinct colors, start/end emoji markers, a sibling date-picker to jump between collections for the same route, and a side panel with route legend and turn-by-turn steps. Calls `api.php?action=route_map`.
- Overview tab route cards show a 🗺️ link that opens the latest successful collection for that route in `map.php`; the link is omitted if no OK collection exists yet. The `overview` API action returns `latest_collection_id` and `latest_collected_at` via correlated subqueries.
- History tab collection rows also link to `map.php?collection_id=<id>` for any individual collection.
- All API calls require active session; no API token exposed to browser

**Database**
- SQLite with WAL mode enabled
- `schema.php`: Creates tables: collections (raw API data), routes (YAML cache), collection_routes (alternative routes)
- Tables store route_id, timestamps (year/month/day/iso_day/hour/minute), duration_seconds, traffic conditions, route summaries

## Development Commands

**Initial Setup**
```bash
sudo ./install.sh                    # Full installation
php schema.php                       # Initialize database
```

**Testing & Debugging**
```bash
php collector.php --test             # Test API calls without saving
php collector.php --test --route=<route_id>   # Test specific route
php collector.php --test-alerts      # Send test alerts to all channels
php collector.php --schedule         # Show schedule + generate cron lines
```

**Data Collection**
```bash
php collector.php                    # Normal run (only active routes in window)
php collector.php --force            # Force-collect all routes now
php collector.php --force --route=<route_id>   # Force one route
```

**Database Management**
```bash
php schema.php --reset               # Drop and recreate all tables
```

**Web Server**
```bash
php -S 0.0.0.0:8080                  # Built-in PHP server for development
# Production: see apache.config or README for Apache/Nginx configuration
```

## Key Technical Details

**Requirements**
- PHP 7.4+ (8.x recommended)
- Extensions: curl, sqlite3, yaml (PECL)
- Install yaml: `sudo apt install php-dev php-pear libyaml-dev && sudo pecl install yaml`

**Schedule System**
- Day names: `Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun`
- Groups: `Weekdays` (Mon-Fri), `Weekends` (Sat-Sun), `All` (Mon-Sun)
- Schedule entries have `_schedule_mode` (depart/arrive) and `_scheduled_time`
- Config class resolves schedules and determines active routes based on current time

**Authentication**
- Session-based (not token-based in URLs)
- Password stored in config.yaml (`dashboard_password`)
- login.php handles authentication, sets session
- All dashboard pages call `Auth::requireLogin()` or `Auth::requireLoginOrJson()`

**Google Maps API**
- Uses Directions API with alternatives enabled by default
- Language/region set in config.yaml (e.g., `el`/`gr` for Greek)
- Cost estimate: ~$1-1.50/month for typical usage
- API key restrictions recommended: IP whitelist, Directions API only

**Alert Logic**
- Thresholds in alerts.yaml: `traffic_threshold_percent`, `min_samples_for_alerts`, `max_alerts_per_day`
- Heavy traffic: fires when `current > avg * (1 + threshold/100)`
- Better route: fires when alternative saves >2 minutes
- Rate limiting per route_id per calendar day

**Data Directory**
- Auto-created at `data/` (relative to project root)
- Contains: routes.sqlite, collector.log, alerts.log, alert_counts.json
- Should be denied in web server config for security

## Common Patterns

**Adding New Routes**
1. Edit routes.yaml, add route definition with id, label, origin, destination, schedule
2. Run `php collector.php --test --route=<new_id>` to verify
3. Run `php collector.php --schedule` to get updated cron line

**Modifying Alert Channels**
1. Edit alerts.yaml, enable channel and add credentials
2. Test with `php collector.php --test-alerts`
3. Assign channel to route in routes.yaml (`alerts: [email, telegram]`)

**Debugging Collection Issues**
- Check `data/collector.log` for errors
- Use `--test` flag to see API responses without saving
- Verify timezone matches server: `date` should match `timezone` in config.yaml
- Check schedule windows with `php collector.php --schedule`

## Security Notes

- Never commit config.yaml with real API keys/passwords (already in .gitignore)
- Web server must block access to: data/, *.yaml, *.log, *.sqlite
- API token in config.yaml is legacy; current auth uses sessions only
- Restrict Google Maps API key by IP and API type in Cloud Console
