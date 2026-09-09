#!/usr/bin/env bash
# ============================================================
# ZEF Framework -- run-supply-chain-scan.sh (hardened, A2 follow-up / FU-2)
#
# Actual clean-workspace vulnerability scan (Syft SBOM -> Grype).
# Memperbaiki kelemahan versi A2 (run-supply-chain-scan.sh.baseline-v2):
#   FU-2.1 default target = CLEAN SOURCE staging/archive (bukan full
#         working tree yang terkontaminasi binary toolchain: syft/grype/rr
#         ikut ter-scan -> 41 High noise self-scan, terdokumentasi di
#         FASE_A2_EXECUTION_REPORT.md 5.1)
#   FU-2.2 opsi eksplisit --target working-tree untuk scan tree kerja
#   FU-2.3 exclude tambahan: .zef, rr, vendor, var (semua mode)
#   FU-2.4 tolak self-scan working tree TANPA flag eksplisit
#   FU-2.5 catat grype DB provenance + timestamp UTC ke output (FU-1)
#
# Mode:
#   default           : scan clean source (arsip hasil clean-release.sh
#                       atau staging dir) -- EVIDENCE RESMI
#   --target working-tree : scan penuh working tree (KHUSUS debug/audit;
#                       menolak jika binary scanner ada di dalam target
#                       dan flag --allow-self-scan tidak diberikan)
#
# Idempoten & deterministik: tidak pernah menghapus dari ROOT.
# ============================================================
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
POLICY="$ROOT/supply-chain/vulnerability-policy.json"
SUPPLY_CHAIN_DIR="${ZEF_SUPPLY_CHAIN_DIR:-$ROOT/.zef/supply-chain}"
SYFT="$SUPPLY_CHAIN_DIR/bin/syft"
GRYPE="$SUPPLY_CHAIN_DIR/bin/grype"

# --- Argumen --------------------------------------------------------
TARGET_MODE="clean-source"
ALLOW_SELF_SCAN=0
CLEAN_SOURCE_ARCHIVE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --target)
      [[ $# -ge 2 ]] || { echo "ERROR: --target requires a value (clean-source|working-tree)" >&2; exit 2; }
      case "$2" in
        clean-source|working-tree) TARGET_MODE="$2" ;;
        *) echo "ERROR: unknown target '$2' (clean-source|working-tree)" >&2; exit 2 ;;
      esac
      shift 2 ;;
    --archive)
      [[ $# -ge 2 ]] || { echo "ERROR: --archive requires a path" >&2; exit 2; }
      CLEAN_SOURCE_ARCHIVE="$2"
      shift 2 ;;
    --allow-self-scan)
      ALLOW_SELF_SCAN=1
      shift ;;
    -h|--help)
      grep '^#' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *)
      echo "ERROR: unknown argument '$1' (see --help)" >&2
      exit 2 ;;
  esac
done

# --- Prasyarat ------------------------------------------------------
[[ -x "$SYFT" ]] || { echo "Pinned Syft binary missing: $SYFT (run install-supply-chain.sh)" >&2; exit 2; }
[[ -x "$GRYPE" ]] || { echo "Pinned Grype binary missing: $GRYPE (run install-supply-chain.sh)" >&2; exit 2; }
[[ -s "$POLICY" ]] || { echo "Vulnerability policy missing: $POLICY" >&2; exit 2; }

# --- Output dir -----------------------------------------------------
if [[ "$TARGET_MODE" == "working-tree" ]]; then
  OUTPUT_DIR="${ZEF_SUPPLY_CHAIN_OUTPUT_DIR:-$ROOT/supply-chain/scan}"
else
  OUTPUT_DIR="${ZEF_SUPPLY_CHAIN_OUTPUT_DIR:-$ROOT/supply-chain/scan-clean-source}"
fi
mkdir -p "$OUTPUT_DIR"

# --- Self-scan guard (FU-2.4) ---------------------------------------
if [[ "$TARGET_MODE" == "working-tree" ]]; then
  if [[ -d "$ROOT/.zef/supply-chain/bin" ]] && ! { [[ "$ALLOW_SELF_SCAN" -eq 1 ]]; }; then
    echo "ERROR: working-tree scan akan menyertakan binary scanner sendiri (.zef/supply-chain/bin)." >&2
    echo "  Default target adalah clean-source (arsip/staging bersih)." >&2
    echo "  Untuk scan working tree secara sadar: --target working-tree --allow-self-scan" >&2
    exit 2
  fi
fi

# --- Siapkan target scan --------------------------------------------
SCAN_TARGET=""
CLEANUP_DIR=""
trap '[[ -n "$CLEANUP_DIR" ]] && rm -rf "$CLEANUP_DIR"' EXIT

if [[ "$TARGET_MODE" == "clean-source" ]]; then
  if [[ -n "$CLEAN_SOURCE_ARCHIVE" ]]; then
    [[ -f "$CLEAN_SOURCE_ARCHIVE" ]] || { echo "ERROR: archive not found: $CLEAN_SOURCE_ARCHIVE" >&2; exit 2; }
    CLEANUP_DIR="$(mktemp -d -t zef-scan-clean.XXXXXX)"
    unzip -q "$CLEAN_SOURCE_ARCHIVE" -d "$CLEANUP_DIR"
    SCAN_TARGET="$CLEANUP_DIR"
    echo "clean_source_archive=$CLEAN_SOURCE_ARCHIVE"
  else
    # Cari arsip terbaru hasil clean-release.sh
    LATEST="$(ls -t "$ROOT"/dist/clean-release/zef-source-*.zip 2>/dev/null | head -1 || true)"
    if [[ -z "$LATEST" ]]; then
      echo "ERROR: tidak ada source archive di dist/clean-release/." >&2
      echo "  Jalankan tools/dev/clean-release.sh dulu, atau beri --archive <path>" >&2
      exit 2
    fi
    CLEANUP_DIR="$(mktemp -d -t zef-scan-clean.XXXXXX)"
    unzip -q "$LATEST" -d "$CLEANUP_DIR"
    SCAN_TARGET="$CLEANUP_DIR"
    echo "clean_source_archive=$LATEST"
  fi
else
  SCAN_TARGET="$ROOT"
fi

# --- Exclusions (FU-2.3) --------------------------------------------
EXCLUDES=(--exclude './.git' --exclude './vendor' --exclude './var' --exclude './.zef' --exclude './dist')
if [[ "$TARGET_MODE" == "working-tree" ]]; then
  EXCLUDES+=(--exclude 'rr' --exclude 'rr.exe' --exclude 'rr.lite')
fi

# --- Scan -------------------------------------------------------------
SCAN_TS="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
echo "scan_mode=$TARGET_MODE"
echo "scan_timestamp_utc=$SCAN_TS"
echo "scan_target=$SCAN_TARGET"

"$SYFT" version | tee "$OUTPUT_DIR/syft-version.txt"
"$GRYPE" version | tee "$OUTPUT_DIR/grype-version.txt"
"$SYFT" "dir:$SCAN_TARGET" "${EXCLUDES[@]}" --output cyclonedx-json="$OUTPUT_DIR/sbom.cdx.json"

# Grype DB: update untuk security-gate; catat status untuk evidence
"$GRYPE" db update > "$OUTPUT_DIR/grype-db-update.txt" 2>&1
"$GRYPE" db status > "$OUTPUT_DIR/grype-db-status.txt"

# Fail-on severity diambil dari policy (default: high)
FAIL_ON="$(php -r '$p=json_decode(file_get_contents($argv[1]),true); echo $p["fail_on_severity"] ?? "high";' "$POLICY" 2>/dev/null || echo high)"
"$GRYPE" sbom:"$OUTPUT_DIR/sbom.cdx.json" --fail-on "$FAIL_ON" --output json > "$OUTPUT_DIR/grype-report.json"

# --- Evidence DB provenance (FU-1/FU-2.5) ----------------------------
python3 - "$OUTPUT_DIR" "$SCAN_TS" <<'PYEOF'
import json, os, sys, hashlib, re, subprocess, pathlib
outdir, scan_ts = sys.argv[1], sys.argv[2]
status_file = os.path.join(outdir, "grype-db-status.txt")
prov = {
    "document": "grype-db-provenance",
    "project": "Zef Framework v2.5.0-beta1",
    "phase": "A2 follow-up (FU-1/FU-2)",
    "purpose": "Release evidence: grype vulnerability DB snapshot digest",
    "scan_mode": "clean-source" if "clean" in outdir else "working-tree",
    "scan_timestamp_utc": scan_ts,
}
try:
    txt = open(status_file).read()
    for line in txt.splitlines():
        line = line.strip()
        if line.startswith("Path:"):      prov["grype_db_local_file"] = line.split(":",1)[1].strip()
        if line.startswith("Schema:"):     prov["grype_db_schema"] = line.split(":",1)[1].strip()
        if line.startswith("Built:"):      prov["grype_db_built"] = line.split(":",1)[1].strip()
        if line.startswith("From:"):       prov["grype_db_download_url"] = line.split(":",1)[1].strip()
        if line.startswith("Status:"):     prov["grype_db_status"] = line.split(":",1)[1].strip()
    m = re.search(r"checksum=sha256%3A([0-9a-f]{64})", prov.get("grype_db_download_url",""))
    if m: prov["grype_db_archive_sha256"] = m.group(1)
    db_path = prov.get("grype_db_local_file","")
    if db_path and os.path.exists(db_path):
        h = hashlib.sha256()
        with open(db_path,"rb") as f:
            for chunk in iter(lambda: f.read(1<<20), b""): h.update(chunk)
        prov["grype_db_local_file_sha256"] = h.hexdigest()
        prov["grype_db_local_file_size_bytes"] = os.path.getsize(db_path)
except Exception as e:
    prov["db_provenance_error"] = str(e)
with open(os.path.join(outdir, "db-provenance.json"), "w") as f:
    json.dump(prov, f, indent=2)
print("db-provenance.json written for %s" % outdir)
PYEOF

printf 'supply_chain_scan=PASS\n'
printf 'mode=%s\n' "$TARGET_MODE"
printf 'output=%s\n' "$OUTPUT_DIR"
printf 'scan_timestamp_utc=%s\n' "$SCAN_TS"
