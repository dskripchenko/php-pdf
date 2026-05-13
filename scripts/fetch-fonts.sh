#!/usr/bin/env bash
# Download Liberation 2.1.5 fonts to .cache/fonts/ for POC use.
# Idempotent: skips if already cached.
set -euo pipefail

CACHE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.cache/fonts"
mkdir -p "$CACHE_DIR"
cd "$CACHE_DIR"

EXTRACT_DIR="liberation-fonts-ttf-2.1.5"
if [ -f "$EXTRACT_DIR/LiberationSans-Regular.ttf" ]; then
    echo "Liberation 2.1.5 already cached at $CACHE_DIR/$EXTRACT_DIR"
    exit 0
fi

URL="https://github.com/liberationfonts/liberation-fonts/files/7261482/liberation-fonts-ttf-2.1.5.tar.gz"
echo "Downloading Liberation 2.1.5 from $URL..."
curl -sL "$URL" -o liberation.tar.gz

EXPECTED_SHA="76d04c18ea243f426b7de1f3ad208e927008f961dc5945e5aad352d0dfde8ee8"
# Note: the expected SHA is for LiberationSans-Regular.ttf inside the tarball,
# not for the tarball itself. Verify after extraction.

tar -xzf liberation.tar.gz
rm liberation.tar.gz

ACTUAL_SHA=$(shasum -a 256 "$EXTRACT_DIR/LiberationSans-Regular.ttf" | awk '{print $1}')
if [ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]; then
    echo "ERROR: SHA256 mismatch for LiberationSans-Regular.ttf"
    echo "Expected: $EXPECTED_SHA"
    echo "Actual:   $ACTUAL_SHA"
    exit 1
fi
echo "Done. Fonts at $CACHE_DIR/$EXTRACT_DIR"
