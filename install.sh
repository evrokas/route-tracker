#!/usr/bin/env bash

# ═══════════════════════════════════════════════════════════════════
# Route Tracker v2 — Installation Script
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
#   2. Installs missing PHP extensions (yaml, sqlite3, curl)
#   3. Creates project directory structure
#   4. Sets file permissions for web server
#   5. Initializes the SQLite database
#   6. Validates YAML configuration files
#   7. Generates cron lines
#   8. Optionally tests the Google Maps API connection
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
header "Route Tracker v2 — Installation"
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
check_php_ext "json"    "php-json"

# ─── YAML extension (PECL) ────────────────────────────────────────────────────
YAML_MISSING=false
if php -m 2>/dev/null | grep -qi "^yaml$"; then
    success "PHP extension: yaml (PECL)"
else
    fail "PHP extension missing: yaml (PECL)"
    YAML_MISSING=true
    echo ""
    echo "    The YAML extension requires manual installation:"
    echo ""
    echo "    # Step 1: Install build dependencies"
    echo "    sudo apt install php-dev php-pear libyaml-dev"
    echo ""
    echo "    # Step 2: Install via PECL"
    echo "    sudo pecl install yaml"
    echo ""
    echo "    # Step 3: Enable the extension"
    if [ -n "$PHP_VERSION" ]; then
        echo "    echo 'extension=yaml.so' | sudo tee /etc/php/${PHP_VERSION}/mods-available/yaml.ini"
        echo "    sudo phpenmod yaml"
    else
        echo "    echo 'extension=yaml.so' >> /etc/php/PHP_VERSION/cli/php.ini"
    fi
    echo ""
    echo "    # Step 4: Verify"
    echo "    php -m | grep yaml"
    echo ""
fi

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
    info "All apt-installable extensions already present"
fi

# Try to install yaml via PECL if missing
if [ "$YAML_MISSING" = true ]; then
    echo ""
    read -p "Attempt to install php-yaml via PECL? (Y/n) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        info "Installing PECL yaml dependencies..."
        apt-get install -y -qq php-dev php-pear libyaml-dev 2>/dev/null || true

        info "Installing yaml extension via PECL..."
        if pecl install yaml 2>/dev/null; then
            if [ -n "$PHP_VERSION" ] && [ -d "/etc/php/${PHP_VERSION}/mods-available" ]; then
                echo "extension=yaml.so" > "/etc/php/${PHP_VERSION}/mods-available/yaml.ini"
                phpenmod yaml 2>/dev/null || true
                success "yaml extension installed and enabled"
            else
                PHP_INI=$(php -i 2>/dev/null | grep "Loaded Configuration File" | awk '{print $NF}')
                if [ -n "$PHP_INI" ] && [ -f "$PHP_INI" ]; then
                    if ! grep -q "extension=yaml.so" "$PHP_INI"; then
                        echo "extension=yaml.so" >> "$PHP_INI"
                    fi
                    success "yaml extension installed (added to ${PHP_INI})"
                else
                    warn "yaml installed but could not auto-enable. Add 'extension=yaml.so' to php.ini"
                fi
            fi

            if php -m 2>/dev/null | grep -qi "^yaml$"; then
                success "yaml extension verified working"
            else
                warn "yaml extension installed but not loading. Restart PHP-FPM/Apache."
            fi
        else
            fail "PECL yaml installation failed. Install manually (see instructions above)."
        fi
    else
        warn "Skipping yaml installation. You'll need to install it manually."
    fi
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
    "auth.php"
    "schema.php"
    "collector.php"
    "api.php"
    "login.php"
    "dashboard.php"
    "dashboard.css"
    "dashboard.js"
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

# Copy YAML configs — don't overwrite if they already exist (user may have customized)
YAML_FILES=("config.yaml" "routes.yaml" "alerts.yaml")
for file in "${YAML_FILES[@]}"; do
    src="${SCRIPT_DIR}/${file}"
    dst="${INSTALL_DIR}/${file}"
    if [ -f "$dst" ]; then
        info "Preserved existing: ${file} (not overwritten)"
    elif [ -f "$src" ]; then
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

# YAML configs: restricted (contain API keys + credentials)
find "${INSTALL_DIR}" -maxdepth 1 -name "*.yaml" -exec chmod 640 {} \;

# Executables
[ -f "${INSTALL_DIR}/install.sh"   ] && chmod 755 "${INSTALL_DIR}/install.sh"
[ -f "${INSTALL_DIR}/collector.php"] && chmod 755 "${INSTALL_DIR}/collector.php"
[ -f "${INSTALL_DIR}/schema.php"   ] && chmod 755 "${INSTALL_DIR}/schema.php"

success "Permissions set (owner: ${WEB_USER}:${WEB_GROUP})"
info "YAML files restricted to 640 (contain API keys)"

# ═══════════════════════════════════════════════════════════════════
header "Step 7: Validating Configuration"
# ═══════════════════════════════════════════════════════════════════

if php -m 2>/dev/null | grep -qi "^yaml$"; then
    for file in "${YAML_FILES[@]}"; do
        filepath="${INSTALL_DIR}/${file}"
        if [ -f "$filepath" ]; then
            result=$(php -r "
\$data = yaml_parse_file('${filepath}');
if (\$data === false) { echo 'PARSE_ERROR'; }
else { echo 'OK:' . count(\$data) . ' keys'; }
" 2>&1)

            if [[ "$result" == OK* ]]; then
                success "YAML valid: ${file} (${result})"
            else
                fail "YAML parse error: ${file}"
                echo "    ${result}"
            fi
        fi
    done
else
    warn "Cannot validate YAML files (yaml extension not loaded)"
fi

# Check API key
if [ -f "${INSTALL_DIR}/config.yaml" ] && php -m 2>/dev/null | grep -qi "^yaml$"; then
    API_KEY=$(php -r "
\$c = yaml_parse_file('${INSTALL_DIR}/config.yaml');
echo \$c['google_maps']['api_key'] ?? '';
" 2>/dev/null)

    if [ -z "$API_KEY" ] || [ "$API_KEY" = "YOUR_GOOGLE_MAPS_API_KEY_HERE" ]; then
        warn "Google Maps API key not configured yet"
        echo "    Edit: ${INSTALL_DIR}/config.yaml"
        echo "    Set:  google_maps.api_key"
    else
        success "Google Maps API key is set"
    fi
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 8: Initializing Database"
# ═══════════════════════════════════════════════════════════════════

if [ -f "${INSTALL_DIR}/schema.php" ]; then
    cd "${INSTALL_DIR}"
    php schema.php 2>&1 | while IFS= read -r line; do
        echo "    ${line}"
    done
    success "Database initialized"
else
    warn "schema.php not found — run 'php schema.php' manually after copying files"
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 9: Generate Cron Schedule"
# ═══════════════════════════════════════════════════════════════════

if [ -f "${INSTALL_DIR}/collector.php" ]; then
    echo ""
    cd "${INSTALL_DIR}"
    php collector.php --schedule 2>&1 | while IFS= read -r line; do
        echo "    ${line}"
    done
    echo ""
    info "Add the cron lines above with: crontab -e"
else
    warn "collector.php not found"
fi

# ═══════════════════════════════════════════════════════════════════
header "Step 10: Web Server Configuration"
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

        <FilesMatch "\.(yaml|log|sqlite)$">
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
        location ~ \.yaml$   { deny all; }
        location ~ \.sqlite$ { deny all; }
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
echo "  1. Edit configuration files:"
echo "     ${CYAN}nano ${INSTALL_DIR}/config.yaml${NC}     ← Add Google Maps API key + set api_token"
echo "     ${CYAN}nano ${INSTALL_DIR}/routes.yaml${NC}     ← Add work/school addresses"
echo "     ${CYAN}nano ${INSTALL_DIR}/alerts.yaml${NC}     ← Configure alert channels"
echo ""
echo "  2. Update dashboard token:"
echo "     ${CYAN}nano ${INSTALL_DIR}/dashboard.php${NC}  ← Only needed if PHP config loading fails"
echo ""
echo "  3. Test API connection:"
echo "     ${CYAN}cd ${INSTALL_DIR} && php collector.php --test${NC}"
echo ""
echo "  4. Test alerts:"
echo "     ${CYAN}php collector.php --test-alerts${NC}"
echo ""
echo "  5. Set up cron (see schedule above):"
echo "     ${CYAN}crontab -e${NC}"
echo ""
echo "  6. Start web server and open dashboard"
echo ""

if [ "$YAML_MISSING" = true ] && ! php -m 2>/dev/null | grep -qi "^yaml$"; then
    echo -e "  ${RED}${BOLD}⚠ IMPORTANT: php-yaml extension still not loaded.${NC}"
    echo "  The system will NOT work without it. See Step 3 output above."
    echo "  You may need to restart PHP-FPM or Apache after installing."
    echo ""
fi
