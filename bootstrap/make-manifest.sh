#!/usr/bin/env bash
#
# Phase G (G8b / C7) — regenerate bootstrap/SHA256SUMS over the downloadable bootstrap
# artifacts, and (if a release secret key is available) produce a detached Ed25519
# signature. Run this after editing ANY bootstrap file, before publishing to the website,
# so the "verify before you run" flow in README.md actually matches what is shipped.
#
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

FILES=(bootstrap.sh bootstrap.command bootstrap.ps1 mesh-catalog.json)

sha256sum "${FILES[@]}" > SHA256SUMS
echo "→ wrote SHA256SUMS:"
cat SHA256SUMS

# Optional detached signature. The release secret key is OPERATOR-HELD and NEVER committed;
# sign on the offline key host at release time. The website publishes SHA256SUMS,
# SHA256SUMS.minisig, and the public key so a downloader can verify before running.
if command -v minisign >/dev/null 2>&1 && [[ -n "${CGA_RELEASE_SECKEY:-}" ]]; then
  minisign -S -s "$CGA_RELEASE_SECKEY" -m SHA256SUMS
  echo "→ signed SHA256SUMS.minisig"
else
  echo "  (minisign / CGA_RELEASE_SECKEY not present — SHA256SUMS is unsigned; sign at release)"
fi
