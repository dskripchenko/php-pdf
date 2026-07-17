#!/usr/bin/env bash
# Smoke-check the torture set: every document must parse and render cleanly
# in two independent engines (poppler pdftoppm + Ghostscript).
#
# Usage: scripts/conformance/torture-smoke.sh [dir]
#   dir — directory with generated torture PDFs (default examples/torture/out)
#
# Env: PDFTOPPM, GS — binary overrides.
#
# Writes summary-torture.md into build/conformance/. Exits non-zero if any
# document fails either engine.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${1:-$ROOT/examples/torture/out}"
OUT="$ROOT/build/conformance"
RENDER="$ROOT/build/torture-render"

PDFTOPPM="${PDFTOPPM:-pdftoppm}"
GS="${GS:-gs}"

mkdir -p "$OUT" "$RENDER"

summary="$OUT/summary-torture.md"
{
    echo "## Torture set — render smoke (poppler + Ghostscript)"
    echo
    echo "| Document | poppler | Ghostscript |"
    echo "|---|---|---|"
} > "$summary"

shopt -s nullglob
pdfs=("$DIR"/*.pdf)
if [ ${#pdfs[@]} -eq 0 ]; then
    echo "No PDFs in $DIR — run examples/torture/generate.php first" >&2
    exit 2
fi

fail=0
for pdf in "${pdfs[@]}"; do
    name="$(basename "$pdf" .pdf)"

    if "$PDFTOPPM" -r 72 -png -singlefile "$pdf" "$RENDER/$name" 2> "$RENDER/$name.poppler.log"; then
        poppler="✅"
    else
        poppler="❌"
        fail=1
        echo "POPPLER FAIL $name" >&2
        cat "$RENDER/$name.poppler.log" >&2
    fi

    if "$GS" -dPDFSTOPONERROR -dNOPAUSE -dBATCH -dQUIET -sDEVICE=nullpage "$pdf" 2> "$RENDER/$name.gs.log"; then
        gs="✅"
    else
        gs="❌"
        fail=1
        echo "GS FAIL $name" >&2
        cat "$RENDER/$name.gs.log" >&2
    fi

    echo "| $name | $poppler | $gs |" >> "$summary"
    echo "$name: poppler=$poppler gs=$gs"
done

echo
cat "$summary"
exit "$fail"
