#!/usr/bin/env bash
set -euo pipefail

# ================================================================
# WordPress PHPUnit Setup Script (auto-detects wp-config.php)
# ================================================================
# - Reads DB credentials from wp-config.php (or accepts overrides)
# - Creates a test DB (<DB_NAME>_test by default, overridable)
# - Installs WP core + test suite into /tmp
# - Installs required system tools if missing
# - Runs vendor/bin/phpunit --testdox from the PLUGIN dir
# - Does NOT modify bootstrap or phpunit.xml.dist
# ================================================================

say()  { printf "\n\033[1;36m==>\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m %s\n" "$*"; }
die()  { printf "\033[1;31m[err]\033[0m %s\n" "$*"; exit 1; }

usage() {
  cat <<'EOF'
Usage: ./setup-wp-phpunit.sh [options]

Options:
  --db-name NAME          Base WordPress database name (defaults to wp-config value)
  --db-user USER          Database user
  --db-password PASS      Database password
  --db-host HOST[:PORT]   Database host (with optional port)
  --db-port PORT          Database port (overrides value inside --db-host)
  --test-db-name NAME     Name for the temporary test database (default: <db-name>_test)
  -h, --help              Show this help text

When overrides are omitted, credentials are parsed from wp-config.php.
EOF
}

# Directory where this script resides (assumed to be the plugin root)
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
PLUGIN_DIR="$SCRIPT_DIR"

# Hold optional overrides
ARG_DB_NAME=""
ARG_DB_USER=""
ARG_DB_PASS=""
ARG_DB_HOST=""
ARG_DB_PORT=""
ARG_TEST_DB_NAME=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db-name)
      [[ $# -ge 2 ]] || die "Missing value for --db-name"
      ARG_DB_NAME="$2"
      shift 2
      ;;
    --db-user)
      [[ $# -ge 2 ]] || die "Missing value for --db-user"
      ARG_DB_USER="$2"
      shift 2
      ;;
    --db-password)
      [[ $# -ge 2 ]] || die "Missing value for --db-password"
      ARG_DB_PASS="$2"
      shift 2
      ;;
    --db-host)
      [[ $# -ge 2 ]] || die "Missing value for --db-host"
      ARG_DB_HOST="$2"
      shift 2
      ;;
    --db-port)
      [[ $# -ge 2 ]] || die "Missing value for --db-port"
      ARG_DB_PORT="$2"
      shift 2
      ;;
    --test-db-name)
      [[ $# -ge 2 ]] || die "Missing value for --test-db-name"
      ARG_TEST_DB_NAME="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "Unknown option: $1"
      ;;
  esac
done

# -------------------------------------------------------------------
# 1. Auto-detect WordPress root (find wp-config.php)
# -------------------------------------------------------------------
find_wp_root() {
  local dir="$(pwd)"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/wp-config.php" ]]; then
      echo "$dir"
      return
    fi
    dir="$(dirname "$dir")"
  done
  return 1
}

WP_ROOT="$(find_wp_root || true)"
[[ -n "${WP_ROOT:-}" ]] || die "wp-config.php not found — please run inside a WordPress installation."

WP_CONFIG="${WP_ROOT}/wp-config.php"
TESTS_DIR="/tmp/wordpress-tests-lib"
WP_CORE_DIR="/tmp/wordpress"

# -------------------------------------------------------------------
# 2. Extract DB credentials from wp-config.php (with optional overrides)
# -------------------------------------------------------------------
say "Reading DB credentials from ${WP_CONFIG}"
DB_NAME="$(grep -E "define\(\s*'DB_NAME'" "$WP_CONFIG" | sed "s/.*'DB_NAME',\s*'\([^']*\)'.*/\1/")"
DB_USER="$(grep -E "define\(\s*'DB_USER'" "$WP_CONFIG" | sed "s/.*'DB_USER',\s*'\([^']*\)'.*/\1/")"
DB_PASS="$(grep -E "define\(\s*'DB_PASSWORD'" "$WP_CONFIG" | sed "s/.*'DB_PASSWORD',\s*'\([^']*\)'.*/\1/")"
DB_HOST_RAW="$(grep -E "define\(\s*'DB_HOST'" "$WP_CONFIG" | sed "s/.*'DB_HOST',\s*'\([^']*\)'.*/\1/")"

[[ -n "$ARG_DB_NAME"     ]] && DB_NAME="$ARG_DB_NAME"
[[ -n "$ARG_DB_USER"     ]] && DB_USER="$ARG_DB_USER"
[[ -n "$ARG_DB_PASS"     ]] && DB_PASS="$ARG_DB_PASS"
[[ -n "$ARG_DB_HOST"     ]] && DB_HOST_RAW="$ARG_DB_HOST"

if [[ -z "${DB_NAME:-}" || -z "${DB_USER:-}" || -z "${DB_HOST_RAW:-}" ]]; then
  die "Missing database details. Re-run with --db-name/--db-user/--db-host overrides if wp-config.php uses env() helpers."
fi

# Split host + port
DB_HOST="${DB_HOST_RAW%%:*}"
DB_PORT_DEFAULT="${DB_HOST_RAW#*:}"
[[ "$DB_PORT_DEFAULT" == "$DB_HOST_RAW" ]] && DB_PORT_DEFAULT=""
DB_PORT="${ARG_DB_PORT:-$DB_PORT_DEFAULT}"
[[ -z "$DB_PORT" ]] && DB_PORT="3306"

DB_NAME_TEST="${ARG_TEST_DB_NAME:-${DB_NAME}_test}"
DB_PASS="${DB_PASS:-}"

say "Using:"
echo "  Host: $DB_HOST"
echo "  Port: $DB_PORT"
echo "  User: $DB_USER"
echo "  DB:   $DB_NAME_TEST"

# -------------------------------------------------------------------
# 3. Install system dependencies (svn, mysql client)
# -------------------------------------------------------------------
say "Installing required system packages..."
if [[ -f /etc/alpine-release ]]; then
  apk add --no-cache subversion mariadb-client ca-certificates curl >/dev/null
  update-ca-certificates >/dev/null 2>&1 || true
elif [[ -f /etc/debian_version ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y >/dev/null
  apt-get install -y subversion default-mysql-client curl ca-certificates >/dev/null
  update-ca-certificates >/dev/null 2>&1 || true
elif [[ -f /etc/redhat-release ]]; then
  yum install -y subversion mariadb curl ca-certificates >/dev/null
else
  warn "Unknown distro — please ensure 'svn' and 'mysql/mariadb' clients are installed."
fi

command -v svn >/dev/null || die "svn command not found"

# -------------------------------------------------------------------
# 4. Prepare WordPress Core and Tests
# -------------------------------------------------------------------
say "Downloading latest WordPress core..."
mkdir -p "$WP_CORE_DIR"
curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/wordpress.tar.gz
tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

say "Fetching WordPress test suite..."
rm -rf "$TESTS_DIR"
mkdir -p "$TESTS_DIR"
svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes "$TESTS_DIR/includes"
svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data "$TESTS_DIR/data"

say "Creating wp-tests-config.php..."
curl -fsSL https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php -o "${TESTS_DIR}/wp-tests-config.php"

sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "${TESTS_DIR}/wp-tests-config.php" || true
sed -i "s:__DIR__ . '/src/':'${WP_CORE_DIR}/':" "${TESTS_DIR}/wp-tests-config.php" || true

DB_HOST_VALUE="${DB_HOST}:${DB_PORT}"
sed -i "s/youremptytestdbnamehere/${DB_NAME_TEST}/" "${TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourusernamehere/${DB_USER}/" "${TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${DB_PASS}/" "${TESTS_DIR}/wp-tests-config.php"
sed -i "s|localhost|${DB_HOST_VALUE}|" "${TESTS_DIR}/wp-tests-config.php"

# -------------------------------------------------------------------
# 5. Create the test database
# -------------------------------------------------------------------
say "Creating test database '${DB_NAME_TEST}'..."
MYSQL_BIN="mariadb"
command -v "${MYSQL_BIN}" >/dev/null 2>&1 || MYSQL_BIN="mysql"
command -v "${MYSQL_BIN}" >/dev/null 2>&1 || die "Neither 'mariadb' nor 'mysql' client found."

MYSQL_CMD=("${MYSQL_BIN}" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER")
[[ -n "$DB_PASS" ]] && MYSQL_CMD+=(-p"$DB_PASS")

"${MYSQL_CMD[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME_TEST\`; CREATE DATABASE \`$DB_NAME_TEST\`;"

# -------------------------------------------------------------------
# 6. Verify dependencies (PHPUnit from the PLUGIN, not WP root)
# -------------------------------------------------------------------
say "Checking for local PHPUnit installation..."
PHPUNIT_BIN="$PLUGIN_DIR/vendor/bin/phpunit"
if [[ ! -x "$PHPUNIT_BIN" ]]; then
  # Fallbacks: WP root vendor, current dir vendor, or PATH
  if [[ -x "$WP_ROOT/vendor/bin/phpunit" ]]; then
    PHPUNIT_BIN="$WP_ROOT/vendor/bin/phpunit"
  elif [[ -x "vendor/bin/phpunit" ]]; then
    PHPUNIT_BIN="vendor/bin/phpunit"
  else
    PHPUNIT_BIN="$(command -v phpunit || true)"
  fi
fi

[[ -n "${PHPUNIT_BIN:-}" && -x "$PHPUNIT_BIN" ]] || die "vendor/bin/phpunit not found in plugin or project. Run 'composer install' in ${PLUGIN_DIR}."

# Yoast polyfills check relative to the plugin (tests usually depend on it)
if [[ ! -d "${PLUGIN_DIR}/vendor/yoast/phpunit-polyfills" ]]; then
  warn "Yoast PHPUnit Polyfills not found in plugin vendor."
  warn "If tests complain, install with:"
  echo "    (cd \"$PLUGIN_DIR\" && composer require --dev yoast/phpunit-polyfills:^3.1)"
fi

# -------------------------------------------------------------------
# 7. Run PHPUnit from the plugin directory
# -------------------------------------------------------------------
say "Running PHPUnit (from: $PHPUNIT_BIN) with plugin config"
pushd "$PLUGIN_DIR" >/dev/null
"$PHPUNIT_BIN" --configuration "$PLUGIN_DIR/phpunit.xml.dist" --testdox
popd >/dev/null

say "✅ WordPress PHPUnit environment ready!"
