#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RR_BIN="${ZEF_RR_BIN:-$ROOT/rr}"
OUTPUT_DIR="${ZEF_RUNTIME_OUTPUT_DIR:-$ROOT/dist/runtime-bundle}"
STAGE="$(mktemp -d -t zef-runtime-bundle.XXXXXX)"
trap 'rm -rf "$STAGE"' EXIT

[[ -x "$RR_BIN" ]] || { echo "RoadRunner binary not found or not executable: $RR_BIN" >&2; exit 1; }
for config in .rr.yaml .rr.g3.yaml .rr.endurance.yaml; do
  [[ -f "$ROOT/$config" ]] || { echo "Missing RoadRunner config: $config" >&2; exit 1; }
done

mkdir -p "$STAGE/bin" "$STAGE/config/roadrunner" "$OUTPUT_DIR"
cp -p "$RR_BIN" "$STAGE/bin/rr"
cp -p "$ROOT"/.rr.yaml "$ROOT"/.rr.g3.yaml "$ROOT"/.rr.endurance.yaml "$STAGE/config/roadrunner/"
printf '%s\n' \
  'ZEF runtime bundle' \
  "php_version=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)" \
  "roadrunner_version=$($RR_BIN --version 2>/dev/null | head -n 1 || $RR_BIN version 2>/dev/null | head -n 1 || echo unknown)" \
  'source_is_external=true' \
  'binary_is_not_part_of_source_package=true' > "$STAGE/README.runtime.txt"
( cd "$STAGE" && find . -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt )
archive="$OUTPUT_DIR/zef-runtime-bundle.zip"
rm -f "$archive"
( cd "$STAGE" && zip -qr "$archive" . )
unzip -t "$archive" >/dev/null
printf 'runtime_bundle=%s\n' "$archive"
printf 'runtime_bundle_sha256='; sha256sum "$archive" | awk '{print $1}'
printf 'binary_sha256='; sha256sum "$STAGE/bin/rr" | awk '{print $1}'
