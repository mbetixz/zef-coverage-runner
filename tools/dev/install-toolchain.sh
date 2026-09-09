#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TOOLCHAIN_DIR="${ZEF_TOOLCHAIN_DIR:-$ROOT/.zef/toolchain}"
COMPOSER_HOME_DIR="$TOOLCHAIN_DIR/composer-home"
COMPOSER_PROJECT="$TOOLCHAIN_DIR/composer-project"
BIN_DIR="$TOOLCHAIN_DIR/bin"
MANIFEST="$TOOLCHAIN_DIR/manifest.env"

RectorVersion="${ZEF_RECTOR_VERSION:-^2.0}"
CsFixerVersion="${ZEF_CS_FIXER_VERSION:-^3.70}"
DeptracVersion="${ZEF_DEPTRAC_VERSION:-^4.0}"

case "$TOOLCHAIN_DIR" in
  "$ROOT"/.zef/toolchain|"$ROOT"/.zef/toolchain/*) ;;
  *) echo "Refusing toolchain path outside project .zef/: $TOOLCHAIN_DIR" >&2; exit 2 ;;
esac

command -v php >/dev/null || { echo 'PHP is required.' >&2; exit 1; }
command -v composer >/dev/null || { echo 'Composer is required.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo 'PHP >= 8.4 is required.' >&2; exit 1; }

mkdir -p "$COMPOSER_HOME_DIR" "$COMPOSER_PROJECT" "$BIN_DIR"
cat > "$COMPOSER_PROJECT/composer.json" <<JSON
{
  "name": "zef/toolchain",
  "private": true,
  "require-dev": {
    "rector/rector": "$RectorVersion",
    "friendsofphp/php-cs-fixer": "$CsFixerVersion",
    "deptrac/deptrac": "$DeptracVersion"
  },
  "config": {
    "preferred-install": {"*": "dist"},
    "sort-packages": true,
    "allow-plugins": {}
  }
}
JSON

COMPOSER_HOME="$COMPOSER_HOME_DIR" composer install \
  --working-dir="$COMPOSER_PROJECT" \
  --no-interaction --no-progress --prefer-dist --no-scripts

for tool in rector php-cs-fixer deptrac; do
  source="$COMPOSER_PROJECT/vendor/bin/$tool"
  if [[ -e "$source" ]]; then
    ln -sfn "$source" "$BIN_DIR/$tool"
  fi
done

# Supply-chain tools are intentionally opt-in and pinned by the caller. The installer
# never downloads an unpinned latest binary. Use install-supply-chain.sh with explicit
# versions/checksums when those tools are required.
cat > "$MANIFEST" <<EOF
ZEF_TOOLCHAIN_ROOT=$TOOLCHAIN_DIR
ZEF_COMPOSER_PROJECT=$COMPOSER_PROJECT
ZEF_COMPOSER_HOME=$COMPOSER_HOME_DIR
ZEF_TOOLCHAIN_BIN=$BIN_DIR
ZEF_RECTOR_VERSION=$RectorVersion
ZEF_CS_FIXER_VERSION=$CsFixerVersion
ZEF_DEPTRAC_VERSION=$DeptracVersion
EOF
chmod 600 "$MANIFEST"

echo "Toolchain installed under $TOOLCHAIN_DIR"
for tool in rector php-cs-fixer deptrac; do
  if [[ -x "$BIN_DIR/$tool" || -e "$BIN_DIR/$tool" ]]; then
    printf '%s: ' "$tool"
    "$BIN_DIR/$tool" --version 2>/dev/null | head -n 1 || true
  fi
done
printf '%s\n' "Manifest: $MANIFEST" "Remove with: tools/dev/uninstall-toolchain.sh"
