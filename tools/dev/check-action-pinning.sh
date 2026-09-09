#!/usr/bin/env bash
# ============================================================
# ZEF Framework -- check-action-pinning.sh (FU-3)
#
# Memeriksa seluruh workflow CI di .github/workflows/ agar:
#   1) SEMUA uses: pihak ketiga dipin ke commit SHA 40-hex
#   2) pin SHA disertai komentar "# <tag>" pada baris yang sama
#   3) tidak ada referensi tag generik yang lolos
#
# Policy: supply-chain/action-pinning-policy.md
# Exit code: 0 = PASS, 1 = FAIL (temuan), 2 = error penggunaan
# ============================================================
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW_DIR="$ROOT/.github/workflows"
LIST_ONLY=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --list) LIST_ONLY=1; shift ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "ERROR: unknown argument '$1'" >&2; exit 2 ;;
  esac
done

[[ -d "$WORKFLOW_DIR" ]] || { echo "ERROR: $WORKFLOW_DIR not found" >&2; exit 2; }

fail=0
count_ok=0
count_pin=0

# Ekspresi: baris berisi `uses: owner/repo@ref` (ref bisa SHA atau tag)
while IFS= read -r hit; do
  file="${hit%%:*}"
  line="${hit#*:}"; line="${line%%:*}"
  [[ -n "$file" && -n "$line" ]] || continue
  text="$(sed -n "${line}p" "$file")"
  # ekstrak owner/repo@ref
  ref="$(printf '%s' "$text" | grep -oE 'uses: [A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+@[^ #}]+' | head -1 | sed 's/uses: //')"
  [[ -n "$ref" ]] || continue
  repo="${ref%@*}"
  tag="${ref##*@}"
  if [[ "$LIST_ONLY" -eq 1 ]]; then
    printf '%-60s %s@%s\n' "$(basename "$file"):$line" "$repo" "$tag"
    continue
  fi
  # Apakah ref berupa SHA 40 hex?
  if [[ "$tag" =~ ^[0-9a-f]{40}$ ]]; then
    count_pin=$((count_pin+1))
    # WAJIB ada komentar # tag di baris yang sama
    if ! printf '%s' "$text" | grep -qE '# v?[0-9][A-Za-z0-9._-]*'; then
      echo "FAIL: $file:$line — pinned SHA tanpa komentar tag: $repo@$tag" >&2
      fail=1
    else
      count_ok=$((count_ok+1))
    fi
  else
    echo "FAIL: $file:$line — action TIDAK dipin ke SHA (tag generik '@$tag'): $repo" >&2
    fail=1
  fi
done < <(grep -nE 'uses: [A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+@' "$WORKFLOW_DIR"/*.yml)

if [[ "$LIST_ONLY" -eq 1 ]]; then
  exit 0
fi

echo "action_pinning_check=$([ $fail -eq 0 ] && echo PASS || echo FAIL)"
echo "pinned_actions=$count_pin"
echo "pinned_with_tag_comment=$count_ok"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
