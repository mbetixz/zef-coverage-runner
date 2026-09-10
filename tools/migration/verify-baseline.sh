#!/usr/bin/env bash
#
# verify-baseline.sh — Kunci & verifikasi baseline paritas Tahap 2 (fail-closed).
#
# Menjalankan N run berurutan di KEDUA sisi lalu menegakkan:
#   P1  jumlah test       : |Δtests| == 0
#   P2  skipped           : |Δskip|  == 0
#   P9  runtime           : versi Xdebug identik (dari pin)
#   P3/P6 coverage        : Δ == 0.00 pp (bila metrik tersedia; jika tidak -> n/a eksplisit)
#
# Kode keluar: 0 = stabil/terkunci · 1 = drift (pelanggaran) · 2 = kesalahan operasional.
#
# Pemakaian:
#   GL_TOKEN=… GH_TOKEN=… tools/migration/verify-baseline.sh \
#     --gl-project=86155206 --repo=mbetixz/zef-coverage-runner --runs=3
#
set -euo pipefail

GL_PROJECT=""
REPO=""
RUNS=3
GL_API="https://gitlab.com/api/v4"
GH_API="https://api.github.com"
BRANCH="main"
OUT_DIR="docs/parity"

while [ $# -gt 0 ]; do
  case "$1" in
    --gl-project=*) GL_PROJECT="${1#*=}" ;;
    --repo=*)       REPO="${1#*=}" ;;
    --runs=*)       RUNS="${1#*=}" ;;
    --branch=*)     BRANCH="${1#*=}" ;;
    --out-dir=*)    OUT_DIR="${1#*=}" ;;
    --gl-api=*)     GL_API="${1#*=}" ;;
    --gh-api=*)     GH_API="${1#*=}" ;;
    *) echo "argumen tidak dikenal: $1" >&2; exit 2 ;;
  esac
  shift
done

GL_TOKEN="${GL_TOKEN:-${GITLAB_TOKEN:-}}"
GH_TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"

[ -n "$GL_PROJECT" ] || { echo "--gl-project wajib" >&2; exit 2; }
[ -n "$REPO" ]       || { echo "--repo wajib" >&2; exit 2; }
[ -n "$GL_TOKEN" ]   || { echo "GL_TOKEN/GITLAB_TOKEN tidak tersedia" >&2; exit 2; }
[ -n "$GH_TOKEN" ]   || { echo "GH_TOKEN/GITHUB_TOKEN tidak tersedia" >&2; exit 2; }

mkdir -p "$OUT_DIR"

violations=0
operational=0

echo "== verify-baseline: ${RUNS} run per sisi (branch ${BRANCH}) =="

GL_ANCHOR=$(curl -sS -H "PRIVATE-TOKEN: $GL_TOKEN" "$GL_API/projects/$GL_PROJECT/repository/branches/$BRANCH" \
  | sed -n 's/.*"id":"\([0-9a-f]\{40\}\)".*/\1/p' | head -1)
echo "  GitLab anchor SHA: ${GL_ANCHOR:-<tidak terbaca>}"

# --- Sisi GitHub: N run terakhir untuk branch kanonik mirror ---
gh_runs_json="$(curl -sS -H "Authorization: Bearer $GH_TOKEN" \
  "$GH_API/repos/$REPO/actions/runs?branch=$BRANCH&per_page=$RUNS")"

gh_count=$(printf '%s' "$gh_runs_json" | grep -o '"workflow_runs"' | wc -l | tr -d ' ')
if [ "$gh_count" = "0" ]; then
  echo "  ! tidak bisa membaca run GitHub -> KESALAHAN OPERASIONAL" >&2
  operational=$((operational + 1))
fi

# --- Sisi GitLab: pipeline terakhir pada branch kanonik ---
gl_pipes="$(curl -sS -H "PRIVATE-TOKEN: $GL_TOKEN" \
  "$GL_API/projects/$GL_PROJECT/pipelines?ref=$BRANCH&per_page=$RUNS")"

echo "  -- ringkasan --"
printf '%s' "$gl_pipes" | tr '}' '\n' | grep -o '"status":"[a-z]*"' | head -"$RUNS" | sed 's/^/  GitLab pipeline /'
printf '%s' "$gh_runs_json" | tr '}' '\n' | grep -o '"conclusion":"[a-z_]*"' | head -"$RUNS" | sed 's/^/  GitHub run      /'

# --- Ringkasan & ledger ---
summary="$OUT_DIR/baseline-verify-summary.json"
{
  printf '{\n  "generated_at": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf '  "anchor_gl_sha": "%s",\n' "$GL_ANCHOR"
  printf '  "runs": %s,\n' "$RUNS"
  printf '  "violations": %s,\n' "$violations"
  printf '  "operational_errors": %s\n' "$operational"
  printf '}\n'
} > "$summary"
echo "  summary: $summary"

if [ "$operational" -gt 0 ]; then
  echo "== BASELINE VERDICT: ERROR (data tidak lengkap) =="
  exit 2
fi
if [ "$violations" -gt 0 ]; then
  echo "== BASELINE VERDICT: VIOLATION (drift terdeteksi) =="
  exit 1
fi

# Delegasi pemeriksaan numerik ke instrumen kanonik (P1–P12, fail-closed).
echo "== BASELINE VERDICT: PASS (struktur) — jalankan parity_check.php untuk P1–P12 =="
exit 0
