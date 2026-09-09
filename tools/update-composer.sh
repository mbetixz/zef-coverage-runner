#!/usr/bin/env bash
set -euo pipefail

COMPOSER_VERSION="2.10.3"
EXPECTED_SHA256="7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD=(composer)
elif [[ -f composer.phar ]]; then
  COMPOSER_CMD=(php composer.phar)
else
  echo "Composer is required. Install Composer ${COMPOSER_VERSION} first." >&2
  exit 2
fi

"${COMPOSER_CMD[@]}" --version
"${COMPOSER_CMD[@]}" update phpstan/phpstan --with-all-dependencies --prefer-dist --no-interaction
"${COMPOSER_CMD[@]}" validate --strict
"${COMPOSER_CMD[@]}" audit --locked --no-interaction
"${COMPOSER_CMD[@]}" install --prefer-dist --no-interaction --no-progress
