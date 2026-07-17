#!/usr/bin/env bash
# Validate the reference PDF/A documents with veraPDF.
#
# Usage: scripts/conformance/verapdf.sh [dir]
#   dir — directory with the generated references (default build/conformance)
#
# Env: VERAPDF — path to the veraPDF CLI (default: `verapdf` on PATH).
#
# Writes per-file JSON reports (<name>.verapdf.json) and a summary.md table
# into the same directory. Exits non-zero if any document is non-compliant.
set -euo pipefail

DIR="${1:-build/conformance}"
VERAPDF="${VERAPDF:-verapdf}"

NAMES=(pdfa-1b pdfa-1a pdfa-2b pdfa-2u pdfa-3b)
flavour() { echo "${1#pdfa-}"; }

version="$("$VERAPDF" --version 2>/dev/null | head -1 || echo 'veraPDF')"
summary="$DIR/summary.md"
{
    echo "## PDF/A conformance — $version"
    echo
    echo "| Document | Flavour | Compliant | Failed rules |"
    echo "|---|---|---|---|"
} > "$summary"

fail=0
for name in "${NAMES[@]}"; do
    fl="$(flavour "$name")"
    pdf="$DIR/$name.pdf"
    json="$DIR/$name.verapdf.json"

    if [[ ! -f "$pdf" ]]; then
        echo "MISSING $pdf" >&2
        echo "| $name.pdf | $fl | ❌ missing | — |" >> "$summary"
        fail=1
        continue
    fi

    # veraPDF exits non-zero for non-compliant files — capture, don't abort.
    "$VERAPDF" -f "$fl" --format json "$pdf" > "$json" 2> "$DIR/$name.verapdf.log" || true

    read -r compliant failed_rules < <(python3 - "$json" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    vr = (d['report']['jobs'][0].get('validationResult') or [None])[0]
    if vr is None:
        print('error -')
    else:
        rules = vr.get('details', {}).get('ruleSummaries', [])
        clauses = ','.join(sorted({r['clause'] for r in rules})) or '-'
        print(('true' if vr.get('compliant') else 'false') + ' ' + clauses)
except Exception:
    print('error -')
PY
)

    if [[ "$compliant" == "true" ]]; then
        echo "PASS $name.pdf ($fl)"
        echo "| $name.pdf | $fl | ✅ | — |" >> "$summary"
    else
        echo "FAIL $name.pdf ($fl) — clauses: $failed_rules" >&2
        echo "| $name.pdf | $fl | ❌ | $failed_rules |" >> "$summary"
        fail=1
    fi
done

echo
cat "$summary"
exit "$fail"
