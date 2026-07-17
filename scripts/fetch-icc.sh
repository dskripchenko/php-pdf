#!/usr/bin/env bash
# Download the CMYK characterization profile used by the PDF/X-1a
# conformance reference (X-1a mandates a CMYK output intent; the sRGB
# profile vendored in resources/icc only covers X-3/X-4).
#
# CGATS21_CRPC1.icc comes from the ICC registry
# (https://www.color.org/registry/) and may be distributed unmodified
# under the ICC profile license. At ~3.7 MB it is fetched on demand and
# cached rather than vendored.
set -euo pipefail

CACHE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.cache/icc"
mkdir -p "$CACHE_DIR"

FILE="$CACHE_DIR/CGATS21_CRPC1.icc"
EXPECTED_SHA="f0d784825f8f358db94fd71c7de9f56ba228df9a606667fa7c1a9850332fa317"

if [ -f "$FILE" ]; then
    echo "CGATS21_CRPC1.icc already cached"
    exit 0
fi

URL="https://www.color.org/registry/profiles/CGATS21_CRPC1.icc"
echo "Downloading $URL..."
curl -sSL "$URL" -o "$FILE"

ACTUAL_SHA=$(shasum -a 256 "$FILE" | awk '{print $1}')
if [ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]; then
    echo "ERROR: SHA256 mismatch for CGATS21_CRPC1.icc"
    echo "Expected: $EXPECTED_SHA"
    echo "Actual:   $ACTUAL_SHA"
    rm -f "$FILE"
    exit 1
fi
echo "Done. Profile at $FILE"
