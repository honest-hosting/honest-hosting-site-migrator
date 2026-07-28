#!/bin/bash
#
# Provision a WordPress install at container start, then hand off to Apache.
#
# Idempotent: on restart it detects an existing install and skips straight to exec.
#
set -euo pipefail

WP_VERSION="${WP_VERSION:-6.9.5}"
WP_PATH="/var/www/html"
PLUGIN_SLUG="honest-hosting-site-migrator"

# Extra wordpress.org plugins installed and activated on every cell, space-separated slugs.
# Default is Plugin Check (PCP), the WordPress.org review team's pre-submission checker --
# it requires PHP 7.4 / WP 6.3, so it runs on every cell in the matrix including the floor.
WP_EXTRA_PLUGINS="${WP_EXTRA_PLUGINS:-plugin-check}"

# wp-cli needs an unbounded memory_limit to extract core: at the stock 128M,
# `core download` dies inside Extractor.php. This applies ONLY to wp-cli invocations --
# the request-path php.ini below stays deliberately constrained.
WP="php -d memory_limit=-1 /usr/local/bin/wp-cli.phar --allow-root --path=${WP_PATH}"

# ---------------------------------------------------------------------------
# Request-path PHP limits.
#
# Kept low on purpose. The migrator targets shared hosting with modest limits, and its
# chunked/streamed export exists precisely to cope with them -- so the matrix should
# reproduce those conditions rather than paper over them.
# ---------------------------------------------------------------------------
cat > /usr/local/etc/php/conf.d/zz-localdev.ini <<INI
memory_limit = ${PHP_MEMORY_LIMIT:-128M}
max_execution_time = ${PHP_MAX_EXECUTION_TIME:-30}
upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE:-8M}
post_max_size = ${PHP_POST_MAX_SIZE:-8M}
display_errors = On
error_reporting = E_ALL
log_errors = On
error_log = /var/www/html/wp-content/debug.log
INI

echo "[provision] PHP $(php -r 'echo PHP_VERSION;') | target WordPress ${WP_VERSION}"

# ---------------------------------------------------------------------------
# Make Apache listen on the SAME port the site is published on.
#
# This is load-bearing for WP-Cron, not cosmetic. WordPress backgrounds work by calling
# spawn_cron(), which issues a loopback HTTP request to site_url('wp-cron.php'). If the site
# port exists only as a host-side publish mapping (e.g. -p 10074:80 with Apache on 80), that
# loopback resolves to a port nothing is listening on *inside* the container:
#
#   cURL error 7: Failed to connect to localhost port 10074: Connection refused
#
# WP-Cron then never fires. Scheduled jobs queue up as "due now" forever and every
# backgrounded migration silently stalls immediately after logging that it started --
# with no PHP error anywhere, because nothing actually failed.
#
# Listening on the published port makes site_url() valid both inside and outside the
# container, which also matches how a real host behaves.
# ---------------------------------------------------------------------------
SITE_PORT="$(printf '%s' "${WP_SITE_URL:-}" | sed -nE 's|^https?://[^/:]+:([0-9]+).*$|\1|p')"
SITE_PORT="${SITE_PORT:-80}"

if [ "${SITE_PORT}" != "80" ]; then
    sed -i -E "s/^Listen 80$/Listen ${SITE_PORT}/" /etc/apache2/ports.conf
    sed -i -E "s/<VirtualHost \*:80>/<VirtualHost *:${SITE_PORT}>/" \
        /etc/apache2/sites-available/000-default.conf
    echo "[provision] Apache listening on ${SITE_PORT} (matches WP_SITE_URL, so cron loopback works)"
fi

# ---------------------------------------------------------------------------
# Wait for the database.
# ---------------------------------------------------------------------------
# Probed via PHP/mysqli, NOT mysqladmin: the official wordpress images ship no mysql client
# binaries, so a mysqladmin-based loop can never succeed and just burns its full timeout
# before falling through. mysqli is always present.
DB_HOST="${WORDPRESS_DB_HOST%%:*}"
DB_PORT="${WORDPRESS_DB_HOST##*:}"
[ "${DB_PORT}" = "${DB_HOST}" ] && DB_PORT=3306

echo "[provision] waiting for database at ${DB_HOST}:${DB_PORT}..."
db_ready=0
for i in $(seq 1 60); do
    if php -r '
        $m = @new mysqli($argv[1], $argv[2], $argv[3], "", (int) $argv[4]);
        exit( $m->connect_errno ? 1 : 0 );
    ' "${DB_HOST}" "${WORDPRESS_DB_USER}" "${WORDPRESS_DB_PASSWORD}" "${DB_PORT}" 2>/dev/null; then
        echo "[provision] database is up (after ${i} attempt(s))"
        db_ready=1
        break
    fi
    sleep 2
done

if [ "${db_ready}" -ne 1 ]; then
    echo "[provision] ERROR: database never became reachable at ${DB_HOST}:${DB_PORT}" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Install WordPress (first boot only).
# ---------------------------------------------------------------------------
if [ ! -f "${WP_PATH}/wp-includes/version.php" ]; then
    echo "[provision] downloading WordPress ${WP_VERSION}..."
    $WP core download --version="${WP_VERSION}"

    echo "[provision] writing wp-config.php..."
    $WP config create \
        --dbhost="${WORDPRESS_DB_HOST}" \
        --dbname="${WORDPRESS_DB_NAME}" \
        --dbuser="${WORDPRESS_DB_USER}" \
        --dbpass="${WORDPRESS_DB_PASSWORD}" \
        --skip-check \
        --extra-php <<'PHPEXTRA'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'FS_METHOD', 'direct' );
PHPEXTRA

    echo "[provision] installing site at ${WP_SITE_URL}..."
    $WP core install \
        --url="${WP_SITE_URL}" \
        --title="HH Migrator - PHP $(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" \
        --admin_user="${WP_ADMIN_USER:-administrator}" \
        --admin_password="${WP_ADMIN_PASSWORD:-dead-beef}" \
        --admin_email="${WP_ADMIN_EMAIL:-dev@honesthosting.io}" \
        --skip-email
else
    echo "[provision] existing WordPress install detected, skipping setup"
fi

# ---------------------------------------------------------------------------
# Activate the bind-mounted plugin.
#
# Not fatal on failure: a deliberate goal of this matrix is observing HOW the plugin fails
# on an unsupported runtime, so a refusal here should surface as a message rather than
# taking the whole container down.
# ---------------------------------------------------------------------------
if [ -f "${WP_PATH}/wp-content/plugins/${PLUGIN_SLUG}/${PLUGIN_SLUG}.php" ]; then
    if $WP plugin activate "${PLUGIN_SLUG}" 2>&1; then
        echo "[provision] plugin activated"
    else
        echo "[provision] WARNING: plugin activation FAILED (see above) -- container still starting"
    fi
else
    echo "[provision] WARNING: plugin not found at wp-content/plugins/${PLUGIN_SLUG} -- run 'make build' first"
fi

# ---------------------------------------------------------------------------
# Extra wordpress.org plugins (Plugin Check by default).
#
# Idempotent, and re-run on every boot rather than only on first install: wp-content/plugins
# lives inside the container (only our own plugin is bind-mounted), so these are lost whenever
# the container is recreated, and this also restores anything deactivated by hand.
#
# Non-fatal by design -- these are fetched from wordpress.org, and a network blip should not
# take down a cell whose actual purpose is testing our plugin.
# ---------------------------------------------------------------------------
for extra_plugin in ${WP_EXTRA_PLUGINS}; do
    [ -z "${extra_plugin}" ] && continue

    if $WP plugin is-installed "${extra_plugin}" >/dev/null 2>&1; then
        $WP plugin activate "${extra_plugin}" >/dev/null 2>&1 \
            && echo "[provision] ${extra_plugin}: already installed, activated" \
            || echo "[provision] WARNING: ${extra_plugin} installed but activation failed"
    elif $WP plugin install "${extra_plugin}" --activate >/dev/null 2>&1; then
        echo "[provision] ${extra_plugin}: installed and activated ($($WP plugin get "${extra_plugin}" --field=version 2>/dev/null))"
    else
        echo "[provision] WARNING: could not install ${extra_plugin} (network? PHP/WP requirement?) -- continuing"
    fi
done

# ---------------------------------------------------------------------------
# Ownership.
#
# Scoped to core + uploads, NOT the plugin directory: that is a bind mount, and chown -R
# across it would rewrite ownership of the files on the host.
# ---------------------------------------------------------------------------
mkdir -p "${WP_PATH}/wp-content/uploads"
chown -R www-data:www-data \
    "${WP_PATH}/wp-admin" \
    "${WP_PATH}/wp-includes" \
    "${WP_PATH}/wp-content/uploads" \
    "${WP_PATH}/wp-content/themes" 2>/dev/null || true
chown www-data:www-data "${WP_PATH}"/*.php 2>/dev/null || true
touch "${WP_PATH}/wp-content/debug.log" && chown www-data:www-data "${WP_PATH}/wp-content/debug.log"

echo "[provision] ready -> ${WP_SITE_URL}  (user: ${WP_ADMIN_USER:-administrator} / ${WP_ADMIN_PASSWORD:-dead-beef})"

exec "$@"
