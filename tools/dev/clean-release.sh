#!/usr/bin/env bash
# ============================================================
# ZEF Framework — clean-release.sh (disempurnakan, Fase A1 / RM-03a)
#
# Membangun source archive bersih (zip) yang HANYA berisi
# source + config + docs + tools. Menutup temuan audit:
#   K-3 : vendor/, var/, .zef/, rr, SHA256SUMS* redundan ikut terpaket
#   K-5 : *.out, SHA256SUMS* root, cache lolos dari eksklusi & cek find
#
# Kebijakan manifest (RM-03a):
#   1 manifest source kanonik  = supply-chain/SHA256SUMS-i.txt
#   (dipakai verify-release-integrity.php / generate-release-manifest.php)
#   Semua SHA256SUMS* lain di root DIHAPUS dari stage.
#
# Idempoten & deterministik: tidak pernah menghapus dari ROOT;
# bekerja di stage temp (mktemp), output ke $OUTPUT_DIR.
# ============================================================
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTPUT_DIR="${ZEF_RELEASE_OUTPUT_DIR:-$ROOT/dist/clean-release}"
STAGE="$(mktemp -d -t zef-clean-release.XXXXXX)"
trap 'rm -rf "$STAGE"' EXIT

version="$(php -r '$j=json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $j["version"] ?? "baseline";' 2>/dev/null || true)"
[[ -n "$version" ]] || version="baseline"
archive="$OUTPUT_DIR/zef-source-${version}.zip"

mkdir -p "$OUTPUT_DIR"
rm -f "$archive"

# ------------------------------------------------------------------
# 1) Stage dari repository tree dengan eksklusi LENGKAP (menutup K-5)
# ------------------------------------------------------------------
tar -C "$ROOT" -cf - \
  --exclude='./vendor' \
  --exclude='./var' \
  --exclude='./dist' \
  --exclude='./.zef' \
  --exclude='./.git' \
  --exclude='./src.preR3' \
  --exclude='./tests/_history' \
  --exclude='./rr' \
  --exclude='./rr.exe' \
  --exclude='./rr.lite' \
  --exclude='*.log' \
  --exclude='*.pid' \
  --exclude='*.out' \
  --exclude='*.tmp' \
  --exclude='*.cache' \
  --exclude='.deptrac.cache' \
  --exclude='.php-cs-fixer.cache' \
  --exclude='.phpunit.result.cache' \
  --exclude='./composer.phar' \
  --exclude='./SHA256SUMS*.txt' \
  --exclude='./*_CANDIDATE_SHA256SUMS.txt' \
  --exclude='./*_ADDITIVE_SHA256SUMS.txt' \
  --exclude='./zef_framework_*.php' \
  --exclude='./RELEASE_MANIFEST_*.txt' \
  --exclude='./SHA256-P.txt' \
  --exclude='./supply-chain/*.intoto.json' \
  . | tar -C "$STAGE" -xf -

# ------------------------------------------------------------------
# 2) Hapus dari stage: semua manifest SHA256SUMS root (kebijakan kanonik)
#    Kecuali manifest kanonik supply-chain/SHA256SUMS-i.txt
# ------------------------------------------------------------------
find "$STAGE" -maxdepth 1 -type f -name 'SHA256SUMS*' -delete
find "$STAGE" -maxdepth 1 -type f -name '*_CANDIDATE_SHA256SUMS.txt' -delete
find "$STAGE" -maxdepth 1 -type f -name '*_ADDITIVE_SHA256SUMS.txt' -delete
find "$STAGE" -maxdepth 1 -type f -name 'RELEASE_MANIFEST_*.txt' -delete
find "$STAGE" -maxdepth 1 -type f -name 'SHA256-P.txt' -delete

# ------------------------------------------------------------------
# 3) Cek integritas pasca-stage: TIDAK BOLEH ada artefak terlarang
# ------------------------------------------------------------------
# 3a) Manifest redundan HANYA di root (kebijakan kanonik RM-03a) —
#     manifest historis di subdir documentation/ adalah dokumentasi sah.
find "$STAGE" -maxdepth 1 -type f \( \
    -name 'SHA256SUMS*' -o \
    -name '*_CANDIDATE_SHA256SUMS.txt' -o \
    -name '*_ADDITIVE_SHA256SUMS.txt' -o \
    -name 'RELEASE_MANIFEST_*.txt' -o \
    -name 'SHA256-P.txt' \) -print -quit | grep -q . && {
  echo 'FAIL: redundant root manifest found in clean source stage.' >&2
  exit 1
}

# 3b) Artefak generated/runtime di seluruh tree (manifest kanonik
#     supply-chain/SHA256SUMS-i.txt DIPERTAHANKAN — di-exclude).
if find "$STAGE" -type f \( \
    -path '*/var/*' -o \
    -path '*/vendor/*' -o \
    -path '*/dist/*' -o \
    -path '*/src.preR3/*' -o \
    -path '*/.zef/*' -o \
    -name '*.log' -o \
    -name '*.pid' -o \
    -name '*.out' -o \
    -name 'rr' -o \
    -name '.deptrac.cache' -o \
    -name '.php-cs-fixer.cache' -o \
    -name '.phpunit.result.cache' -o \
    -name 'zef_framework_*.php' \) \
    ! -path '*/supply-chain/SHA256SUMS-i.txt' -print -quit | grep -q .; then
  echo 'FAIL: forbidden generated/runtime artifact found in clean source stage.' >&2
  exit 1
fi

# Manifest kanonik WAJIB masih ada (kebijakan: supply-chain/SHA256SUMS-i.txt)
if [[ ! -f "$STAGE/supply-chain/SHA256SUMS-i.txt" ]]; then
  echo 'FAIL: canonical manifest supply-chain/SHA256SUMS-i.txt missing from stage.' >&2
  exit 1
fi

# ------------------------------------------------------------------
# 3c) Normalisasi mtime stage -> arsip BYTE-deterministik.
#     Info-ZIP menulis mtime file ke entry zip; tanpa normalisasi,
#     hash arsip berubah antar build walau konten identik
#     (diverifikasi Fase A1-promotion: diff -r kosong, hash beda).
# ------------------------------------------------------------------
export TZ=UTC
find "$STAGE" -exec touch -h -d '1970-01-01 00:00:00 UTC' {} + 2>/dev/null || true

# ------------------------------------------------------------------
# 4) Zip deterministik + verifikasi
# ------------------------------------------------------------------
( cd "$STAGE" && zip -qrX "$archive" . )
unzip -t "$archive" >/dev/null

printf 'clean_release=%s\n' "$archive"
printf 'clean_release_sha256='; sha256sum "$archive" | awk '{print $1}'
printf 'clean_release_files='; unzip -Z1 "$archive" | wc -l
printf 'clean_release_size_bytes='; stat -c %s "$archive"
printf 'hygiene_check=PASS\n'
