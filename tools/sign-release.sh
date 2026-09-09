#!/usr/bin/env bash
set -euo pipefail
ARTIFACT="${1:?artifact required}"
command -v cosign >/dev/null 2>&1 || { echo "cosign is required for tagged release signing" >&2; exit 2; }
cosign sign-blob --yes --bundle "${ARTIFACT}.sigstore.json" "$ARTIFACT"
