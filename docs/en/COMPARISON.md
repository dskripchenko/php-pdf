# Comparison with mpdf, tcpdf, dompdf, FPDF

A feature-by-feature comparison of `dskripchenko/php-pdf` against the
four most-used PHP PDF libraries on Packagist. Performance numbers are
in [BENCHMARKS.md](BENCHMARKS.md).

## Licensing

| Library                  | License        | OEM / proprietary bundle |
|--------------------------|----------------|--------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ no friction |
| mpdf/mpdf                | GPL-2.0-only   | ❌ requires GPL bundle or commercial license |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ static-linking nuances |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ same as tcpdf |
| setasign/fpdf            | re-licensable  | ✅ but FPDI and other extras are proprietary |

MIT is the most permissive PHP license. Use the library anywhere,
including closed-source products, without modifying its source or
publishing your own.

## Engineering baseline

| Item                              | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|-----------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| PHP minimum                       | **8.2** | 7.4  | 7.1   | 7.1    | 4+   |
| Strict types throughout           | ✅      | ❌   | ❌    | ❌     | ❌   |
| Readonly classes, enums           | ✅      | ❌   | ❌    | ❌     | ❌   |
| Single file vs modular            | modular | modular | **single** (~30k LOC) | modular | modular |
| External binary dependencies      | ✅ none | ✅ none | ✅ none | ✅ none | ✅ none |

## Input

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Low-level imperative API               | ✅      | ⚠️    | ✅    | ⚠️      | ✅   |
| Fluent builders (Document → Section)   | ✅      | ❌   | ❌    | ❌     | ❌   |
| HTML/CSS input                         | ✅      | ✅   | ⚠️ basic | ✅  | ❌   |
| Inline `style` attributes              | ✅      | ✅   | ⚠️    | ✅     | ❌   |
| `<style>` blocks / external CSS        | ❌      | ✅   | ⚠️    | ✅     | ❌   |
| Float layout                           | ❌      | ✅   | ⚠️    | ✅     | ❌   |

For complex HTML or CSS (Flexbox, multi-column, `@media`, floats),
`dompdf` and `mpdf` go further. For business documents (paragraphs,
tables, lists, inline styling, headings), `php-pdf` is on par with
better performance.

## Typography

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| 14 standard fonts                      | ✅      | ✅   | ✅    | ✅     | ✅   |
| TTF embedding with subsetting          | ✅      | ✅   | ✅    | ✅     | ⚠️ (tFPDF) |
| Kerning                                | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| GSUB ligatures (basic)                 | ✅      | partial | ⚠️ | ❌     | ❌   |
| ToUnicode CMap (searchable CJK/Cyrillic) | ✅    | ✅   | ✅    | ✅     | ❌   |
| Variable fonts (fvar/gvar/MVAR/HVAR)   | ✅      | ❌   | ❌    | ❌     | ❌   |
| Bidi (UAX#9, X1–X10 explicit)          | ✅      | partial | partial | ❌  | ❌   |
| Arabic shaping                         | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| Indic shaping (Devanagari, Bengali, Gujarati) | ✅ | partial | ❌ | ❌     | ❌   |
| Vertical writing mode                  | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| Knuth–Plass line breaking              | ✅      | ❌   | ❌    | ❌     | ❌   |
| Hyphenation / soft hyphen              | ✅      | ✅   | ⚠️    | ⚠️      | ❌   |
| Multi-column layout                    | ✅      | ✅   | ⚠️    | ⚠️ partial | ❌ |

## Tables, lists, headers

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Tables with rowspan / colspan          | ✅      | ✅   | ✅    | ✅     | ❌   |
| Border collapse, double borders        | ✅      | ✅   | ✅    | ✅     | ❌   |
| Border radius                          | ✅      | ✅   | ⚠️    | ✅     | ❌   |
| Repeated table header on overflow      | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| Headers / footers / watermarks         | ✅      | ✅   | ✅    | ⚠️      | ⚠️    |
| Footnotes                              | ✅      | ✅   | ❌    | ❌     | ❌   |
| Section breaks (per-section page setup) | ✅     | ✅   | ⚠️    | ⚠️      | ❌   |

## Barcodes

| Format                                 | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Code 128 (A/B/C, GS1-128)              | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 39                                | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 93                                | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 11                                | ✅      | ❌   | ✅    | ❌     | ❌   |
| Codabar / NW-7                         | ✅      | ❌   | ✅    | ❌     | ❌   |
| ITF / ITF-14                           | ✅      | ✅   | ✅    | ❌     | ❌   |
| MSI Plessey                            | ✅      | ❌   | ✅    | ❌     | ❌   |
| Pharmacode (Laetus)                    | ✅      | ❌   | ❌    | ❌     | ❌   |
| EAN-13 / EAN-8                         | ✅      | ✅   | ✅    | ❌     | ❌   |
| EAN-2 / EAN-5 add-ons                  | ✅      | ❌   | ❌    | ❌     | ❌   |
| UPC-A / UPC-E                          | ✅      | ✅   | ✅    | ❌     | ❌   |
| QR Code (Numeric/Alphanum/Byte/Kanji)  | ✅      | ✅   | ✅    | ❌     | ❌   |
| QR ECI                                 | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| QR Structured Append                   | ✅      | ❌   | ❌    | ❌     | ❌   |
| QR FNC1 (GS1 + AIM)                    | ✅      | ❌   | ❌    | ❌     | ❌   |
| Data Matrix ECC 200 (all sizes)        | ✅      | partial | ✅ | ❌     | ❌   |
| Data Matrix 144×144                    | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| PDF417 (Byte/Text/Numeric, Macro, GS1) | ✅      | partial | ✅ | ❌     | ❌   |
| Aztec Compact + Full                   | ✅      | ❌   | ❌    | ❌     | ❌   |
| Aztec Structured Append + FLG/ECI      | ✅      | ❌   | ❌    | ❌     | ❌   |

## Charts and math

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Bar / Line / Pie chart                 | ✅      | ❌   | ⚠️ (TCPDF Graph extension) | ❌ | ❌ |
| Area / Donut / Scatter chart           | ✅      | ❌   | ⚠️ ext  | ❌    | ❌   |
| Grouped / Stacked bar                  | ✅      | ❌   | ⚠️ ext  | ❌    | ❌   |
| Math (LaTeX subset)                    | ✅      | ❌   | ❌    | ❌     | ❌   |
| SVG (paths, gradients, transforms)     | ✅      | ✅   | ⚠️ basic | ⚠️    | ❌   |

## Interactive features

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| AcroForm widgets                       | ✅      | ✅   | ✅    | ❌     | ❌   |
| AcroForm appearance streams (NeedAppearances) | ✅ | ⚠️ | ✅  | ❌     | ❌   |
| Per-field JavaScript actions           | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Markup annotations (12 kinds)          | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Hyperlinks (URI / Dest / Named / JS / Launch) | ✅ | ✅ | ✅  | ✅     | ⚠️    |
| Document-level actions (Will/Did)      | ✅      | ❌   | ✅    | ❌     | ❌   |
| Optional Content Groups (layers)       | ✅      | ❌   | ✅    | ❌     | ❌   |

## Security

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| RC4-128 encryption                     | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| AES-128 (V4 R4)                        | ✅      | ✅   | ✅    | ❌     | ❌   |
| AES-256 V5 R5 (Adobe Supplement)       | ✅      | ✅   | ✅    | ❌     | ❌   |
| **AES-256 V5 R6 (PDF 2.0)**            | ✅      | ❌   | ❌    | ❌     | ❌   |
| Encrypted strings + streams + Catalog  | ✅      | ✅   | ✅    | ❌     | ❌   |
| PKCS#7 detached signing                | ✅      | ❌   | ✅    | ❌     | ❌   |
| Public-key encryption (/PubSec)        | ❌      | ❌   | ✅    | ❌     | ❌   |

## Conformance and accessibility

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| PDF/A-1b                               | ✅      | ✅   | ✅    | ❌     | ❌   |
| PDF/A-1a (accessibility variant)       | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| PDF/A-2u                               | ✅      | ✅   | ⚠️    | ❌     | ❌   |
| PDF/X-1a / X-3 / X-4                   | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| Tagged PDF (H1–H6, Table, L)           | ✅      | partial | ⚠️ | ❌     | ❌   |
| Custom RoleMap                         | ✅      | ❌   | ❌    | ❌     | ❌   |
| `/Lang`, `/MarkInfo`, ViewerPreferences | ✅     | ✅   | ✅    | ❌     | ❌   |
| Page labels (Roman, alpha, prefix)     | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |

## Output features

| Feature                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| XRef streams (PDF 1.5+)                | ✅      | ❌   | ❌    | ❌     | ❌   |
| Object Streams (PDF 1.5+)              | ✅      | ❌   | ❌    | ❌     | ❌   |
| Balanced Page Tree                     | ✅      | ❌   | ❌    | ❌     | ❌   |
| Stream output (`toStream`)             | ✅      | ❌   | ❌    | ❌     | ❌   |
| Embedded files / attachments           | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Form XObjects (`/Do` reusable streams) | ✅      | ✅   | ✅    | ❌     | ⚠️    |
| Patterns (tiling + axial + radial)     | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| Multi-stop gradients (stitching fns)   | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |

## When to choose each

### Choose **dskripchenko/php-pdf** when
- You ship a closed-source / OEM product and need MIT.
- You generate business documents (invoices, reports, contracts, forms,
  certificates) where typography, barcodes, AcroForms, signing, or
  PDF/A matter.
- You want the broadest barcode coverage in pure PHP.
- You target PHP 8.2+ and want modern, type-safe code.
- You need PDF 2.0 features (AES-256 R6).
- You care about output size and per-render latency.

### Choose **mpdf/mpdf** when
- You already have it in your stack and migration cost isn't justified.
- Your project is GPL-compatible.
- You need richer CSS coverage (Flexbox, `@media`, complex selectors).
- You prefer mpdf's documentation and Stack Overflow corpus.

### Choose **tecnickcom/tcpdf** when
- You're maintaining a legacy enterprise PHP application that already
  uses it.
- You specifically need public-key encryption (`/Filter /PubSec`).
- You're comfortable with a single 30k-line file and PHP 7.1+ idioms.

### Choose **dompdf/dompdf** when
- You need to render arbitrary HTML/CSS with float layout — content
  intended for browsers, not business documents.
- Performance and memory aren't critical (it builds the full DOM in
  memory before layout).

### Choose **setasign/fpdf** when
- You generate simple invoices or receipts with manual positioning.
- You want the smallest possible dependency footprint.
- You don't need UTF-8, HTML, tables, charts, encryption, or signing.
