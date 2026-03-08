# Migration to v3

## What changed

1. **No more YAML files** — `config.yaml`, `routes.yaml`, `alerts.yaml` are gone. All configuration is stored in the SQLite database and managed via the web Settings UI.

2. **Directory restructure** — Files are organized into `web/` (browser-accessible), `src/` (PHP classes and CLI scripts), `config/` (templates), and `scripts/` (utilities). The web server DocumentRoot should point at `web/`.

3. **Single cron job** — `advisor.php` handles both advisor checks and data collection. Only one cron entry is needed:
   ```
   */5 * * * * php /path/to/tracker/src/advisor.php >> /path/to/tracker/data/advisor.log 2>&1
   ```

4. **php-yaml not required** — The PECL yaml extension is no longer needed.

5. **Session-based auth** — Dashboard and settings are protected by session authentication. Default password: `changeme`.

## Upgrading from v2

1. Back up your `data/routes.sqlite` database.
2. Deploy the new file structure.
3. Run `php src/schema.php --init` (safe to re-run — only adds missing tables/settings).
4. Update your web server config to point DocumentRoot at `web/`.
5. Update your cron job to use `src/advisor.php`.
6. Re-enter any settings (API key, routes, alerts) via the Settings UI if starting fresh.
