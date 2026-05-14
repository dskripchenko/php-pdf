# dskripchenko/php-pdf

Pure-PHP, MIT-licensed PDF generator. Drop-in alternative для `mpdf/mpdf`
(GPL-2.0) — без GPL-friction для OEM, on-premise installer, proprietary
product bundle сценариев.

**Status:** v1.5.0 — production-ready. 213+ phases, 1683 tests, 118k+
assertions, всё проходит.

## Установка

```bash
composer require dskripchenko/php-pdf
```

Optional font bundles:
- `dskripchenko/php-pdf-fonts-liberation` — Liberation Sans/Serif/Mono
  (OFL — изолирована от MIT-зоны host-библиотеки)

## Quick start

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\{Paragraph, Run};
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;

$doc = new Document(new Section([
    new Paragraph([new Run('Hello, PDF world.')]),
]));

file_put_contents('hello.pdf', $doc->toBytes(new Engine));
```

## Features

### Typography & text shaping
- TrueType subset embedding (~86% smaller than full font)
- GPOS kerning (pair adjustments через TJ-arrays)
- GSUB ligatures (fi/fl/ffi + custom)
- Variable fonts (TrueType glyf — fvar/avar/HVAR/MVAR/gvar)
- Composite glyph deltas (gvar per-component dx/dy)
- Indic shaping (reph reorder, pre-base matras, Phase 137-139 + 144)
- Arabic shaping (Phase 135)
- Bidi UAX 9 implicit + X1-X10 explicit embedding/override stack
- L3 mirroring (Phase 148), L2 reordering
- Unicode line breaking (UAX 14) с soft hyphens
- Tab stops + hanging punctuation
- Letter-spacing, line-height, sup/sub sizing

### Layout
- Multi-column layout (ColumnSet)
- Multi-section documents с различными PageSetup на section
- Mirrored / gutter / custom page dimensions
- Headers / footers (per-section, first-page override)
- Watermarks (text + image, rotated, ExtGState opacity)
- Footnotes / endnotes (inline-end-of-body)
- Bookmarks panel (Outline tree)
- CSS-style borders (collapse + separated + radius + priority)
- Tables (spans, header repeat, border-collapse, border-spacing)
- Lists (bullet / ordered + nested + 5 formats)
- Hyperlinks + named destinations

### Charts (11 types)
Bar (grouped/stacked), Line (single/multi-series), Area (single/stacked),
Pie (Bezier arcs + exploded slices + perimeter labels), Donut, Scatter.
Custom axis ranges, grid lines, rotation, axis titles.

### Math
MathExpression — LaTeX subset с fractions/superscripts/radicals/
environments (align/aligned/gather/eqnarray/cases/matrix variants),
custom fontFamily.

### Barcodes
**Linear (13 formats):** Code 11, Code 39, Code 93, Code 128 + GS1-128,
Codabar (NW-7), ITF / ITF-14 (GTIN), EAN-8, EAN-13 (+ EAN-2/5 add-ons),
UPC-A, UPC-E, MSI Plessey, Pharmacode.

**2D (4 formats):**
- QR Code V1-V10 (Numeric/Alphanumeric/Byte/Kanji + Structured Append
  + ECI + FNC1 modes 1+2 + version info BCH(18,6) для V7+)
- DataMatrix ECC 200 (ASCII + C40 + Text + X12 + EDIFACT + Base 256
  + Macro 05/06 + GS1 + ECI с 1/2/3-byte designators)
- PDF417 (Byte / Text / Numeric compaction + Macro PDF417 + GS1 + ECI)
- Aztec (Compact 1-4L + Full 5-32L)

### Images & SVG
- PNG / JPEG embedding с content dedup by hash
- Inline images (text wrap, baseline alignment)
- SVG support: basic shapes, paths (C/S/Q/T/H/V/A), text, transforms

### PDF security & accessibility
- Encryption: RC4-128 V2 R3, AES-128 V4 R4, AES-256 V5 R5, V5 R6
- Public-key encryption (/PubSec) — **deferred к v1.6**
- PKCS#7 detached signing (buffered + streaming для seekable streams)
- Tagged PDF (PDF/UA минимум) — H1-H6, /Table, /TR, /TD, /L, /LI,
  image alt-text
- PDF/A-1b, PDF/A-2u, PDF/A-1a (с Tagged enforcement)

### Forms (AcroForm)
Text fields, checkbox, radio, combo, list, multiline, password,
signature placeholder, JavaScript actions.

### PDF output optimization
- FlateDecode content + font streams
- XRef streams (PDF 1.5) — binary-packed cross-reference,
  ~50% metadata size reduction
- TJ-array grouping (Phase 158)
- Page Resources dict slimming
- gstate dedup в ContentStream

### API surface
- `Document::__construct(..., useXrefStream, pdfVersion)`
- Streaming output: `Document::toStream($resource)`
- PDF version targeting: '1.4' legacy compat / '2.0' modern features
- Custom fonts через FontProvider API

## Status / Roadmap

См. [ROADMAP.md](ROADMAP.md) для подробного backlog'а v1.6+ scope items
(QR V11-V40, DataMatrix 144×144, Aztec Rune, CFF2, public-key encryption,
Knuth-Plass line breaking).

См. [CHANGELOG.md](CHANGELOG.md) для истории phases 1-69 (v0.13-v1.1).

## Architecture

HTML → AST (Section/Paragraph/Run/Table/etc.) → Layout\Engine →
Pdf\Document → Pdf\Writer → PDF bytes.

Mirrors [`dskripchenko/php-docx`](https://github.com/dskripchenko/php-docx)
architecture для consistency между PDF/DOCX render paths.

См. [docs/adr/](docs/adr/) для Architecture Decision Records.

## Requirements

- PHP 8.2+
- ext-mbstring
- ext-zlib
- ext-dom

## License

MIT — см. [LICENSE](LICENSE) (when added).
