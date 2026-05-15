# Usage guide

This guide walks through `dskripchenko/php-pdf` from the simplest
HTML-to-PDF conversion to the lowest-level page emission. Every section
is self-contained — read top to bottom for a tour, or jump straight to
the feature you need.

## Contents

- [Three entry points](#three-entry-points)
- [Building documents](#building-documents)
  - [Paragraphs and headings](#paragraphs-and-headings)
  - [Run styling](#run-styling)
  - [Tables](#tables)
  - [Lists](#lists)
  - [Images](#images)
  - [Headers, footers, watermarks](#headers-footers-watermarks)
  - [Page setup and section breaks](#page-setup-and-section-breaks)
- [HTML / CSS input](#html--css-input)
- [Custom fonts](#custom-fonts)
- [Barcodes](#barcodes)
- [Charts](#charts)
- [Math expressions](#math-expressions)
- [SVG](#svg)
- [Hyperlinks and bookmarks](#hyperlinks-and-bookmarks)
- [Forms (AcroForm)](#forms-acroform)
- [Annotations](#annotations)
- [Encryption](#encryption)
- [Digital signing](#digital-signing)
- [PDF/A and Tagged PDF](#pdfa-and-tagged-pdf)
- [PDF/X print conformance](#pdfx-print-conformance)
- [Streaming output](#streaming-output)
- [Optional content groups (layers)](#optional-content-groups-layers)
- [Low-level emission](#low-level-emission)

---

## Three entry points

The library exposes three layers, each fully usable on its own.

1. **`Document::fromHtml($html)`** — easiest. Parses HTML, lays it out,
   returns a `Document` ready to write. Good for invoices, reports, and
   any content that already exists as HTML.
2. **`Build\DocumentBuilder`** — fluent. Chain methods for paragraphs,
   tables, lists, charts, barcodes. Compiles to the same AST as the
   HTML entry point. Good when content is computed.
3. **`Pdf\Document`** — low level. Add pages, draw text and shapes at
   absolute coordinates. No layout engine, no flow. Good for ticket-
   style precise positioning.

You can mix them. `DocumentBuilder` produces an AST; the layout engine
emits the underlying `Pdf\Document`; both `toBytes()` and `toFile()`
work on every layer.

---

## Building documents

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$bytes = DocumentBuilder::new()
    ->heading(1, 'Annual Report 2026')
    ->paragraph('Revenue is up 12% year-over-year.')
    ->toBytes();
```

`toBytes()` returns the PDF as a string. `toFile($path)` writes
directly to disk and returns the byte count. `build()` returns the AST
`Document` if you want to inspect or post-process it.

### Paragraphs and headings

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Style\RunStyle;

DocumentBuilder::new()
    ->heading(1, 'Quarterly review')
    ->paragraph('First sentence.')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Second paragraph with ')
          ->text('bold', new RunStyle(bold: true))
          ->text(' and ')
          ->text('italic', new RunStyle(italic: true))
          ->text(' inline.');
    })
    ->emptyLine()
    ->horizontalRule()
    ->paragraph('Below the rule.')
    ->toFile('out.pdf');
```

### Run styling

A `Run` is a styled text fragment. `RunStyle` controls everything that
affects glyph rendering.

```php
use Dskripchenko\PhpPdf\Style\RunStyle;

$style = new RunStyle(
    sizePt: 12.0,
    color: 'ff0000',           // hex without '#'
    backgroundColor: 'ffff99',
    fontFamily: 'Helvetica',
    bold: true,
    italic: false,
    underline: false,
    strikethrough: false,
    superscript: false,
    subscript: false,
    letterSpacingPt: 0.5,
);
```

Block-level layout lives in `ParagraphStyle`:

```php
use Dskripchenko\PhpPdf\Element\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

new ParagraphStyle(
    alignment: Alignment::Justify,
    spaceBeforePt: 6.0,
    spaceAfterPt: 6.0,
    indentLeftPt: 36.0,
    indentFirstLinePt: 18.0,
    lineHeightMult: 1.5,
    paddingPt: 8.0,
    backgroundColor: 'f0f0f0',
);
```

### Tables

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;

DocumentBuilder::new()
    ->heading(2, 'Quarterly revenue')
    ->table(function (TableBuilder $t) {
        $t->columnWidths([100, 200])
          ->headerRow(fn (RowBuilder $r) =>
              $r->cells(['Quarter', 'Revenue']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q1', '$300,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q2', '$310,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q3', '$280,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q4', '$310,000']));
    })
    ->toFile('report.pdf');
```

Row spans, column spans, alignment, borders, and per-cell styling are
all supported. See `src/Build/CellBuilder.php` for the full API.

### Lists

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ListBuilder;
use Dskripchenko\PhpPdf\Style\ListFormat;

DocumentBuilder::new()
    ->bulletList(function (ListBuilder $l) {
        $l->item('Alpha');
        $l->item('Beta');
        $l->item('Gamma');
    })
    ->orderedList(function (ListBuilder $l) {
        $l->item('Step one');
        $l->item('Step two');
    }, ListFormat::Decimal)
    ->toFile('lists.pdf');
```

`ListFormat` covers Decimal, UpperRoman, LowerRoman, UpperAlpha,
LowerAlpha.

### Images

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Supported formats: JPEG, PNG (8-bit truecolor, 8-bit palette, with
alpha via SMask). The same image used N times across a document is
embedded as a single XObject (content-hash dedup).

### Headers, footers, watermarks

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\HeaderFooterBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->header(function (HeaderFooterBuilder $h) {
        $h->paragraph(fn (ParagraphBuilder $p) =>
            $p->text('Acme Corp · Confidential'));
    })
    ->footer(function (HeaderFooterBuilder $f) {
        $f->paragraph(fn (ParagraphBuilder $p) =>
            $p->text('Page ')->pageNumber()->text(' of ')->pageCount());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` accepts a `PdfImage`. Both watermarks have
opacity controls via `watermarkTextOpacity()` / `watermarkImageOpacity()`.

### Page setup and section breaks

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;

DocumentBuilder::new()
    ->pageSetup(new PageSetup(
        paperSize: PaperSize::A4,
        orientation: Orientation::Landscape,
        margins: new PageMargins(top: 36, right: 36, bottom: 36, left: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

Paper sizes: A0–A6, B0–B6, Letter, Legal, Executive, Tabloid, plus
custom via `defaultCustomDimensionsPt`.

---

## HTML / CSS input

```php
use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
<h1 style="color: navy">Annual Report 2026</h1>
<p>
  <strong>Revenue</strong>:
  <span style="color: green">$1.2M</span>
  (<span style="color: red">-12%</span> YoY)
</p>
<table>
  <thead><tr><th>Quarter</th><th>Revenue</th></tr></thead>
  <tbody>
    <tr><td>Q1</td><td>$300K</td></tr>
    <tr><td>Q2</td><td>$310K</td></tr>
  </tbody>
</table>
HTML);

$doc->toFile('report.pdf');
```

**Supported HTML5:**

- Block: `<p>`, `<div>`, `<section>`, `<article>`, `<h1>`–`<h6>`,
  `<header>`, `<footer>`, `<nav>`, `<aside>`, `<main>`, `<figure>`,
  `<figcaption>`, `<hr>`, `<ul>` / `<ol>` / `<li>`, `<table>` /
  `<thead>` / `<tbody>` / `<tfoot>` / `<tr>` / `<td>` / `<th>` /
  `<caption>`, `<blockquote>`, `<pre>`, `<dl>` / `<dt>` / `<dd>`.
- Inline: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Legacy: `<center>`, `<font color face size>`.

**Supported inline CSS (`style` attribute):**

- `color`, `background-color` — hex `#rrggbb` / `#rgb`, `rgb()`, 21
  named colors.
- `font-size` (pt, px, em, mm, cm, in), `font-family` (first value
  from comma list), `font-weight` (`bold`, `bolder`, 700+),
  `font-style: italic`.
- `text-decoration` (`underline`, `line-through`), `text-transform`
  (`uppercase`, `lowercase`, `capitalize`), `text-align`,
  `text-indent`.
- `margin`, `padding` shorthand (1/2/3/4 values), `border` shorthand
  (`solid`, `double`, `dashed`, `dotted`, `none` + width + color).
- `line-height` (multiplier or percentage).

**Not supported:** external CSS (`<link rel="stylesheet">`), `<style>`
blocks, complex selectors, `@media`, JavaScript, floats,
`position: absolute / fixed`, Flexbox.

For `<style>` / class support, preprocess HTML through an external
inliner (e.g. `pelago/emogrifier`).

---

## Custom fonts

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;

$fonts = new DirectoryFontProvider(__DIR__ . '/fonts');

$bytes = DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — 你好世界 — مرحبا')
    ->toBytes(new Engine(fontProvider: $fonts));
```

`DirectoryFontProvider` scans a directory for `.ttf` / `.otf` files and
exposes them by family name. `ChainedFontProvider` lets you compose
providers so the engine can fall back to system fonts for missing
glyphs.

Embedded fonts are subset on demand — only the glyphs you used end up
in the file. Kerning, basic ligatures, and ToUnicode CMaps are emitted
automatically.

---

## Barcodes

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;

DocumentBuilder::new()
    ->barcode('ACME-1234', BarcodeFormat::Code128, heightPt: 40)
    ->barcode('https://example.com', BarcodeFormat::Qr, widthPt: 120, heightPt: 120, showText: false)
    ->toFile('barcodes.pdf');
```

QR convenience factories:

```php
use Dskripchenko\PhpPdf\Barcode\QrEncoder;

$vcard = QrEncoder::vCard(
    fullName: 'Jane Doe',
    org: 'Acme Corp',
    email: 'jane@example.com',
    phone: '+1-555-0100',
);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$mailto = QrEncoder::mailto('hello@example.com', subject: 'Hi');
```

See [COMPARISON.md](COMPARISON.md#barcodes) for the full list of 16
supported formats.

---

## Charts

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\BarChart;

DocumentBuilder::new()
    ->block(new BarChart(
        bars: [
            ['label' => 'Q1', 'value' => 300],
            ['label' => 'Q2', 'value' => 310],
            ['label' => 'Q3', 'value' => 280],
            ['label' => 'Q4', 'value' => 310],
        ],
        title: 'Quarterly revenue',
        widthPt: 400,
        heightPt: 220,
    ))
    ->toFile('chart.pdf');
```

Available chart types: `BarChart`, `LineChart`, `PieChart`, `AreaChart`,
`DonutChart`, `GroupedBarChart`, `StackedBarChart`, `MultiLineChart`,
`ScatterChart`. Each accepts axis titles, label rotation, grid lines,
legend, colors, and smoothing where applicable.

---

## Math expressions

A LaTeX subset is rendered into PDF via `MathExpression`:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\MathExpression;

DocumentBuilder::new()
    ->block(new MathExpression('\frac{a^2 + b^2}{c^2} = 1'))
    ->block(new MathExpression('\sum_{i=1}^{n} i = \frac{n(n+1)}{2}'))
    ->block(new MathExpression('\begin{pmatrix} a & b \\ c & d \end{pmatrix}'))
    ->toFile('math.pdf');
```

Supported: fractions, sqrt, super/subscript, big operators (sum,
product, integral), matrices (`pmatrix`, `bmatrix`, `vmatrix`),
multi-line environments (`align`, `gather`, `cases`).

---

## SVG

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\SvgElement;

DocumentBuilder::new()
    ->block(new SvgElement(file_get_contents('logo.svg'), widthPt: 200))
    ->toFile('svg.pdf');
```

Supported SVG: paths (full path syntax incl. arcs and Bézier curves),
shapes (`<rect>`, `<circle>`, `<ellipse>`, `<line>`, `<polyline>`,
`<polygon>`), gradients (linear, radial, multi-stop), transforms
(`translate`, `scale`, `rotate`, `skewX`, `skewY`, `matrix`),
`<use>` / `<defs>`, CSS class styling.

Inline SVG works inside HTML too — `<svg>` tags pass through to the
SVG renderer.

---

## Hyperlinks and bookmarks

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Top')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site');
    })
    ->bookmark('Chapter 1', level: 1)
    ->heading(1, 'Chapter 1')
    ->paragraph('Body...')
    ->toFile('linked.pdf');
```

Hyperlink kinds emitted: `URI`, `Dest` (named destination),
JavaScript, Launch, and named-page destinations. The outline panel
(bookmarks) is multi-level — pass `level: 2` for a subsection, `level:
3` for a sub-subsection, and so on.

Heading auto-anchors: `<h1 id="intro">` becomes a named destination, so
`<a href="#intro">jump</a>` works inside HTML input.

---

## Forms (AcroForm)

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\FormField;

DocumentBuilder::new()
    ->heading(1, 'Application form')
    ->block(new FormField(
        type: 'text',
        name: 'full_name',
        x: 100, y: 700, w: 200, h: 24,
    ))
    ->block(new FormField(
        type: 'checkbox',
        name: 'agree',
        x: 100, y: 660, w: 14, h: 14,
        defaultValue: 'on',
    ))
    ->block(new FormField(
        type: 'combo',
        name: 'country',
        x: 100, y: 620, w: 200, h: 24,
        options: ['US', 'UK', 'DE', 'RU', 'CN'],
        defaultValue: 'US',
    ))
    ->block(new FormField(
        type: 'submit',
        name: 'send',
        x: 100, y: 580, w: 80, h: 28,
        buttonCaption: 'Submit',
        submitUrl: 'https://example.com/submit',
    ))
    ->toFile('form.pdf');
```

Field types: `text`, `text-multiline`, `password`, `checkbox`,
`radio-group`, `combo`, `list`, `push`, `submit`, `reset`, `signature`.

Per-field JavaScript hooks: `keystrokeScript`, `validateScript`,
`calculateScript`, `formatScript`, `clickScript`. Document-level
events: `WC` (WillClose), `WS` (WillSave), `DS` (DidSave), `WP`
(WillPrint), `DP` (DidPrint).

---

## Annotations

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();
$page->showText('Highlighted text', 72, 720, StandardFont::Helvetica, 12);
$page->addHighlightAnnotation(
    x1: 72, y1: 720, x2: 200, y2: 735,
    contents: 'Reviewer note: confirm this number.',
    color: [1.0, 1.0, 0.4],
);
file_put_contents('annotated.pdf', $doc->toBytes());
```

Annotation kinds: `Text`, `Highlight`, `Underline`, `StrikeOut`,
`FreeText`, `Square`, `Circle`, `Line`, `Stamp`, `Ink`, `Polygon`,
`PolyLine`.

---

## Encryption

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;

$doc = Document::new();
$doc->encrypt(
    userPassword: 'secret',
    ownerPassword: 'owner',
    permissions: Encryption::PERM_PRINT | Encryption::PERM_COPY,
    algorithm: EncryptionAlgorithm::Aes_256_R6,
);
$doc->toFile('encrypted.pdf');
```

Algorithms:

| Algorithm     | V/R  | Cipher       | PDF version |
|---------------|------|--------------|-------------|
| `Rc4_128`     | V2 R3 | RC4-128      | 1.4         |
| `Aes_128`     | V4 R4 | AES-128-CBC (AESV2) | 1.6 |
| `Aes_256`     | V5 R5 | AES-256-CBC (AESV3) | 1.7 |
| `Aes_256_R6`  | V5 R6 | AES-256 + Algorithm 2.B iterative hash | 2.0 |

Permission bits: `PERM_PRINT`, `PERM_MODIFY`, `PERM_COPY`,
`PERM_ANNOTATE`, `PERM_FILL_FORMS`, `PERM_ACCESSIBILITY`,
`PERM_ASSEMBLE`, `PERM_PRINT_HIGH`.

`ext-openssl` is required for AES.

---

## Digital signing

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Element\FormField;

$doc = Document::new();
$page = $doc->addPage();

// At least one signature widget must exist.
$page->addFormField(new FormField(
    type: 'signature',
    name: 'sig1',
    x: 100, y: 100, w: 200, h: 60,
));

$doc->sign(new SignatureConfig(
    certificatePem: file_get_contents('cert.pem'),
    privateKeyPem: file_get_contents('key.pem'),
    privateKeyPassphrase: 'optional',
    signerName: 'Jane Doe',
    reason: 'Document approval',
    location: 'Berlin',
    contactInfo: 'jane@example.com',
));

$doc->toFile('signed.pdf');
```

The PDF is emitted with placeholder `/ByteRange` and `/Contents` then
patched in place after PKCS#7 detached signing.

---

## PDF/A and Tagged PDF

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;

$doc = Document::new();
$doc->enablePdfA(new PdfAConfig(
    conformance: PdfAConfig::CONFORMANCE_B,   // or CONFORMANCE_A, CONFORMANCE_U
    title: 'Archive copy',
    author: 'Acme Corp',
    lang: 'en-US',
));
// Conformance 'A' auto-enables Tagged PDF.
$doc->toFile('pdfa.pdf');
```

`enablePdfA()` is incompatible with `encrypt()` and `enablePdfX()` —
the library throws on conflict.

Tagged PDF without PDF/A:

```php
$doc->enableTagged();
$doc->setLang('en-US');
```

Tagged emission produces a `/StructTreeRoot` with `H1`–`H6`, `P`,
`Table`, `TR`, `TD`, `L`, `LI`, `Link`, plus an optional custom
`/RoleMap` (`setStructRoleMap(['MyHeading' => 'H1', ...])`).

---

## PDF/X print conformance

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfXConfig;

$doc = Document::new();
$doc->enablePdfX(new PdfXConfig(
    variant: PdfXConfig::VARIANT_X4,
    outputConditionIdentifier: 'FOGRA39',
    outputCondition: 'Coated FOGRA39',
    registryName: 'http://www.color.org',
    title: 'Print master',
    trapped: 'False',
));
$doc->toFile('print.pdf');
```

Variants: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. Caller is
responsible for content-level compliance (e.g. CMYK conversion for
X-1a, no transparency for X-1a / X-3).

---

## Streaming output

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$builder = DocumentBuilder::new();
// ... add thousands of pages ...

// Direct to a file without buffering the whole document.
$builder->toFile('/tmp/big.pdf');

// Or to any stream resource — HTTP response, php://stdout, etc.
$fp = fopen('php://output', 'wb');
$builder->build()->toStream($fp);
```

Streaming flushes the byte assembly straight to the stream instead of
buffering a full-document string in memory.

---

## Optional content groups (layers)

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$base = $doc->addLayer('Base map', defaultVisible: true);
$annotations = $doc->addLayer('Annotations', defaultVisible: false);

$page = $doc->addPage();
$page->beginLayer($base);
// ... draw the base map ...
$page->endLayer();
$page->beginLayer($annotations);
// ... draw annotations ...
$page->endLayer();
```

Layers appear in Acrobat's Layers panel; readers can toggle them on
and off.

---

## Low-level emission

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();

$page->showText('Lower left', 30, 30, StandardFont::Helvetica, 10);
$page->showText('Hello, world!', 200, 400, StandardFont::TimesRoman, 24);

// Filled rectangle.
$page->saveState();
$page->setNonStrokingColor(0.9, 0.1, 0.1);
$page->fillRectangle(100, 100, 200, 50);
$page->restoreState();

// Line.
$page->saveState();
$page->setStrokingColor(0, 0, 0);
$page->setLineWidth(2.0);
$page->moveTo(50, 200);
$page->lineTo(550, 200);
$page->stroke();
$page->restoreState();

file_put_contents('low-level.pdf', $doc->toBytes());
```

`Pdf\Document` has no layout engine — coordinates are in PDF points
(1/72 inch), origin at the bottom-left. Use it for ticket-style or
overlay-style PDFs where positioning is fully calculated by the
caller.

Configuration on `Pdf\Document`:

- `setMetadata(...)` — Title, Author, Subject, Keywords, Creator,
  Producer, CreationDate.
- `useXrefStream(true)` — emit cross-reference as a stream object
  (PDF 1.5+), ~50% smaller metadata footprint.
- `useObjectStreams(true)` — pack non-stream dictionaries into Object
  Streams, ~15–30% smaller files for metadata-heavy documents.
- `setViewerPreferences(['hideToolbar' => true, ...])`.
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`.
- `setOpenAction('fit-page', pageIndex: 3)`.
- `attachFile($name, $bytes, mimeType: 'application/json')`.

See `src/Pdf/Document.php` for the complete surface.
