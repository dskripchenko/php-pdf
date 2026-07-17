<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/logo-dark.svg">
    <img src="docs/assets/logo-light.svg" alt="php-pdf" width="360">
  </picture>
</p>

# dskripchenko/php-pdf

> **Replaces mpdf + FPDI with a single MIT package — no GPL friction, and
> [faster](docs/en/BENCHMARKS.md).** Pure-PHP toolkit to **generate, read,
> and merge** PDFs: a GPL-free mpdf alternative for HTML→PDF and a free
> FPDI alternative for import/stamp — including xref-stream and encrypted
> sources. Migration is mechanical: [from mpdf](docs/en/MIGRATION-FROM-MPDF.md) ·
> [from FPDI](docs/en/MIGRATION-FROM-FPDI.md).

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/conformance.yml?branch=main&label=PDF%2FA%20%C2%B7%20PDF%2FX%20%C2%B7%20visual&logo=github)](docs/en/CONFORMANCE.md)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-pdf?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-pdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-pdf)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

🌐 [Русский](docs/ru/README.md) · [中文](docs/zh/README.md) · [Deutsch](docs/de/README.md) · [English](docs/en/README.md)

---

## Contents

- [Why this library](#why-this-library)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Feature highlights](#feature-highlights)
- [Documentation](#documentation)
- [Performance](#performance)
- [Requirements](#requirements)
- [Testing](#testing)
- [License](#license)

---

## Why this library

**Licensing.** MIT is the most permissive PHP license — use the code
anywhere, including closed-source products. Compare with the main PHP
PDF stack:

| Library                  | License        | OEM / proprietary bundle |
|--------------------------|----------------|--------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ no friction |
| mpdf/mpdf                | GPL-2.0-only   | ❌ requires GPL bundle or commercial license |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ static-linking nuances |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ same as tcpdf |
| setasign/fpdf            | re-licensable  | ✅ but extras are proprietary |

**Engineering.**

- **Modern PHP 8.2+** — readonly classes, enums, named arguments, strict
  types. Clean, type-safe API surface.
- **Two-layer design** — `Pdf\Document` for low-level emission, `Build\*`
  fluent builders for high-level documents, `Document::fromHtml()` for
  HTML/CSS input.
- **Strong typography** — Knuth–Plass line breaking, TTF subsetting with
  kerning, GSUB ligatures, ToUnicode CMaps, variable font instances,
  Bidi (UAX#9), Arabic shaping, basic Indic shaping, vertical writing.
- **Widest barcode coverage** — 12 linear + 4 2D formats including rare
  Pharmacode, MSI Plessey, ITF-14, EAN-2/5 add-ons.
- **Production cryptography** — RC4-128, AES-128, AES-256 (V5 R5 and R6
  per ISO 32000-2 / PDF 2.0).
- **PKCS#7 detached signing** with placeholder /ByteRange auto-patching.
- **PDF/A-1a / 1b / 2u and PDF/X-1a / 3 / 4** conformance with embedded
  sRGB ICC profile and XMP metadata.
- **Tagged PDF / PDF/UA-ready** structure tree with H1–H6, Table/TR/TD,
  L/LI, custom RoleMap, ParentTree number tree.
- **Streaming output** to a stream resource for large documents.
- **XRef streams** (PDF 1.5+) and Object Streams for compact output.

---

## Installation

```bash
composer require dskripchenko/php-pdf
```

PHP 8.2 or later. Required extensions: `mbstring`, `zlib`, `dom`. Add
`openssl` for AES encryption or PKCS#7 signing.

---

## Quick start

### HTML → PDF

```php
use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
<h1>Invoice #1234</h1>
<p>Customer: <strong>Acme Corp</strong></p>
<table>
  <thead><tr><th>Item</th><th>Price</th></tr></thead>
  <tbody>
    <tr><td>Widget</td><td>$10.00</td></tr>
    <tr><td>Gadget</td><td>$25.00</td></tr>
  </tbody>
</table>
HTML);

$doc->toFile('invoice.pdf');
```

### Programmatic builder

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;

DocumentBuilder::new()
    ->heading(1, 'Quarterly report')
    ->paragraph('Q1 revenue exceeded the forecast by 12%.')
    ->table(function (TableBuilder $t) {
        $t->headerRow(fn (RowBuilder $r) => $r->cells(['Quarter', 'Revenue']));
        $t->row(fn (RowBuilder $r) => $r->cells(['Q1', '$330,000']));
        $t->row(fn (RowBuilder $r) => $r->cells(['Q2', '$310,000']));
    })
    ->toFile('report.pdf');
```

### Low-level emission

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();
$page->showText('Hello, world!', 72, 720, StandardFont::TimesRoman, 12);
file_put_contents('hello.pdf', $doc->toBytes());
```

---

## Feature highlights

### Input
- HTML5 subset parser via `Document::fromHtml()`.
- Block tags `<p>`, `<h1>`–`<h6>`, `<ul>`/`<ol>`/`<li>`, tables (with
  `<thead>`/`<tbody>`/`<tfoot>` and `<caption>`), `<blockquote>`, `<hr>`,
  `<pre>`, `<dl>`/`<dt>`/`<dd>`.
- Inline tags `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<del>`,
  `<sup>`/`<sub>`, `<br>`, `<a>`, `<span>`, plus `<code>`, `<kbd>`,
  `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`,
  `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- HTML5 semantic blocks (`<header>`, `<footer>`, `<article>`, `<nav>`,
  `<section>`, etc.) and legacy `<center>` / `<font>`.
- Inline CSS: color, background, font properties, text-align,
  text-decoration, text-transform, margin/padding shorthand, borders,
  line-height, text-indent.

### Layout and typography
- Knuth–Plass box–glue–penalty line breaker with adaptive penalty.
- Multi-column layout (`ColumnSet`) with column-first flow.
- Tables with rowspan, colspan, border collapse, double borders, border
  radius, cell padding.
- Headers, footers, watermarks (text and image), section breaks.
- Footnotes with page-bottom positioning.

### Fonts
- 14 Adobe base-14 fonts (WinAnsi).
- TTF embedding with on-demand subsetting (CFF and TrueType).
- Kerning, basic GSUB ligatures, ToUnicode CMap.
- Variable font instances (fvar, gvar, MVAR, HVAR, avar).
- Bidi (UAX#9), Arabic shaping, basic Indic shaping.

### Barcodes
- Linear: Code 128 (A/B/C auto, GS1-128), Code 39, Code 93, Code 11,
  Codabar, ITF/ITF-14, MSI Plessey, Pharmacode, EAN-13/EAN-8 with 2/5-
  digit add-ons, UPC-A, UPC-E.
- 2D: QR V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI, Structured
  Append, FNC1), Data Matrix ECC 200 (all sizes incl. 144×144,
  rectangular, 6 modes), PDF417 (Byte/Text/Numeric, Macro, GS1, ECI),
  Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- QR convenience factories: vCard 3.0, WiFi Joinware, mailto.

### Charts
- BarChart, LineChart, PieChart, AreaChart, DonutChart, GroupedBarChart,
  StackedBarChart, MultiLineChart, ScatterChart.

### Interactive
- AcroForm widgets: text (single / multiline / password), checkbox,
  radio, combo, list, push / submit / reset buttons, signature.
- Per-field JavaScript actions (keystroke, validate, calculate, format).
- Markup annotations: Text, Highlight, Underline, StrikeOut, FreeText,
  Square, Circle, Line, Stamp, Ink, Polygon, PolyLine.

### Security and conformance
- Encryption: RC4-128, AES-128, AES-256 (V5 R5 + R6 / PDF 2.0).
- PKCS#7 detached signing with timestamp, reason, location, signer name.
- PDF/A-1a, PDF/A-1b, PDF/A-2u with embedded sRGB ICC.
- PDF/X-1a, PDF/X-3, PDF/X-4 with /OutputIntent /S /GTS_PDFX.
- Tagged PDF / PDF/UA-ready structure tree.

### Reading and merging
- Read existing PDFs (`ReaderDocument`) — classic and stream xref, object
  streams, corrupt-xref recovery, decryption (RC4 / AES-128 / AES-256).
- Merge (`PdfMerger`) — append and reorder whole or selected pages from
  multiple files; page annotations and bookmarks carried over with internal
  links and named destinations remapped.
- Stamp overlays (watermarks, letterheads) and FPDI-style page import into a
  freshly generated document via `PageImporter::intoDocument()`.
- See the [reading & merging guide](docs/en/MERGE.md).

A complete usage walkthrough is in [docs/en/USAGE.md](docs/en/USAGE.md).

---

## Documentation

- 📖 [Usage guide](docs/en/USAGE.md) — paragraphs, tables, charts,
  barcodes, forms, encryption, signing, PDF/A.
- 🔗 [Reading & merging PDFs](docs/en/MERGE.md) — read existing files,
  append/reorder pages, stamp overlays, handle encrypted input.
- ⚖️ [Comparison vs mpdf / tcpdf / dompdf / FPDF](docs/en/COMPARISON.md) —
  feature matrix, when to choose each.
- 📊 [Benchmarks](docs/en/BENCHMARKS.md) — reproducible wall-time, memory,
  and output-size measurements.
- ✅ [Conformance](docs/en/CONFORMANCE.md) — PDF/A validated with veraPDF,
  PDF/X checked with Ghostscript, renders diffed against goldens, and
  signatures verified with OpenSSL — on every push.
- 🔀 [Migrating from mpdf](docs/en/MIGRATION-FROM-MPDF.md) — compat
  facade (`WriteHTML`/`Output` keep working) + full mapping table.
- 🔀 [Migrating from FPDI](docs/en/MIGRATION-FROM-FPDI.md) —
  `setSourceFile`/`importPage`/`useTemplate` facade; reads xref-stream
  and encrypted sources without a commercial parser.

### Examples

Runnable scripts in [`examples/`](examples/), outputs committed to
[`samples/`](samples/) — every one is render-checked in CI:

| Run | Output | Shows |
|---|---|---|
| `php examples/01-html-to-pdf.php` | [PDF](samples/01-html-to-pdf.pdf) | HTML/CSS subset → PDF |
| `php examples/02-builder.php` | [PDF](samples/02-builder.pdf) | fluent builder, tables |
| `php examples/03-barcodes.php` | [PDF](samples/03-barcodes.pdf) | QR, Code 128, EAN-13, DataMatrix |
| `php examples/04-charts.php` | [PDF](samples/04-charts.pdf) | vector pie/bar/line charts |
| `php examples/05-forms.php` | [PDF](samples/05-forms.pdf) | fillable AcroForm fields |
| `php examples/06-signature.php` | [PDF](samples/06-signature.pdf) | PKCS#7 digital signature |
| `php examples/07-pdfa.php` | [PDF](samples/07-pdfa.pdf) | PDF/A-2u (veraPDF-clean); needs `scripts/fetch-fonts.sh` |
| `php examples/08-merge.php` | [PDF](samples/08-merge.pdf) | append + reorder existing PDFs |
| `php examples/09-stamp.php` | [PDF](samples/09-stamp.pdf) | watermark/letterhead stamping |

The [torture set](examples/torture/) exercises the hard paths (Arabic,
CJK, rectangular DataMatrix, …) — see [VIEWER-MATRIX](docs/en/VIEWER-MATRIX.md).

---

## Performance

Median of 5 isolated subprocess runs (environment and versions in the report). Full
methodology and reproducer in [docs/en/BENCHMARKS.md](docs/en/BENCHMARKS.md).

<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->
| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |
|---|---:|---:|---:|---:|---:|
| HTML → PDF article (~5 pages) | **12.0 ms** | 64.1 ms | 37.4 ms | 51.7 ms | _n/a_ |
| 100-page invoice (50 rows/page) | **576 ms** | 2640 ms | 1479 ms | 9586 ms | 27.3 ms |
| Image grid (20 pages × 4 JPEGs) | **6.7 ms** | 36.3 ms | 14.5 ms | 32.8 ms | 1.0 ms |
| Hello world (single page, one paragraph) | **4.3 ms** | 28.4 ms | 12.9 ms | 11.5 ms | 0.9 ms |
<!-- bench:table:end -->

FPDF wins on the simplest scenarios (no HTML, no wrapping, no table
flow), but it cannot generate HTML→PDF and lacks UTF-8, charts,
barcodes, forms, encryption, signing.

---

## Requirements

- PHP **8.2** or later.
- Required: `ext-mbstring`, `ext-zlib`, `ext-dom`.
- Optional: `ext-openssl` (AES encryption and PKCS#7 signing).
- No external binaries — pure PHP.

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

2,000+ tests, ~119k assertions, all passing on PHP 8.2 / 8.3 / 8.4 (see the [tests workflow](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)).

---

## License

MIT — see [LICENSE](LICENSE).

Copyright © 2026 Denis Skripchenko.
