# Route Tracker v2

A PHP-based system that collects Google Maps Directions API data for recurring
family trips, stores statistics over time in SQLite, identifies the best routes
per day/time/season, and sends alerts via multiple channels when traffic is
unusually heavy or a better alternative exists.

---

## Quick Start

```bash
# 1. Install + check dependencies
sudo ./install.sh --check-only    # check only
sudo ./install.sh                  # full install to /var/www/route-tracker

# 2. Edit configuration
nano config.yaml   # add Google Maps API key, set api_token
nano routes.yaml   # add work/school addresses
nano alerts.yaml   # configure email/Telegram/Viber/Signal

# 3. Initialize database
php schema.php

# 4. Test API connection
php collector.php --test
php collector.php --test --route=son_learning

# 5. Test alerts
php collector.php --test-alerts

# 6. Generate cron schedule
php collector.php --schedule

# 7. Add cron lines
crontab -e

# 8. Start web server
php -S 0.0.0.0:8080    # quick test
```

---

## File Structure

```
route-tracker/
├── install.sh          # Automated installation script
├── config.yaml         # Global settings (API key, DB, timezone)
├── routes.yaml         # Route definitions with schedules
├── alerts.yaml         # Alert channel configurations
├── Config.php          # YAML config loader + schedule logic
├── AlertManager.php    # Multi-channel alert sending
├── schema.php          # Database initialization (run once)
├── collector.php       # Main cron data collector
├── api.php             # JSON REST API for dashboard
├── dashboard.html      # Browser dashboard (vanilla JS + Canvas)
├── README.md           # This file
└── data/               # Auto-created
    ├── routes.sqlite   # SQLite database
    ├── collector.log   # Collection log
    ├── alerts.log      # Alert log
    └── alert_counts.json
```

---

## Configuration

### config.yaml

| Key | Description |
|-----|-------------|
| `google_maps.api_key` | Your Google Maps Directions API key |
| `api_token` | Random string — must match dashboard.html `API_TOKEN` |
| `timezone` | e.g. `Europe/Athens` |
| `collection.window_before_minutes` | Start collecting X min before scheduled time (default: 15) |
| `collection.window_after_minutes` | Stop collecting X min after (default: 5) |
| `collection.request_alternatives` | Ask Google for alternative routes (default: true) |

### routes.yaml

Schedule `days` field accepts:
- Individual days: `Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun`
- Comma-separated: `Mon,Wed,Fri`
- Groups: `Weekdays`, `Weekends`, `All`

Each schedule entry needs either `depart: "HH:MM"` or `arrive: "HH:MM"`.

- **depart**: collect data at exactly this time
- **arrive**: system calculates estimated departure time (arrive − 45 min by default)

### alerts.yaml

Set `enabled: true` for any channel you want to use.

**Email (SMTP):** Tested with Gmail App Passwords. Set `smtp_encryption: tls` for port 587.

**Telegram:** Create bot via @BotFather. Get chat_id by visiting  
`https://api.telegram.org/bot<TOKEN>/getUpdates` after sending any message to your bot.

**Viber:** Requires a Viber Public Account/Bot. Users must message the bot first.

**Signal:** Requires [signal-cli-rest-api](https://github.com/bbernhard/signal-cli-rest-api)  
running in Docker on your server.

---

## Collector CLI Reference

| Command | Effect |
|---------|--------|
| `php collector.php` | Collect routes within scheduled window |
| `php collector.php --force` | Collect ALL routes now |
| `php collector.php --force --route=dad_work` | Force one specific route |
| `php collector.php --test` | API call + show results, don't save |
| `php collector.php --test --route=son_learning` | Test one route |
| `php collector.php --schedule` | Print schedule + cron lines |
| `php collector.php --test-alerts` | Send test to all enabled channels |

---

## Database Reset

To wipe data and start fresh:
```bash
php schema.php --reset
```

---

## Dashboard

Open `dashboard.html` in a browser. Update `API_TOKEN` in the `<script>` block at  
the bottom to match `config.yaml` → `api_token`.

**Tabs:**
- **Overview** — avg/best/worst per route with stat cards
- **Best Routes** — recommended road per day of week
- **By Day** — grouped bar chart by day + detail table
- **Trends** — timeline, monthly averages, road comparison
- **History** — raw collection log

---

## Web Server

**Apache:**
```apache
<VirtualHost *:80>
    ServerName routes.yourdomain.com
    DocumentRoot /var/www/route-tracker

    <Directory /var/www/route-tracker>
        AllowOverride None
        Require all granted
    </Directory>

    <Directory /var/www/route-tracker/data>
        Require all denied
    </Directory>

    <FilesMatch "\.(yaml|log|sqlite)$">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name routes.yourdomain.com;
    root /var/www/route-tracker;
    index dashboard.html;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location /data/     { deny all; }
    location ~ \.yaml$  { deny all; }
    location ~ \.sqlite$ { deny all; }
}
```

---

## Requirements

| | |
|--|--|
| OS | Linux (Ubuntu 20.04+ / Debian 11+) |
| PHP | 7.4+ (8.x recommended) |
| PHP extensions | `curl`, `sqlite3`, `yaml` (PECL) |
| Web server | Apache or Nginx (or PHP built-in) |
| Cron | For scheduled collection |

### Installing php-yaml

```bash
sudo apt install php-dev php-pear libyaml-dev
sudo pecl install yaml

# PHP 8.x:
echo "extension=yaml.so" | sudo tee /etc/php/8.3/mods-available/yaml.ini
sudo phpenmod yaml

# Verify:
php -m | grep yaml
```

---

## Cost Estimate

Google Maps Directions API: ~$5 per 1000 requests.  
With 3 routes × 4 collections/hour × active windows ≈ 200–300 requests/month ≈ **~$1–1.50/month**.

Enable billing on Google Cloud Console and restrict the API key to:
- **APIs**: Directions API only
- **IP restrictions**: your server's IP address
