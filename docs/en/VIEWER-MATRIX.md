# Viewer matrix — torture set

The torture set (`examples/torture/generate.php`) is a suite of documents
exercising the hardest rendering paths: complex tables, Cyrillic + Greek,
Arabic shaping/bidi, CJK, five barcode symbologies, charts, SVG, AcroForm
fields, a digital signature, PDF/A-2u, and read/merge/stamp output.

Two columns are automated: every push renders each document with **poppler**
and **Ghostscript** (`torture-smoke` job in the
[conformance workflow](../../../../actions/workflows/conformance.yml)); a
failure in either engine fails CI. The remaining columns are a manual
checklist, re-run before each release.

Legend: ✅ verified · ☐ pending · ✗ broken (file an issue)

| Document | Exercises | poppler (CI) | Ghostscript (CI) | Acrobat Reader | Chrome | Firefox (pdf.js) | macOS Preview |
|---|---|---|---|---|---|---|---|
| t01-tables | column/row spans, wrapping | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t02-multilingual | Cyrillic, Greek, ligatures, styles | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t03-arabic-bidi | Arabic shaping, bidi runs | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t04-cjk | CJK ideographs, kana | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t05-barcodes | Code128, EAN-13, QR, DataMatrix (rectangular), PDF417 | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t06-charts | pie, bar, line, area | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t07-svg | gradients, paths, text | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t08-forms | text, checkbox, multiline fields | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t09-pdfa-2u | archival profile with real content | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t10-signed | PKCS#7 detached signature | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| t11-merged | append + stamp via reader/merger | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |

## Manual checklist

1. Generate: `bash scripts/fetch-fonts.sh && php examples/torture/generate.php`
   (or download the `torture-set` artifact from the latest conformance run).
2. Open each document in the viewer and compare against the poppler render
   (`build/torture-render/*.png` or the CI artifact).
3. Viewer-specific points:
   - **t05**: scan the QR and DataMatrix with a phone; both must decode.
   - **t08**: fields must be editable; the checkbox must toggle.
   - **t10**: the signature panel must report a valid signature with an
     untrusted (self-signed) certificate — *"valid but unverified identity"*
     in Acrobat. A "document has been altered" message is a bug.
   - **t09**: Acrobat should show the "PDF/A mode" banner.
4. Update this table (✅/✗ + viewer version) and note anomalies below.

## Known anomalies

- *(none recorded yet — table pending its first manual pass)*
