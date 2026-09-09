#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TOOLCHAIN_DIR="${ZEF_TOOLCHAIN_DIR:-$ROOT/.zef/toolchain}"
ZEF_DIR="$ROOT/.zef"

case "$TOOLCHAIN_DIR" in
  "$ROOT"/.zef/toolchain) ;;
  *) echo "Refusing to remove path outside project toolchain: $TOOLCHAIN_DIR" >&2; exit 2 ;;
esac

if [[ ! -d "$TOOLCHAIN_DIR" ]]; then
  echo "Toolchain is already absent: $TOOLCHAIN_DIR"
  exit 0
fi

# Remove only the directory created by install-toolchain.sh. Never use a caller-
# supplied arbitrary path, and never remove the project's normal vendor directory.
rm -rf -- "$TOOLCHAIN_DIR"

# Remove the parent marker only when it is empty; preserve unrelated project data.
if [[ -d "$ZEF_DIR" ]] && [[ -z "$(find "$ZEF_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
  rmdir "$ZEF_DIR"
fi

echo "Toolchain removed. Project vendor/, source/, tests/, and baseline files were not touched."
