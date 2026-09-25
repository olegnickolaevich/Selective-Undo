#!/usr/bin/env bash
# Serves the test site on http://127.0.0.1:8899 with PHP's built-in server.
#
#   SU_TEST_SITE=/path SU_E2E_PORT=8968   serve another site created by bin/test-env.sh
#   SU_PHP_IMAGE=wordpress:6.8-php8.2-apache   use the PHP of a Docker image instead of the local one
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="${SU_TEST_SITE:-$ROOT/.work/site}"
PORT="${SU_E2E_PORT:-8899}"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

if [ -n "${SU_PHP_IMAGE:-}" ]; then
  # Same paths inside the container, so that symlinks and paths in wp-config.php resolve.
  exec docker run --rm --network host -v "$SITE:$SITE" -v "$ROOT:$ROOT" -w "$SITE" \
    -e PHP_CLI_SERVER_WORKERS --entrypoint php "$SU_PHP_IMAGE" -S 127.0.0.1:"$PORT" -t "$SITE"
fi

exec php -S 127.0.0.1:"$PORT" -t "$SITE"
