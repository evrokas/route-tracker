#!/usr/bin/env bash

# ═══════════════════════════════════════════════════════════════════
# Route Tracker v3 — Installation Script
# ═══════════════════════════════════════════════════════════════════
#
# Usage:
#   chmod +x install.sh
#   sudo ./install.sh                    # Full install to /var/www/route-tracker
#   sudo ./install.sh /custom/path       # Install to custom directory
#   sudo ./install.sh --check-only       # Only check dependencies, don't install
#
# What this script does:
#   1. Checks system requirements (PHP, extensions, tools)
#   2. Installs missing PHP extensions (sqlite3, curl)
#   3. Creates project directory structure
#   4. Sets file permissions for web server
#   5. Initializes the SQLite database (php schema.php --init)
#   6. Generates cron lines
# ═══════════════════════════════════════════════════════════════════

set -e

# ─── Colors ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ─── Defaults ─────────────────────────────────────────────────────────────────
INSTALL_DIR="${1:-/var/www/route-tracker}"
CHECK_ONLY=false
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WEB_USER="www-data"
WEB_GROUP="www-data"
PHP_VERSION=""
ERRORS=0
WARNINGS=0

# ─── Parse arguments ──────────────────────────────────────────────────────────
for arg in "$@"; do
    case "$arg" in
        --check-only)
            CHECK_ONLY=true
            ;;
        --help|-h)
            echo "Usage: sudo $0 [install_dir] [--check-only]"
            echo ""
            echo "  install_dir    Installation directory (default: /var/www/route-tracker)"
            echo "  --check-only   Only check dependencies, don't install anything"
            echo ""
            exit 0
            ;;
        *)
            if [[ "$arg" != --* ]]; then
                INSTALL_DIR="$arg"
            fi
            ;;
    esac
done

# ─── Helper functions ──────────────────────────────────────────────────────────
info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[  OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; ((WARNINGS++)); }
fail()    { echo -e "${RED}[FAIL]${NC} $1"; ((ERRORS++)); }
header()  { echo ""; echo -e "${BOLD}${CYAN}═══ $1 ═══${NC}"; echo ""; }

# ─── Check root ───────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 && "$CHECK_ONLY" != true ]]; then
    echo -e "${RED}This script must be run as root (use sudo)${NC}"
    echo "  sudo $0 $*"
    echo ""
    echo "For dependency check only (no root needed):"
    echo "  $0 --check-only"
    exit 1
fi

# ═══════════════════════════════════════════════════════════════════
header "Route Tracker v3 — Installation"
# ═══════════════════════════════════════════════════════════════════

if [ "$CHECK_ONLY" = true ]; then
    info "Running in check-only mode (no changes will be made)"
else
    info "Install directory: ${BOLD}${INSTALL_DIR}${NC}"
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
header "Step 1: Checking System Requirements"
# ═══════════════════════════════════════════════════════════════════

# ─── PHP ──────────────────────────────────────────────────────────────────────
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    PHP_FULL=$(php -r 'echo PHP_VERSION;')
    PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
    PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

    if [[ "$PHP_MAJOR" -ge 8 ]] || [[ "$PHP_MAJOR" -eq 7 && "$PHP_MINOR" -ge 4 ]]; then
        success "PHP ${PHP_FULL} installed (minimum 7.4 required)"
    else
        fail "PHP ${PHP_FULL} is too old (minimum 7.4 required)"
    fi
else
    fail "PHP is not installed"
    echo ""
    echo "  Install PHP:"
    echo "    sudo apt update && sudo apt install php-cli php-common"
    echo ""
fi

# ─── PHP Extensions ───────────────────────────────────────────────────────────
check_php_ext() {
    local ext="$1"
    local package="$2"

    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        success "PHP extension: ${ext}"
        return 0
    else
        fail "PHP extension missing: ${ext}"
        if [ -n "$package" ]; then
            echo "    Install: sudo apt install ${package}"
        fi
        return 1
    fi
}

check_php_ext "curl"    "php-curl"
check_php_ext "sqlite3" "php-sqlite3"
check_php_ext "pdo_sqlite" "php-sqlite3"
check_php_ext "json"    "php-json"

# Note: php-yaml (PECL) is NOT required in v3 — all config is stored in SQLite
info "Note: php-yaml (PECL) is not required in v3 (config stored in SQLite)"

# ─── curl command ─────────────────────────────────────────────────────────────
if command -v curl &> /dev/null; then
    success "curl command available"
else
    warn "curl command not found (needed for testing)"
    echo "    Install: sudo apt install curl"
fi

# ─── cron ─────────────────────────────────────────────────────────────────────
if command -v crontab &> /dev/null; then
    success "crontab available"
else
    warn "crontab not found"
    echo "    Install: sudo apt install cron"
fi

# ─── Web user ─────────────────────────────────────────────────────────────────
if id "$WEB_USER" &>/dev/null; then
    success "Web server user '${WEB_USER}' exists"
else
    warn "Web server user '${WEB_USER}' not found"
    if id "nginx" &>/dev/null; then
        WEB_USER="nginx"
        WEB_GROUP="nginx"
        info "Using 'nginx' user instead"
    else
        WEB_USER=$(whoami)
        WEB_GROUP=$(id -gn)
        info "Using current user '${WEB_USER}' as fallback"
    fi
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 2: Dependency Summary"
# ═══════════════════════════════════════════════════════════════════

echo ""
if [ $ERRORS -gt 0 ]; then
    echo -e "  ${RED}${BOLD}${ERRORS} error(s)${NC} found"
fi
if [ $WARNINGS -gt 0 ]; then
    echo -e "  ${YELLOW}${BOLD}${WARNINGS} warning(s)${NC} found"
fi
if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}All checks passed!${NC}"
fi
echo ""

if [ "$CHECK_ONLY" = true ]; then
    echo "Check-only mode complete."
    exit $ERRORS
fi

# ─── Stop if critical errors ──────────────────────────────────────────────────
if [ $ERRORS -gt 0 ]; then
    echo -e "${YELLOW}Fix the errors above before continuing.${NC}"
    echo ""
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 3: Auto-Install Missing PHP Extensions"
# ═══════════════════════════════════════════════════════════════════

PACKAGES_TO_INSTALL=""

if ! php -m 2>/dev/null | grep -qi "^curl$"; then
    PACKAGES_TO_INSTALL+=" php-curl"
fi
if ! php -m 2>/dev/null | grep -qi "^sqlite3$"; then
    PACKAGES_TO_INSTALL+=" php-sqlite3"
fi

if [ -n "$PACKAGES_TO_INSTALL" ]; then
    info "Installing:${PACKAGES_TO_INSTALL}"
    apt-get update -qq
    apt-get install -y -qq $PACKAGES_TO_INSTALL
    success "Installed PHP extensions via apt"
else
    info "All required extensions already present"
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 4: Creating Project Directory"
# ═══════════════════════════════════════════════════════════════════

if [ -d "$INSTALL_DIR" ]; then
    info "Directory already exists: ${INSTALL_DIR}"
    read -p "Overwrite files? Existing data/ will be preserved. (Y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Nn]$ ]]; then
        echo "Aborted."
        exit 0
    fi
else
    mkdir -p "$INSTALL_DIR"
    success "Created: ${INSTALL_DIR}"
fi

mkdir -p "${INSTALL_DIR}/data"
success "Created: ${INSTALL_DIR}/data/"

# ═══════════════════════════════════════════════════════════════════
header "Step 5: Copying Project Files"
# ═══════════════════════════════════════════════════════════════════

PHP_FILES=(
    "Config.php"
    "AlertManager.php"
    "DepartureAdvisor.php"
    "auth.php"
    "schema.php"
    "collector.php"
    "advisor.php"
    "api.php"
    "login.php"
    "dashboard.php"
    "dashboard.css"
    "dashboard.js"
    "settings.php"
    "settings.js"
    "settings.css"
    "README.md"
)

for file in "${PHP_FILES[@]}"; do
    src="${SCRIPT_DIR}/${file}"
    dst="${INSTALL_DIR}/${file}"
    if [ -f "$src" ]; then
        cp "$src" "$dst"
        success "Copied: ${file}"
    else
        warn "Source not found: ${file} (will need to be created)"
    fi
done

# Copy install script itself
cp "$0" "${INSTALL_DIR}/install.sh" 2>/dev/null || true

# ═══════════════════════════════════════════════════════════════════
header "Step 6: Setting Permissions"
# ═══════════════════════════════════════════════════════════════════

chown -R "${WEB_USER}:${WEB_GROUP}" "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}"

# Data directory: web server needs write access
chmod 775 "${INSTALL_DIR}/data"

# PHP/HTML files: readable
find "${INSTALL_DIR}" -maxdepth 1 -name "*.php"  -exec chmod 644 {} \;
find "${INSTALL_DIR}" -maxdepth 1 -name "*.html" -exec chmod 644 {} \;
find "${INSTALL_DIR}" -maxdepth 1 -name "*.css"  -exec chmod 644 {} \;
find "${INSTALL_DIR}" -maxdepth 1 -name "*.js"   -exec chmod 644 {} \;
find "${INSTALL_DIR}" -maxdepth 1 -name "*.md"   -exec chmod 644 {} \;

# Executables
[ -f "${INSTALL_DIR}/install.sh"        ] && chmod 755 "${INSTALL_DIR}/install.sh"
[ -f "${INSTALL_DIR}/collector.php"     ] && chmod 755 "${INSTALL_DIR}/collector.php"
[ -f "${INSTALL_DIR}/advisor.php"       ] && chmod 755 "${INSTALL_DIR}/advisor.php"
[ -f "${INSTALL_DIR}/schema.php"        ] && chmod 755 "${INSTALL_DIR}/schema.php"

success "Permissions set (owner: ${WEB_USER}:${WEB_GROUP})"

# ═══════════════════════════════════════════════════════════════════
header "Step 7: Initializing Database"
# ═══════════════════════════════════════════════════════════════════

if [ -f "${INSTALL_DIR}/schema.php" ]; then
    cd "${INSTALL_DIR}"
    php schema.php --init 2>&1 | while IFS= read -r line; do
        echo "    ${line}"
    done
    success "Database initialized (open Settings to configure API key + routes)"
else
    warn "schema.php not found — run 'php schema.php --init' manually after copying files"
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 8: Cron Setup"
# ═══════════════════════════════════════════════════════════════════

echo ""
echo "  Add the following cron job (runs advisor + collection every 5 minutes):"
echo ""
echo "    */5 * * * * php ${INSTALL_DIR}/advisor.php >> ${INSTALL_DIR}/data/advisor.log 2>&1"
echo ""
info "Add with: crontab -e"

# ═══════════════════════════════════════════════════════════════════
header "Step 9: Web Server Configuration"
# ═══════════════════════════════════════════════════════════════════

echo ""
echo "  Choose one of the following to serve the dashboard:"
echo ""
echo -e "  ${BOLD}Option A: PHP Built-in Server (quick test)${NC}"
echo "    cd ${INSTALL_DIR}"
echo "    php -S 0.0.0.0:8080"
echo "    # Open: http://your-server-ip:8080/login.php"
echo ""
echo -e "  ${BOLD}Option B: Apache VirtualHost${NC}"
cat << APACHE
    <VirtualHost *:80>
        ServerName routes.yourdomain.com
        DocumentRoot ${INSTALL_DIR}
        DirectoryIndex dashboard.php

        <Directory ${INSTALL_DIR}>
            AllowOverride None
            Require all granted
        </Directory>

        <Directory ${INSTALL_DIR}/data>
            Require all denied
        </Directory>

        <FilesMatch "\.(log|sqlite)$">
            Require all denied
        </FilesMatch>
    </VirtualHost>
APACHE
echo ""
echo -e "  ${BOLD}Option C: Nginx${NC}"
cat << NGINX
    server {
        listen 80;
        server_name routes.yourdomain.com;
        root ${INSTALL_DIR};
        index dashboard.php;

        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include fastcgi_params;
        }

        location /data/      { deny all; }
        location ~ \.sqlite$ { deny all; }
        location ~ \.log$    { deny all; }
    }
NGINX
echo ""

# ═══════════════════════════════════════════════════════════════════
header "Installation Summary"
# ═══════════════════════════════════════════════════════════════════

echo ""
echo -e "  ${GREEN}${BOLD}Installation complete!${NC}"
echo ""
echo "  Project directory: ${INSTALL_DIR}"
echo ""
echo -e "  ${BOLD}Next steps:${NC}"
echo ""
echo "  1. Open the Settings UI in your browser:"
echo "     http://your-server/settings.php"
echo "     Default login password: changeme"
echo ""
echo "  2. In Settings → General:"
echo "     - Add your Google Maps API key"
echo "     - Set timezone"
echo ""
echo "  3. In Settings → Routes:"
echo "     - Add routes with schedules"
echo "     - Enable the Departure Advisor if desired"
echo ""
echo "  4. In Settings → Alerts:"
echo "     - Configure Telegram / Email / Viber / Signal"
echo ""
echo "  5. Set up cron (see Step 8 above):"
echo "     crontab -e"
echo ""
echo "  6. Test from Settings → System tab:"
echo "     Run Test Collection, Run Advisor Now"
echo ""
