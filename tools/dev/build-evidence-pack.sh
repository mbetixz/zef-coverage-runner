#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTPUT_DIR="${ZEF_EVIDENCE_OUTPUT_DIR:-$ROOT/dist/evidence-pack}"
STAGE="$(mktemp -d -t zef-evidence-pack.XXXXXX)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/documentation" "$STAGE/manifests" "$STAGE/verification" "$OUTPUT_DIR"
cp -a "$ROOT/documentation/." "$STAGE/documentation/"
cp -f "$ROOT/composer.json" "$ROOT/composer.lock" "$ROOT/phpstan.neon.dist" "$STAGE/manifests/"
cp -f "$ROOT/supply-chain"/*.json "$STAGE/manifests/" 2>/dev/null || true
cp -f /home/ubuntu/zef_baseline_cleanup/enterprise-lineage.SHA256SUMS "$STAGE/manifests/" 2>/dev/null || true
cp -f /home/ubuntu/zef_enterprise/phase2-verification.log "$STAGE/verification/" 2>/dev/null || true
cp -f /home/ubuntu/zef_enterprise/final-clean-release.log "$STAGE/verification/" 2>/dev/null || true
cp -f /home/ubuntu/zef_router_refactor/final-regression.log "$STAGE/verification/" 2>/dev/null || true
cp -f /home/ubuntu/zef_router_refactor/rollback-verification.log "$STAGE/verification/" 2>/dev/null || true
cp -f /home/ubuntu/zef_enterprise/static-policy.log "$STAGE/verification/" 2>/dev/null || true

# Do not include the application vendor directory, runtime binary, cache, or arbitrary host files.
if find "$STAGE" -type f \( -path '*/vendor/*' -o -path '*/.zef/*' -o -name '*.cache' -o -name '*.pid' \) -print -quit | grep -q .; then
  echo 'FAIL: forbidden evidence artifact found.' >&2
  exit 1
fi
( cd "$STAGE" && find . -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt )
archive="$OUTPUT_DIR/zef-enterprise-evidence-pack.zip"
rm -f "$archive"
( cd "$STAGE" && zip -qr "$archive" . )
unzip -t "$archive" >/dev/null
printf 'evidence_pack=%s\n' "$archive"
printf 'evidence_pack_sha256='; sha256sum "$archive" | awk '{print $1}'
printf 'evidence_pack_files='; unzip -Z1 "$archive" | wc -l
