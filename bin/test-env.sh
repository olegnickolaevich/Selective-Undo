#!/usr/bin/env bash
# Creates a disposable WordPress site with this plugin active, for integration tests.
# Requires Docker, PHP 8.2+ (mysqli) and network access to Docker Hub (first run).
#
#   bin/test-env.sh up      start database, install a fresh site, activate the plugin
#   bin/test-env.sh down    remove the database container
#   bin/test-env.sh wp ...  run WP-CLI against the test site
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$ROOT/.work"
SITE="$WORK/site"
DB_CONTAINER="${SU_DB_CONTAINER:-sundo-test-db}"
DB_IMAGE="${SU_DB_IMAGE:-mysql:8.4}"
DB_PORT="${SU_DB_PORT:-33080}"
WP_IMAGE="${SU_WP_IMAGE:-wordpress:latest}"
WPCLI="$WORK/wp-cli.phar"

mkdir -p "$WORK"

wp() { php "$WPCLI" --path="$SITE" --allow-root "$@"; }

db_client() { [[ "$DB_IMAGE" == mariadb* ]] && echo mariadb || echo mysql; }

start_db() {
  if ! docker ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
    docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
    docker run -d --name "$DB_CONTAINER" -e MYSQL_ROOT_PASSWORD=root -e MARIADB_ROOT_PASSWORD=root \
      -p "$DB_PORT:3306" "$DB_IMAGE" >/dev/null
  fi
  local client; client="$(db_client)"
  for _ in $(seq 1 90); do
    docker exec "$DB_CONTAINER" "$client" -uroot -proot -e 'SELECT 1' >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "Database did not start" >&2; exit 1
}

ensure_wordpress() {
  if [ ! -f "$WORK/wordpress/wp-includes/version.php" ]; then
    docker image inspect "$WP_IMAGE" >/dev/null 2>&1 || docker pull -q "$WP_IMAGE" >/dev/null
    local cid; cid="$(docker create "$WP_IMAGE")"
    docker cp "$cid:/usr/src/wordpress" "$WORK/wordpress" >/dev/null
    docker rm "$cid" >/dev/null
  fi
  if [ ! -f "$WPCLI" ]; then
    curl -sSL -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  fi
}

install_site() {
  docker exec "$DB_CONTAINER" "$(db_client)" -uroot -proot -e 'DROP DATABASE IF EXISTS wptest; CREATE DATABASE wptest CHARACTER SET utf8mb4' 2>/dev/null
  rm -rf "$SITE" && cp -r "$WORK/wordpress" "$SITE"
  cat > "$SITE/wp-config.php" <<PHP
<?php
define('DB_NAME', 'wptest');
define('DB_USER', 'root');
define('DB_PASSWORD', 'root');
define('DB_HOST', '127.0.0.1:$DB_PORT');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
\$table_prefix = 'wp_';
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', '$WORK/debug.log');
define('DISABLE_WP_CRON', true);
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as \$k) { define(\$k, 'test-' . \$k); }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
PHP
  wp core install --url=http://127.0.0.1:8899 --title='Selective Undo Test' --admin_user=admin \
    --admin_password=password --admin_email=admin@example.test --skip-email >/dev/null
  # wp_install() guesses the URL from the CLI path; pin it for the web server used by E2E tests.
  wp option update siteurl http://127.0.0.1:8899 >/dev/null
  wp option update home http://127.0.0.1:8899 >/dev/null
  ln -sfn "$ROOT" "$SITE/wp-content/plugins/selective-undo"
  wp user create editor editor@example.test --role=editor --user_pass=password >/dev/null
  wp user create author author@example.test --role=author --user_pass=password >/dev/null
  wp plugin activate selective-undo >/dev/null
  # Install bundled translations the way WordPress.org language packs are installed.
  SU_WPCLI="$WPCLI" "$ROOT/bin/i18n.sh" install-test-site >/dev/null
  echo "Site ready: $SITE (WordPress $(wp core version), DB $DB_IMAGE on port $DB_PORT)"
}

case "${1:-up}" in
  up) start_db; ensure_wordpress; install_site ;;
  down) docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true ;;
  wp) shift; wp "$@" ;;
  *) echo "usage: $0 up|down|wp ..." >&2; exit 2 ;;
esac
