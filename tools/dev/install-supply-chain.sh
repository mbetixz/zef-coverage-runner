#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALL_DIR="${ZEF_SUPPLY_CHAIN_DIR:-$ROOT/.zef/supply-chain}"
BIN_DIR="$INSTALL_DIR/bin"
MANIFEST="$INSTALL_DIR/manifest.env"

# ---------------------------------------------------------------------------
# Pinned supply-chain tool versions (release policy — canonical for baseline).
# Defaults below are the audited pins for this baseline; override via env vars
# ONLY when a deliberate, reviewed pin bump is being performed.
# ---------------------------------------------------------------------------
: "${ZEF_SYFT_VERSION:=v1.51.1}"
: "${ZEF_SYFT_SHA256:=8fcb33017a0dc1058298c923c436d19dfa68ae93968e0b423248542e3afb9fc3}"
: "${ZEF_GRYPE_VERSION:=v0.118.0}"
: "${ZEF_GRYPE_SHA256:=1d444c5e7360471815f7158f71935fcecc68a3c417d85c7344f770854300bba2}"

for value in "$ZEF_SYFT_SHA256" "$ZEF_GRYPE_SHA256"; do
  [[ "$value" =~ ^[[:xdigit:]]{64}$ ]] || { echo 'Release checksum must be exactly 64 hexadecimal characters.' >&2; exit 2; }
done
[[ "$ZEF_SYFT_VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Syft version must be a pinned semver tag.' >&2; exit 2; }
[[ "$ZEF_GRYPE_VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Grype version must be a pinned semver tag.' >&2; exit 2; }

mkdir -p "$BIN_DIR"
tmp="$(mktemp -d -t zef-supply-chain.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

# Idempotency guard: if both pinned binaries are already installed with the
# requested versions, keep them and do not re-download.
if [[ -x "$BIN_DIR/syft" && -x "$BIN_DIR/grype" && -f "$MANIFEST" ]]; then
  installed_ok=1
  grep -q "^ZEF_SYFT_VERSION=$ZEF_SYFT_VERSION\$" "$MANIFEST" || installed_ok=0
  grep -q "^ZEF_SYFT_SHA256=$ZEF_SYFT_SHA256\$" "$MANIFEST" || installed_ok=0
  grep -q "^ZEF_GRYPE_VERSION=$ZEF_GRYPE_VERSION\$" "$MANIFEST" || installed_ok=0
  grep -q "^ZEF_GRYPE_SHA256=$ZEF_GRYPE_SHA256\$" "$MANIFEST" || installed_ok=0
  if [[ "$installed_ok" -eq 1 ]]; then
    echo "Pinned supply-chain tools already present and matching manifest: syft $ZEF_SYFT_VERSION, grype $ZEF_GRYPE_VERSION (skip download)"
    "$BIN_DIR/syft" version
    "$BIN_DIR/grype" version
    exit 0
  fi
fi

download_and_install() {
  local name="$1" version="$2" expected="$3" url="$4"
  local archive="$tmp/$name.tar.gz"
  curl --fail --location --retry 2 --proto '=https' --tlsv1.2 -o "$archive" "$url"
  echo "$expected  $archive" | sha256sum --check --status
  tar -xzf "$archive" -C "$tmp" "$name"
  install -m 0755 "$tmp/$name" "$BIN_DIR/$name"
}

download_and_install syft "$ZEF_SYFT_VERSION" "$ZEF_SYFT_SHA256" "https://github.com/anchore/syft/releases/download/$ZEF_SYFT_VERSION/syft_${ZEF_SYFT_VERSION#v}_linux_amd64.tar.gz"
download_and_install grype "$ZEF_GRYPE_VERSION" "$ZEF_GRYPE_SHA256" "https://github.com/anchore/grype/releases/download/$ZEF_GRYPE_VERSION/grype_${ZEF_GRYPE_VERSION#v}_linux_amd64.tar.gz"

cat > "$MANIFEST" <<EOF
ZEF_SUPPLY_CHAIN_ROOT=$INSTALL_DIR
ZEF_SUPPLY_CHAIN_BIN=$BIN_DIR
ZEF_SYFT_VERSION=$ZEF_SYFT_VERSION
ZEF_SYFT_SHA256=$ZEF_SYFT_SHA256
ZEF_GRYPE_VERSION=$ZEF_GRYPE_VERSION
ZEF_GRYPE_SHA256=$ZEF_GRYPE_SHA256
EOF
chmod 600 "$MANIFEST"
"$BIN_DIR/syft" version
"$BIN_DIR/grype" version
echo "Pinned supply-chain tools installed under $INSTALL_DIR"
