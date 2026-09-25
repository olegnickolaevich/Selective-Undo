#!/usr/bin/env bash
# Serves the test site on http://127.0.0.1:8899 with PHP's built-in server.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -S 127.0.0.1:"${SU_E2E_PORT:-8899}" -t "$ROOT/.work/site"
