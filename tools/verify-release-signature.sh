#!/usr/bin/env bash
set -euo pipefail
ARTIFACT="${1:?artifact required}"
BUNDLE="${2:-${ARTIFACT}.sigstore.json}"
CERT_IDENTITY="${COSIGN_CERTIFICATE_IDENTITY:-}"
CERT_ISSUER="${COSIGN_CERTIFICATE_ISSUER:-}"
command -v cosign >/dev/null 2>&1 || { echo 'cosign is required for signature verification' >&2; exit 2; }
test -s "$BUNDLE" || { echo "signature bundle missing: $BUNDLE" >&2; exit 1; }
args=(verify-blob --bundle "$BUNDLE")
if [[ -n "$CERT_IDENTITY" ]]; then args+=(--certificate-identity-regexp "$CERT_IDENTITY"); fi
if [[ -n "$CERT_ISSUER" ]]; then args+=(--certificate-oidc-issuer "$CERT_ISSUER"); fi
cosign "${args[@]}" "$ARTIFACT"
