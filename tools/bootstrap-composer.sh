#!/usr/bin/env bash
set -euo pipefail

VERSION="2.10.3"
EXPECTED_SHA256="7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$ROOT/composer.phar"
URL="https://getcomposer.org/download/${VERSION}/composer.phar"

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required" >&2
  exit 2
fi

curl --fail --location --proto '=https' --tlsv1.2 --silent --show-error "$URL" -o "$TARGET"
ACTUAL_SHA256="$(sha256sum "$TARGET" | awk '{print $1}')"
if [[ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]]; then
  rm -f "$TARGET"
  echo "Composer checksum mismatch" >&2
  exit 3
fi

chmod 0755 "$TARGET"
php "$TARGET" --version
