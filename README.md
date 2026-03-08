# Route Tracker v3

A PHP-based system that collects Google Maps Directions API data for recurring
family trips, stores statistics over time in SQLite, identifies the best routes
per day/time/season, and sends alerts via multiple channels when traffic is
unusually heavy or a better alternative exists.

All configuration is stored in SQLite — no YAML files required.

---

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
│   └── apache.config       # Apache vhost example
├── scripts/                # Utility scripts
│   └── install.sh          # Automated installer
├── data/                   # Runtime data (gitignored)
│   ├── routes.sqlite
│   ├── collector.log
│   ├── alerts.log
│   ├── advisor.log
│   └── alert_counts.json
├── CLAUDE.md
└── README.md
```

---

## Quick Start

```bash
# 1. Install + check dependencies
sudo ./scripts/install.sh --check-only    # check only
sudo ./scripts/install.sh                  # full install to /var/www/route-tracker

# 2. Initialize database
php src/schema.php --init

# 3. Test API connection
php src/collector.php --test

# 4. Start dev web server
php -S 0.0.0.0:8080 -t web

# 5. Open http://your-server:8080/login.php
#    Default password: changeme

# 6. Configure via Settings UI:
#    - General: add Google Maps API key, set timezone
#    - Routes: add routes with schedules
#    - Alerts: configure Telegram/Email/Signal/Viber
```

---

## Requirements

| | |
|--|--|
| OS | Linux (Ubuntu 20.04+ / Debian 11+) |
| PHP | 7.4+ (8.x recommended) |
| PHP extensions | `curl`, `sqlite3` (no PECL yaml needed) |
| Web server | Apache or Nginx (or PHP built-in) |
| Cron | For scheduled collection |

---

## Web Server Setup

Point your DocumentRoot at the `web/` directory. This keeps PHP classes, CLI
scripts, and the `data/` directory outside the web root.

**Apache:**
```apache
<VirtualHost *:80>
    ServerName routes.yourdomain.com
    DocumentRoot /path/to/tracker/web
    DirectoryIndex dashboard.php

    <Directory /path/to/tracker/web>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /path/to/tracker/data>
        Require all denied
    </Directory>
</VirtualHost>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name routes.yourdomain.com;
    root /path/to/tracker/web;
    index dashboard.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location /data/      { deny all; }
    location ~ \.sqlite$ { deny all; }
    location ~ \.log$    { deny all; }
}
```

---

## CLI Reference

| Command | Effect |
|---------|--------|
| `php src/schema.php --init` | Create tables + seed defaults (safe to re-run) |
| `php src/schema.php --reset` | Drop all tables, recreate, re-seed (DELETES ALL DATA) |
| `php src/collector.php` | Collect routes within scheduled window |
| `php src/collector.php --force` | Collect ALL routes now |
| `php src/collector.php --force --route=dad_work` | Force one specific route |
| `php src/collector.php --test` | API call + show results, don't save |
| `php src/collector.php --schedule` | Print schedule + cron lines |
| `php src/advisor.php` | Manual advisor run |

---

## Cron Setup

Single cron job handles both advisor and collection:

```
*/5 * * * * php /path/to/tracker/src/advisor.php >> /path/to/tracker/data/advisor.log 2>&1
```

---

## Default Credentials

- Password: `changeme` (set by `schema.php --init`)
- Change via Settings → General → Change Password

---

## Cost Estimate

Google Maps Directions API: ~$5 per 1000 requests.
With 3 routes × 4 collections/hour × active windows ≈ 200–300 requests/month ≈ **~$1–1.50/month**.

Enable billing on Google Cloud Console and restrict the API key to:
- **APIs**: Directions API only
- **IP restrictions**: your server's IP address
