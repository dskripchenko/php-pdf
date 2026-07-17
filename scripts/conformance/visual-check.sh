#!/usr/bin/env bash
# Visual regression check: render reference PDFs to PNG and diff against
# committed golden images.
#
# Usage:
#   scripts/conformance/visual-check.sh          # compare against goldens
#   scripts/conformance/visual-check.sh --update # (re)write the goldens
#
# Env:
#   PDFTOPPM — poppler pdftoppm binary (default: pdftoppm)
#   COMPARE  — ImageMagick compare command (default: compare; set e.g.
#              COMPARE="magick compare" for ImageMagick 7)
#
# Goldens live in tests/visual/golden/ and are rendered with the CI's
# poppler (ubuntu-latest). If a legitimate rendering change reddens CI,
# download the rendered PNGs from the visual-regression artifact and commit
# them as the new goldens — do NOT regenerate goldens on macOS: a different
# poppler build may anti-alias differently.
#
# Tolerance: per-pixel fuzz absorbs sub-pixel anti-aliasing noise; the
# check fails when more than MAX_DIFF_PCT percent of pixels differ.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD="$ROOT/build/conformance"
RENDER="$ROOT/build/visual"
GOLDEN="$ROOT/tests/visual/golden"

PDFTOPPM="${PDFTOPPM:-pdftoppm}"
COMPARE="${COMPARE:-compare}"
DPI=96
FUZZ="5%"
MAX_DIFF_PCT="0.5"

# First page of each document is the visual fixture.
DOCS=(pdfa-1b pdfx-4)

mkdir -p "$RENDER" "$GOLDEN"

update=0
[[ "${1:-}" == "--update" ]] && update=1

summary="$BUILD/summary-visual.md"
{
    echo "## Visual regression — poppler render vs golden PNGs"
    echo
    echo "| Document | Differing pixels | Threshold | Result |"
    echo "|---|---|---|---|"
} > "$summary"

fail=0
for name in "${DOCS[@]}"; do
    pdf="$BUILD/$name.pdf"
    if [[ ! -f "$pdf" ]]; then
        echo "MISSING $pdf (run scripts/conformance/generate.php first)" >&2
        exit 2
    fi

    "$PDFTOPPM" -f 1 -l 1 -r "$DPI" -png -singlefile "$pdf" "$RENDER/$name"
    rendered="$RENDER/$name.png"

    if [[ "$update" == 1 ]]; then
        cp "$rendered" "$GOLDEN/$name.png"
        echo "updated golden $GOLDEN/$name.png"
        continue
    fi

    if [[ ! -f "$GOLDEN/$name.png" ]]; then
        echo "NO GOLDEN for $name — run with --update to create it" >&2
        echo "| $name | — | — | ❌ no golden |" >> "$summary"
        fail=1
        continue
    fi

    total=$(python3 -c "
import struct
with open('$rendered', 'rb') as f:
    f.seek(16)
    w, h = struct.unpack('>II', f.read(8))
print(w * h)
")
    # AE metric goes to stderr; exit code 1 just means images differ.
    ae=$($COMPARE -metric AE -fuzz "$FUZZ" "$GOLDEN/$name.png" "$rendered" \
        "$RENDER/$name.diff.png" 2>&1 >/dev/null || true)
    # ImageMagick 7 prints "N (normalized)" — keep the absolute count.
    ae="${ae%% *}"
    if ! [[ "$ae" =~ ^[0-9.e+]+$ ]]; then
        echo "COMPARE ERROR for $name: $ae" >&2
        echo "| $name | — | — | ❌ compare error |" >> "$summary"
        fail=1
        continue
    fi

    pct=$(python3 -c "print(f'{100 * float('$ae') / $total:.3f}')")
    if python3 -c "import sys; sys.exit(0 if float('$pct') <= $MAX_DIFF_PCT else 1)"; then
        echo "PASS $name ($ae px / $pct% differ, limit $MAX_DIFF_PCT%)"
        echo "| $name | $ae ($pct%) | $MAX_DIFF_PCT% | ✅ |" >> "$summary"
        rm -f "$RENDER/$name.diff.png"
    else
        echo "FAIL $name ($ae px / $pct% differ, limit $MAX_DIFF_PCT%)" >&2
        echo "| $name | $ae ($pct%) | $MAX_DIFF_PCT% | ❌ |" >> "$summary"
        fail=1
    fi
done

echo
cat "$summary"
exit "$fail"
