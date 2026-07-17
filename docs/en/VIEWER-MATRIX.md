# Viewer matrix — torture set

The torture set (`examples/torture/generate.php`) is a suite of documents
exercising the hardest rendering paths: complex tables, Cyrillic + Greek,
Arabic shaping/bidi, CJK, five barcode symbologies, charts, SVG, AcroForm
fields, a digital signature, PDF/A-2u, and read/merge/stamp output.

Four columns are automated: every push renders each document with
**poppler** and **Ghostscript** in CI (`torture-smoke` job); the
**pdf.js** column is the actual Firefox engine driven headless
(`pdfjs-dist` + node canvas, `disableFontFace: true`), and the
**Quartz** column is Apple's PDFKit renderer via Quick Look — the same
engine macOS Preview uses. Acrobat Reader and Chrome (PDFium) have no
scriptable render path and stay manual.

Legend: ✅ verified · ☐ pending · ✗ broken (file an issue)

Last automated pass: **2026-07-17** — poppler 26.06 / Ghostscript 10.07 /
pdf.js 6.1.200 / Quartz on macOS 15 (Darwin 25.5).

| Document | Exercises | poppler (CI) | Ghostscript (CI) | pdf.js / Firefox | Quartz / Preview | Acrobat Reader | Chrome (PDFium) |
|---|---|---|---|---|---|---|---|
| t01-tables | column/row spans, wrapping | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t02-multilingual | Cyrillic, Greek, ligatures, styles | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t03-arabic-bidi | Arabic shaping, bidi runs | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t04-cjk | CJK ideographs, kana | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t05-barcodes | Code128, EAN-13, QR, DataMatrix (rectangular), PDF417 | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t06-charts | pie, bar, line, area | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t07-svg | gradients, paths, rounded rects | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t08-forms | text, checkbox, multiline fields | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t09-pdfa-2u | archival profile with real content | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t10-signed | PKCS#7 detached signature | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |
| t11-merged | append + stamp via reader/merger | ✅ | ✅ | ✅ | ✅ | ☐ | ☐ |

## Machine decodability (beyond rendering)

Barcodes are verified by independent decoders against the poppler render,
not just by looking at them:

| Symbol | Decoder | Result |
|---|---|---|
| QR | zbar **and** ZXing | ✅ round-trips (incl. UTF-8 payloads) |
| DataMatrix (rectangular 12×26) | libdmtx (`dmtxread`) | ✅ round-trips |
| EAN-13, Code 128 | zbar | ✅ round-trips |
| PKCS#7 signature | OpenSSL CLI (`cms -verify`) | ✅ (test suite) |

The QR decodability check exists because the 2026-07-17 pass caught the
format-information bits being written in reverse — every viewer rendered
the symbol, no scanner could read it. Fixed; guarded by
`tests/Barcode/QrFormatInfoTest.php`.

## Manual checklist (Acrobat Reader, Chrome)

1. Generate: `bash scripts/fetch-fonts.sh && php examples/torture/generate.php`
   (or download the `torture-set` artifact from the latest conformance run).
2. Open each document and compare against the poppler render
   (`build/torture-render/*.png` or the CI artifact).
3. Viewer-specific points:
   - **t05**: scan the QR and DataMatrix with a phone camera; both must
     decode (independent decoders already pass — the phone is a sanity
     check for contrast/quiet zones).
   - **t08**: fields must be editable; the checkbox must toggle.
   - **t10**: the signature panel must report a valid signature with an
     untrusted (self-signed) certificate — *"valid but unverified
     identity"* in Acrobat. A "document has been altered" message is a bug.
   - **t09**: Acrobat should show the "PDF/A mode" banner.
4. Update the table (✅/✗ + viewer version) and note anomalies below.

## Known anomalies

- **t04 (Quartz, pdf.js, all viewers)**: the Korean sample line renders
  as boxes — expected: DroidSansFallback carries no Hangul, and the doc
  says so; it is a fallback-coverage illustration, not a defect.
- **Fixed during the 2026-07-17 pass** (both found by this checklist):
  QR format information written LSB-first (unreadable by any scanner);
  SVG default-namespace lookups dropping gradients/`<style>`/`<use>`
  (gradient fills rendered black).
