#!/usr/bin/env python3
"""Structural PDF/X checks for the conformance reference documents.

Usage: python3 scripts/conformance/pdfx-check.py [dir]
  dir — directory with the generated references (default build/conformance)

Tool choice: veraPDF validates PDF/A and PDF/UA but has no PDF/X profiles,
and full ISO 15930 preflight is the domain of commercial tools (callas
pdfToolbox, Acrobat Preflight). This checker covers the machine-checkable
structural requirements over the raw PDF bytes — deliberately WITHOUT using
php-pdf's own reader, so the writer is not validated by its own parser:

  * Ghostscript parses and renders the file with -dPDFSTOPONERROR
  * /OutputIntents entry with /S /GTS_PDFX and an embedded
    /DestOutputProfile ICC stream
  * /Info has /GTS_PDFXVersion (matching the flavour), /Title, /CreationDate,
    /ModDate, and /Trapped that is /True or /False (Unknown is not allowed)
  * trailer has a /ID file identifier
  * every page object carries a /TrimBox or /ArtBox, never both
  * no /Encrypt in the trailer
  * header version: X-3 => 1.4+, X-4 => 1.6+

Writes summary-pdfx.md into the directory; exits non-zero on any failure.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path

GS = os.environ.get("GS", "gs")

EXPECTED = {
    "pdfx-3": ("PDF/X-3:2003", (1, 4)),
    "pdfx-4": ("PDF/X-4", (1, 6)),
}


def ghostscript_renders(pdf: Path) -> tuple[bool, str]:
    try:
        proc = subprocess.run(
            [GS, "-dPDFSTOPONERROR", "-dNOPAUSE", "-dBATCH", "-dQUIET",
             "-sDEVICE=nullpage", str(pdf)],
            capture_output=True, text=True, timeout=120,
        )
    except FileNotFoundError:
        return False, "ghostscript (gs) not found"
    except subprocess.TimeoutExpired:
        return False, "ghostscript timed out"
    if proc.returncode != 0:
        tail = (proc.stderr or proc.stdout).strip().splitlines()[-3:]
        return False, "gs exit %d: %s" % (proc.returncode, " / ".join(tail))
    return True, ""


def page_objects(data: bytes) -> list[bytes]:
    """Bodies of all page objects (/Type /Page, not /Pages)."""
    pages = []
    for m in re.finditer(rb"\d+ 0 obj(.*?)endobj", data, re.S):
        body = m.group(1)
        if re.search(rb"/Type\s*/Page(?![s\w])", body):
            pages.append(body)
    return pages


def check_file(pdf: Path, variant: str, min_version: tuple[int, int]) -> list[str]:
    errors: list[str] = []
    data = pdf.read_bytes()

    header = re.match(rb"%PDF-(\d+)\.(\d+)", data)
    if not header:
        return ["missing %PDF header"]
    version = (int(header.group(1)), int(header.group(2)))
    if version < min_version:
        errors.append(f"header {version[0]}.{version[1]} < required "
                      f"{min_version[0]}.{min_version[1]}")

    ok, msg = ghostscript_renders(pdf)
    if not ok:
        errors.append(f"ghostscript: {msg}")

    if not re.search(rb"/S\s*/GTS_PDFX", data):
        errors.append("no OutputIntent with /S /GTS_PDFX")
    if not re.search(rb"/DestOutputProfile\s+\d+ 0 R", data):
        errors.append("OutputIntent has no /DestOutputProfile reference")
    if b"/OutputConditionIdentifier" not in data:
        errors.append("no /OutputConditionIdentifier")

    if not re.search(rb"/GTS_PDFXVersion \(" + re.escape(variant.encode()) + rb"\)", data):
        errors.append(f"/Info lacks /GTS_PDFXVersion ({variant})")
    for key in (b"/Title", b"/CreationDate", b"/ModDate"):
        if key + b" (" not in data:
            errors.append(f"/Info lacks {key.decode()}")
    if not re.search(rb"/Trapped\s*/(True|False)(?![\w])", data):
        errors.append("/Trapped must be /True or /False")

    if not re.search(rb"/ID\s*\[", data):
        errors.append("trailer lacks /ID")
    if re.search(rb"/Encrypt\s+\d+ 0 R", data):
        errors.append("trailer has /Encrypt (PDF/X forbids encryption)")

    pages = page_objects(data)
    if not pages:
        errors.append("no page objects found")
    for i, body in enumerate(pages):
        has_trim = b"/TrimBox" in body
        has_art = b"/ArtBox" in body
        if has_trim and has_art:
            errors.append(f"page {i + 1}: both /TrimBox and /ArtBox present")
        if not has_trim and not has_art:
            errors.append(f"page {i + 1}: neither /TrimBox nor /ArtBox present")

    return errors


def main() -> int:
    out_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "build/conformance")
    rows = []
    failed = False

    for name, (variant, min_version) in EXPECTED.items():
        pdf = out_dir / f"{name}.pdf"
        if not pdf.is_file():
            print(f"MISSING {pdf}", file=sys.stderr)
            rows.append(f"| {name}.pdf | {variant} | ❌ missing | — |")
            failed = True
            continue
        errors = check_file(pdf, variant, min_version)
        if errors:
            failed = True
            print(f"FAIL {name}.pdf ({variant})", file=sys.stderr)
            for e in errors:
                print(f"  - {e}", file=sys.stderr)
            rows.append(f"| {name}.pdf | {variant} | ❌ | {'; '.join(errors)} |")
        else:
            print(f"PASS {name}.pdf ({variant})")
            rows.append(f"| {name}.pdf | {variant} | ✅ | — |")

    summary = out_dir / "summary-pdfx.md"
    summary.write_text(
        "## PDF/X structural checks — Ghostscript + byte-level assertions\n\n"
        "| Document | Variant | Result | Details |\n|---|---|---|---|\n"
        + "\n".join(rows) + "\n\n"
        "PDF/X-1a reference is pending a redistributable CMYK output-intent "
        "profile (X-1a forbids an RGB output intent).\n",
    )
    print()
    print(summary.read_text())
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
