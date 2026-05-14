# dskripchenko/php-pdf

> Pure-PHP, **MIT-licensed** PDF generator. Drop-in alternative для `mpdf/mpdf`
> (GPL-2.0) без GPL-friction для OEM, on-premise installer, proprietary
> bundle сценариев.

**Status:** v1.5.0 production-ready. 213+ phases, **1683 tests / 118k+
assertions**, all passing. PHP 8.2+.

---

## Содержание

- [Why this library](#why-this-library)
- [Сравнение с прямыми аналогами](#сравнение-с-прямыми-аналогами)
- [Установка](#установка)
- [Quick start](#quick-start)
- [Usage guide](#usage-guide)
  - [Базовые элементы](#базовые-элементы)
  - [Стилизация](#стилизация)
  - [Таблицы](#таблицы)
  - [Списки](#списки)
  - [Изображения](#изображения)
  - [Headers, footers, watermarks](#headers-footers-watermarks)
  - [Page setup](#page-setup)
  - [Custom fonts](#custom-fonts)
  - [Barcodes](#barcodes)
  - [Charts](#charts)
  - [Math expressions](#math-expressions)
  - [SVG](#svg)
  - [Hyperlinks + bookmarks](#hyperlinks--bookmarks)
  - [Forms (AcroForm)](#forms-acroform)
  - [Encryption](#encryption)
  - [Digital signing](#digital-signing)
  - [PDF/A and Tagged PDF](#pdfa-and-tagged-pdf)
  - [Streaming output](#streaming-output)
- [Architecture](#architecture)
- [Roadmap](#roadmap)
- [Requirements](#requirements)
- [License](#license)

---

## Why this library

### Лицензионная плоскость
| Library | License | OEM / proprietary bundle |
|---------|---------|--------------------------|
| **dskripchenko/php-pdf** | **MIT** | ✅ no friction |
| mpdf/mpdf | GPL-2.0-only | ❌ требует GPL bundle или коммерческой OEM-лицензии |
| tecnickcom/tcpdf | LGPL-2.1+ | ⚠️ можно при dynamic linking, но статически — нюансы |
| dompdf/dompdf | LGPL-2.1 | ⚠️ как tcpdf |
| setasign/fpdf | повторно лицензируется | ✅ но purchasable add-ons на FPDI proprietary |

MIT — самая permissive PHP-лицензия. Используйте библиотеку как угодно, в том
числе в закрытом продукте.

### Технические преимущества
- **Modern PHP 8.2+** — readonly classes, enums, named arguments, strict
  types. Чистый, типобезопасный код.
- **AST-based** — Document → Section → Paragraph/Table/etc. Парсеры
  HTML/Markdown подключаются как фронтэнд, рендер изолирован.
- **Сильная типографика** — variable fonts (TrueType glyf), Indic shaping,
  Arabic joining, full UAX 9 Bidi (W/N/I/L2/L3 + X1-X10 explicit
  embedding), tab stops, hanging punctuation.
- **Самое широкое покрытие barcode** — 13 linear + 4 2D форматов (включая
  редкие Codabar, Pharmacode, MSI Plessey, ITF-14, EAN-2/5 add-ons).
- **Production-grade encryption** — AES-256 V5 R6 (PDF 2.0 ISO 32000-2).
- **PKCS#7 detached signing** с streaming-режимом для seekable streams.
- **PDF/UA-ready Tagged PDF** — H1-H6, /Table/TR/TD, /L/LI, image alt-text.
- **PDF/A-1a/1b/2u** compliance с auto Tagged enforcement.
- **Streaming output** — Writer::toStream() для memory-efficient emission
  больших документов.
- **XRef streams (PDF 1.5)** — ~50% smaller metadata footprint.

---

## Сравнение с прямыми аналогами

### Feature matrix

| Feature                          | dskripchenko/<br>php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------|:--:|:----:|:-----:|:------:|:----:|
| License                          | **MIT** | GPL-2.0 | LGPL-2.1+ | LGPL-2.1 | MIT |
| PHP min                          | 8.2 | 7.4 | 7.1 | 7.1 | 4+ |
| Modern PHP (readonly, enums)     | ✅ | ❌ | ❌ | ❌ | ❌ |
| HTML/CSS input                   | ✅ `Document::fromHtml()` | ✅ | ⚠️ basic | ✅ | ❌ |
| Variable fonts (TTF glyf)        | ✅ | ❌ | ❌ | ❌ | ❌ |
| Indic shaping (8 scripts)        | ✅ | partial | ❌ | ❌ | ❌ |
| Arabic shaping                   | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Bidi UAX 9 (full X1-X10)         | ✅ | partial | partial | ❌ | ❌ |
| Tab stops, hanging punctuation   | ✅ | ❌ | ❌ | ❌ | ❌ |
| Knuth-Plass line breaking        | ❌ | ❌ | ❌ | ❌ | ❌ |
| Tables (spans, header repeat)    | ✅ | ✅ | ✅ | ✅ | ❌ |
| Charts (11 types)                | ✅ | ❌ | ✅ (TCPDF Graph) | ❌ | ❌ |
| Math (LaTeX subset)              | ✅ | ❌ | ❌ | ❌ | ❌ |
| SVG (paths, gradients, transforms) | ✅ | ✅ | ⚠️ basic | ⚠️ | ❌ |
| **Linear barcodes (13 formats)** | ✅ | ⚠️ 9 | ✅ 11 | ❌ | ❌ |
| **2D barcodes (4 formats)**      | ✅ | ⚠️ 3 (QR, DM, PDF417) | ✅ 4 | ❌ | ❌ |
| Pharmacode                       | ✅ | ❌ | ❌ | ❌ | ❌ |
| EAN-2/5 add-ons                  | ✅ | ❌ | ⚠️ (only EAN-13) | ❌ | ❌ |
| QR ECI + Structured Append       | ✅ | ❌ | ❌ | ❌ | ❌ |
| QR FNC1 (GS1+AIM)                | ✅ | ❌ | ❌ | ❌ | ❌ |
| DataMatrix ECC 200 + 6 modes     | ✅ | partial | ✅ | ❌ | ❌ |
| PDF417 (Macro + GS1 + ECI)       | ✅ | partial | ✅ | ❌ | ❌ |
| AcroForms                        | ✅ | ✅ | ✅ | ❌ | ❌ |
| AcroForm JS actions              | ✅ | ⚠️ | ✅ | ❌ | ❌ |
| RC4-128 encryption               | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| AES-128 / AES-256 R5             | ✅ | ✅ | ✅ | ❌ | ❌ |
| **AES-256 R6 (PDF 2.0)**         | ✅ | ❌ | ❌ | ❌ | ❌ |
| PKCS#7 signing                   | ✅ | ❌ | ✅ | ❌ | ❌ |
| PKCS#7 streaming                 | ✅ | — | ❌ | — | — |
| Public-key encryption (/PubSec)  | ❌ deferred | ❌ | ✅ | ❌ | ❌ |
| PDF/A-1b                         | ✅ | ✅ | ✅ | ❌ | ❌ |
| PDF/A-1a (accessible)            | ✅ | ⚠️ | ⚠️ | ❌ | ❌ |
| PDF/A-2u, 3                      | ✅ | ✅ | ⚠️ | ❌ | ❌ |
| Tagged PDF / PDF/UA              | ✅ (H1-6, Table, L) | ⚠️ partial | ⚠️ | ❌ | ❌ |
| XRef streams (PDF 1.5)           | ✅ | ❌ | ❌ | ❌ | ❌ |
| Streaming output                 | ✅ | ❌ | ❌ | ❌ | ❌ |
| Test coverage                    | **1683 tests** | manual + few unit | ~3 unit tests | unit suite | none |
| Codebase size                    | ~80k LOC | ~50k | ~30k (1 file) | ~20k | ~5k |
| Maintenance status (2026)        | active | community (slow) | maintained | maintained | community |

### Сильные стороны vs основные конкуренты

#### vs **mpdf/mpdf**
**Плюсы php-pdf:**
- MIT vs GPL-2.0 — нет лицензионного трения для proprietary продуктов
- Modern PHP 8.2+ (readonly, enums) vs PHP 7.4 + legacy patterns
- Variable fonts support (mpdf не поддерживает)
- Indic shaping для 8 скриптов (mpdf только partial)
- Knuth-Plass — обе не поддерживают, но php-pdf имеет tab stops + hanging punctuation
- Production-grade AES-256 R6 (PDF 2.0) — mpdf не поддерживает R6
- Tagged PDF/PDF/UA с полной H1-H6 + Table/TR/TD + L/LI разметкой
- Charts native (11 типов) vs mpdf без built-in charts
- Math expressions (LaTeX subset) — mpdf не имеет

**Минусы php-pdf:**
- Меньшая battle-tested база — mpdf existing 20+ лет, тысячи production
  deployments
- HTML coverage уже Phase 219 (`Document::fromHtml()`) — но скромнее
  чем mpdf на complex CSS (Flexbox, multi-column, @media). Для простых
  business documents (paragraphs, tables, lists, inline styling) — на
  paritet
- Документация и сообщество скромнее — у mpdf есть Stack Overflow корпус
  ответов

#### vs **tecnickcom/tcpdf**
**Плюсы php-pdf:**
- MIT vs LGPL-2.1+ — LGPL имеет copyleft нюансы при static linking
- Modern PHP architecture — у tcpdf один файл на 30k+ строк, legacy
- Comprehensive Indic + full Bidi — tcpdf не имеет Indic shaping
- Variable fonts (tcpdf не поддерживает)
- AES-256 R6 + Tagged PDF/UA + PDF/A-1a — у tcpdf нет
- Math expressions + native charts (11 types) — tcpdf имеет TCPDF Graph
  как отдельную dependency
- XRef streams — tcpdf использует classic xref
- Streaming output — tcpdf полностью buffer-based

**Минусы php-pdf:**
- tcpdf имеет более широкий охват annotation типов (Text, Highlight, Stamp,
  Polygon — php-pdf поддерживает, но с меньшим количеством преcetов)
- Public-key encryption (/PubSec) — у tcpdf есть, у php-pdf deferred к v1.6+
- Bigger barcode codeword library out of the box (хотя по форматам paritet)
- Existing ecosystem — TCPDF добавок (TCPDF Graph, TCPDF Connectors)

#### vs **dompdf/dompdf**
**Плюсы php-pdf:**
- Significantly faster (HTML→PDF в dompdf медленный из-за CSS engine)
- Variable fonts, Indic shaping, Arabic, Bidi — dompdf не имеет
- Charts, Math, barcodes — dompdf не поддерживает
- Encryption AES-256 R6 — dompdf не поддерживает
- PDF/A, PDF/UA, signing — dompdf не поддерживает
- AcroForms — dompdf не поддерживает

**Минусы php-pdf:**
- HTML/CSS coverage у dompdf шире (Flexbox partial, complex selectors,
  более полный CSS box model). php-pdf нацелен на business documents,
  не на rendering arbitrary HTML5
- Float layout — dompdf supports, php-pdf does not

#### vs **setasign/fpdf** (+ tFPDF)
**Плюсы php-pdf:**
- High-level API (Document/Section/Paragraph) — fpdf низкоуровневый
  (вручную позиционирование, нет text wrapping)
- UTF-8 первоклассный (fpdf требует tFPDF для UTF-8, ограниченно)
- Все вышеперечисленные features (charts, barcodes, encryption, etc.)

**Минусы php-pdf:**
- fpdf минималистичен и предсказуем — отлично для simple invoices
  и low-resource scenarios
- fpdf code size ~5k LOC vs php-pdf ~80k — для simple PDF generation fpdf
  быстрее загружается

### Когда выбрать что

- **dskripchenko/php-pdf** — proprietary продукт с PDF generation
  (MIT-friendly), нужна сильная типографика (CJK/Indic/Arabic), нужны
  charts/barcodes/math/forms, нужны современные PDF features (AES-256 R6,
  PDF/UA, XRef streams)
- **mpdf** — open-source GPL-совместимый проект с прямым HTML→PDF, давно
  есть в стеке, не хочется мигрировать
- **tcpdf** — legacy enterprise PHP-приложения, нужен public-key encryption
- **dompdf** — нужно рендерить complex HTML/CSS с float layout
- **fpdf** — простые invoice/receipt PDFs, минимальные зависимости

---

## Установка

```bash
composer require dskripchenko/php-pdf
```

Опциональные пакеты:
```bash
# OFL-лицензированные Liberation fonts (Sans/Serif/Mono, mostly Latin
# + Cyrillic), bundled в отдельный пакет для license isolation
composer require dskripchenko/php-pdf-fonts-liberation
```

---

## Quick start

### Direct HTML → PDF (simplest)

```php
use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
<h1>Invoice #1234</h1>
<p>Customer: <b>Acme Corp</b></p>
<table>
  <tr><th>Item</th><th>Price</th></tr>
  <tr><td>Widget</td><td>$10.00</td></tr>
  <tr><td>Gadget</td><td>$25.00</td></tr>
</table>
HTML);

$doc->toFile('invoice.pdf');
```

### Programmatic AST construction (full control)

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

---

## Usage guide

### HTML/CSS input

`Document::fromHtml()` parses HTML и возвращает готовый Document.

**Supported HTML5 elements:**
- Block: `<p>`, `<div>`, `<section>`, `<article>`, `<h1>`..`<h6>`,
  `<hr>`, `<ul>`/`<ol>`/`<li>`, `<table>`/`<tr>`/`<td>`/`<th>`
  (с `<thead>`/`<tbody>`/`<tfoot>`), `<blockquote>`, `<pre>`
- Inline: `<span>`, `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<strike>`/`<del>`,
  `<sup>`, `<sub>`, `<br>`, `<img>`, `<a>`

**Inline CSS (via `style` attribute):**
- `color`, `background-color` (hex #RRGGBB / #RGB / rgb() / 21 named colors)
- `font-size` (pt, px, em, mm, cm, in)
- `font-family` (first choice от comma list)
- `font-weight` (`bold`, `bolder`, 700-900 → bold)
- `font-style: italic`
- `text-decoration`: `underline`, `line-through`
- `letter-spacing`

```php
use Dskripchenko\PhpPdf\Document;

$html = <<<'HTML'
<h1 style="color: navy">Annual Report 2026</h1>
<p>
  <b>Revenue</b>: <span style="color: green">$1.2M</span>
  (<span style="color: red">-12%</span> YoY)
</p>
<table>
  <thead>
    <tr><th>Q</th><th>Revenue</th></tr>
  </thead>
  <tbody>
    <tr><td>Q1</td><td>$300K</td></tr>
    <tr><td>Q2</td><td>$310K</td></tr>
    <tr><td>Q3</td><td>$280K</td></tr>
    <tr><td>Q4</td><td>$310K</td></tr>
  </tbody>
</table>
<p>See <a href="https://example.com">company website</a> для details.</p>
HTML;

$doc = Document::fromHtml(
    html: $html,
    metadata: ['Title' => 'Annual Report 2026'],
);
$doc->toFile('report.pdf');
```

**NOT supported:** external CSS (`<link rel="stylesheet">`),
`<style>` blocks, complex selectors, `@media` queries, JavaScript,
forms, position:absolute/fixed, floats.

Для `<style>`/class support preprocess HTML через external CSS inliner:

```php
$emogrifier = new \Pelago\Emogrifier\CssInliner();
$inlinedHtml = $emogrifier->inlineCss($html);
$doc = Document::fromHtml($inlinedHtml);
```

### Базовые элементы

**Document** — top-level immutable AST root. Содержит одну Section
с body + optional дополнительные sections.

**Section** — body content + page setup + headers/footers/watermark.

**Paragraph** — block of Runs. Inline дочерние элементы — Run, Image,
Hyperlink, LineBreak.

**Run** — text fragment с RunStyle (color, font, size, bold/italic, etc).

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\{Paragraph, Run, LineBreak};
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;

$doc = new Document(new Section([
    new Paragraph([
        new Run('Hello, ', new RunStyle(bold: true)),
        new Run('PDF ', new RunStyle(italic: true, color: '0066cc')),
        new Run('world.'),
        new LineBreak,
        new Run('Multi-line text.'),
    ]),
]));

file_put_contents('out.pdf', $doc->toBytes(new Engine));
```

### Стилизация

**RunStyle** (inline):
```php
new RunStyle(
    sizePt: 14.0,
    color: 'ff0000',          // hex без #
    backgroundColor: 'ffff99',
    fontFamily: 'Arial',
    bold: true,
    italic: false,
    underline: false,
    strikethrough: false,
    superscript: false,
    subscript: false,
    letterSpacingPt: 0.5,
)
```

**ParagraphStyle** (block):
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
)
```

### Таблицы

```php
use Dskripchenko\PhpPdf\Element\{Cell, Row, Table};
use Dskripchenko\PhpPdf\Style\{Border, BorderSet, BorderStyle, CellStyle, TableStyle};

$thin = new Border(BorderStyle::Single, widthPt: 0.5, color: '000000');
$borders = new BorderSet(top: $thin, right: $thin, bottom: $thin, left: $thin);

$headerCellStyle = new CellStyle(
    borders: $borders,
    paddingTopPt: 6, paddingRightPt: 6, paddingBottomPt: 6, paddingLeftPt: 6,
    backgroundColor: 'f0f0f0',
);
$cellStyle = new CellStyle(borders: $borders);

$table = new Table(
    rows: [
        new Row([
            new Cell([new Paragraph([new Run('Header A', new RunStyle(bold: true))])], style: $headerCellStyle),
            new Cell([new Paragraph([new Run('Header B', new RunStyle(bold: true))])], style: $headerCellStyle),
        ]),
        new Row([
            new Cell([new Paragraph([new Run('Data 1')])], style: $cellStyle),
            new Cell([new Paragraph([new Run('Data 2')])], style: $cellStyle),
        ]),
    ],
    style: new TableStyle(borderCollapse: true),
);

$doc = new Document(new Section([$table]));
```

Column spans, header repeat across pages, border priority "thicker wins"
все поддерживаются.

### Списки

```php
use Dskripchenko\PhpPdf\Element\{ListNode, ListItem};
use Dskripchenko\PhpPdf\Style\ListFormat;

$list = new ListNode(
    items: [
        new ListItem([new Paragraph([new Run('First item')])]),
        new ListItem(
            children: [new Paragraph([new Run('Second item')])],
            nestedList: new ListNode(
                items: [new ListItem([new Paragraph([new Run('Nested 2.1')])])],
                format: ListFormat::LowerLetter,
            ),
        ),
        new ListItem([new Paragraph([new Run('Third item')])]),
    ],
    format: ListFormat::Decimal, // или Bullet / LowerRoman / UpperRoman / UpperLetter
    startAt: 1,
);
```

Format options: `Bullet`, `Decimal`, `LowerLetter`, `UpperLetter`,
`LowerRoman`, `UpperRoman`.

### Изображения

```php
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;

$image = new Image(
    source: PdfImage::fromPath('/path/to/photo.jpg'),
    widthPt: 200,
    heightPt: 150,
    altText: 'Описание для PDF/UA accessibility',
);

// Or directly from bytes:
$bytes = file_get_contents('photo.jpg');
$image2 = Image::fromBytes($bytes, widthPt: 200, heightPt: 150);

// Block-level usage:
$doc = new Document(new Section([$image]));

// Inline (внутри paragraph):
$para = new Paragraph([
    new Run('Image: '),
    $image, // Image implements BlockElement AND InlineElement
    new Run(' inline.'),
]);
```

Content dedup by SHA-1 hash автоматический — повторное использование
одной картинки увеличивает PDF только на новые references.

### Headers, footers, watermarks

```php
use Dskripchenko\PhpPdf\Element\Field;

$section = new Section(
    body: [
        new Paragraph([new Run('Main content.')]),
    ],
    headerBlocks: [
        new Paragraph([new Run('Page header', new RunStyle(sizePt: 10))]),
    ],
    footerBlocks: [
        new Paragraph([
            new Run('Page '),
            Field::page(),       // current page number
            new Run(' of '),
            Field::totalPages(), // total pages count
            new Run('   '),
            Field::date('dd.MM.yyyy'),
        ]),
    ],
    watermarkText: 'CONFIDENTIAL',
    watermarkTextOpacity: 0.3,
    firstPageHeaderBlocks: [], // empty list = blank header on page 1
);
```

Field factories: `Field::page()`, `Field::totalPages()`, `Field::date(format)`,
`Field::time(format)`, `Field::mergeField(name)`.

### Page setup

```php
use Dskripchenko\PhpPdf\Style\{Orientation, PageMargins, PageSetup, PaperSize};

$section = new Section(
    body: [...],
    pageSetup: new PageSetup(
        paperSize: PaperSize::A4,
        orientation: Orientation::Landscape,
        margins: new PageMargins(
            topPt: 36, rightPt: 36, bottomPt: 36, leftPt: 36,
            mirrored: true,        // для bound books / facing pages
            gutterPt: 18,
        ),
    ),
);
```

PaperSize options: `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`, `Tabloid`,
`Executive`. Кастомные размеры через `customDimensionsPt: [width, height]`:

```php
new PageSetup(
    paperSize: PaperSize::A4, // ignored если customDimensions задан
    customDimensionsPt: [612.0, 792.0], // US Letter
)
```

Convenience constructors:
- `PageMargins::all(36)` — все четыре одинаковые
- `PageMargins::fromMm(20, 15, 20, 15)` — конвертация мм → points

### Custom fonts

```php
use Dskripchenko\PhpPdf\Font\{DirectoryFontProvider, FontProvider};

// Способ 1: directory с TTF файлами
$fontProvider = new DirectoryFontProvider('/path/to/fonts/');

// Способ 2: Liberation bundle (если installed)
$fontProvider = new \Dskripchenko\PhpPdfFontsLiberation\LiberationFontProvider;

$engine = new Engine(fontProvider: $fontProvider);
$doc->toBytes($engine);
```

Resolution chain: RunStyle.fontFamily → FontProvider lookup → fallback
к variant chain (BoldItalic → Bold → Italic → Regular → bare) →
Engine.defaultFont → built-in Helvetica.

Microsoft metric aliases (Arial → LiberationSans) встроены в Liberation
provider.

### Barcodes

```php
use Dskripchenko\PhpPdf\Element\{Barcode, BarcodeFormat};

// Linear
$ean13 = new Barcode('4006381333931', BarcodeFormat::Ean13, widthPt: 200);
$code128 = new Barcode('Product-XYZ-123', BarcodeFormat::Code128, widthPt: 200);
$itf14 = new Barcode('12345678901231', BarcodeFormat::Itf, widthPt: 200);

// 2D
$qr = new Barcode('https://example.com', BarcodeFormat::Qr, widthPt: 150);
$dm = new Barcode('Data matrix content', BarcodeFormat::DataMatrix, widthPt: 150);
$pdf417 = new Barcode('PDF417 stacked', BarcodeFormat::Pdf417, widthPt: 200);

$doc = new Document(new Section([$ean13, $qr]));
```

**Поддерживаемые форматы:**

| Linear (13) | 2D (4) |
|-------------|--------|
| Code 11 | QR (V1-V10 + ECI + Structured Append + FNC1) |
| Code 39 | DataMatrix (ECC 200 + 6 modes + Macro + GS1 + ECI) |
| Code 93 | PDF417 (text/numeric/byte + Macro + GS1 + ECI) |
| Code 128 (+ GS1-128) | Aztec (Compact 1-4L + Full 5-32L) |
| Codabar (NW-7) | |
| ITF / ITF-14 GTIN | |
| EAN-8 | |
| EAN-13 (+ EAN-2/5 add-ons) | |
| UPC-A | |
| UPC-E | |
| MSI Plessey | |
| Pharmacode (Laetus) | |

Advanced QR usage:
```php
use Dskripchenko\PhpPdf\Barcode\{QrEncoder, QrEccLevel};

// Structured Append (multi-symbol concatenation)
$parity = QrEncoder::computeStructuredAppendParity('full data');
$sym1 = QrEncoder::structuredAppend('part1', position: 0, total: 3, parity: $parity);
$sym2 = QrEncoder::structuredAppend('part2', position: 1, total: 3, parity: $parity);
$sym3 = QrEncoder::structuredAppend('part3', position: 2, total: 3, parity: $parity);

// GS1 FNC1 marker
$gs1 = new QrEncoder('01095060001343528200', QrEccLevel::H, fnc1Mode: 1);

// ECI (extended channel — non-default charset)
$eci = new QrEncoder('Hello', eciDesignator: 26); // UTF-8
```

### Charts

11 chart типов. Каждый bar/slice/point = ассоциативный array с label +
value + optional color.

```php
use Dskripchenko\PhpPdf\Element\{BarChart, LineChart, PieChart};

$bar = new BarChart(
    bars: [
        ['label' => 'Jan', 'value' => 10],
        ['label' => 'Feb', 'value' => 25, 'color' => 'ff6600'],
        ['label' => 'Mar', 'value' => 18],
    ],
    widthPt: 400,
    heightPt: 250,
    xAxisTitle: 'Month',
    yAxisTitle: 'Sales',
    showGridLines: true,
    xLabelRotationDeg: 45,
);

$line = new LineChart(
    points: [
        ['label' => 'Q1', 'value' => 10],
        ['label' => 'Q2', 'value' => 25],
        ['label' => 'Q3', 'value' => 18],
    ],
    widthPt: 400,
    heightPt: 250,
    smoothed: true,        // Catmull-Rom splines
    showGridLines: true,
);

$pie = new PieChart(
    slices: [
        ['label' => 'A', 'value' => 30, 'color' => 'ff0000'],
        ['label' => 'B', 'value' => 45, 'color' => '00ff00'],
        ['label' => 'C', 'value' => 25, 'color' => '0000ff', 'explode' => 0.1],
    ],
    sizePt: 300,
    showPerimeterLabels: true,
);
```

Доступные: `BarChart`, `StackedBarChart`, `GroupedBarChart`, `LineChart`,
`MultiLineChart` (multi-series + smoothed Catmull-Rom splines),
`AreaChart`, `PieChart` (Bezier arcs + exploded slices + perimeter labels
с leader lines), `DonutChart`, `ScatterChart`.

### Math expressions

```php
use Dskripchenko\PhpPdf\Element\MathExpression;

$math = new MathExpression(
    tex: '\frac{x^2 + 1}{\sqrt{y}} = \int_0^1 f(t) dt',
    fontSizePt: 14,
);

// LaTeX environments:
$align = new MathExpression(
    tex: '\begin{align} a + b &= c \\ d - e &= f \end{align}'
);

// Matrices:
$matrix = new MathExpression(
    tex: '\begin{pmatrix} 1 & 2 \\ 3 & 4 \end{pmatrix}'
);

// Custom font (resolve через FontProvider):
$styled = new MathExpression(
    tex: 'E = mc^2',
    fontSizePt: 16,
    fontFamily: 'Times',
);
```

Поддержка: fractions, superscripts/subscripts, roots (\sqrt, \cbrt),
Greek letters (α-ω, Α-Ω), big operators (\sum, \int, \prod с limits),
environments (align/aligned/gather/eqnarray/cases/matrix/pmatrix/bmatrix/
vmatrix), nested fractions внутри superscripts.

### SVG

```php
use Dskripchenko\PhpPdf\Element\SvgElement;

$svg = new SvgElement(
    svgXml: '<svg width="200" height="100"><circle cx="100" cy="50" r="40" fill="red"/></svg>',
    widthPt: 200,
    heightPt: 100,
);
```

Поддержка: basic shapes (rect/circle/ellipse/line/polygon/polyline/path),
path commands (M/L/C/S/Q/T/H/V/A + relative), text element, transforms
(translate/scale/rotate/matrix + gradientTransform), linear/radial
gradients с multi-stop stitching, opacity, &lt;style&gt; CSS,
&lt;defs&gt;/&lt;use&gt; reuse.

### Hyperlinks, bookmarks, outline tree

```php
use Dskripchenko\PhpPdf\Element\{Bookmark, Heading, Hyperlink};

// External URL link
$link = Hyperlink::external(
    href: 'https://example.com',
    children: [new Run('Click here', new RunStyle(color: '0000ff', underline: true))],
);

// Internal named destination
$anchor = new Bookmark(name: 'chapter-1', children: [new Run('Chapter 1')]);
$internalLink = Hyperlink::internal(
    anchor: 'chapter-1',
    children: [new Run('See Chapter 1')],
);

// PDF Bookmarks panel (outline tree) — auto-generated from Heading elements
$doc = new Document(new Section([
    new Heading(1, [new Run('Chapter 1')]),  // → top-level outline entry
    new Paragraph([new Run('Content...')]),
    new Heading(2, [new Run('Section 1.1')]), // → nested under H1
    new Paragraph([new Run('Subsection content...')]),
    new Heading(1, [new Run('Chapter 2')]),
]));
```

Outline хирархия выводится автоматически из Heading level (1-6).
В Tagged PDF mode дополнительно эмиттится /H1.../H6 struct elements
для accessibility navigation.

### Forms (AcroForm)

```php
use Dskripchenko\PhpPdf\Element\FormField;

$text = new FormField(
    name: 'username',
    type: FormField::TYPE_TEXT,
    defaultValue: 'John Doe',
    widthPt: 150,
    heightPt: 20,
);

$multiline = new FormField(
    name: 'comments',
    type: FormField::TYPE_TEXT_MULTILINE,
    widthPt: 300, heightPt: 80,
);

$checkbox = new FormField(
    name: 'agree',
    type: FormField::TYPE_CHECKBOX,
);

$combo = new FormField(
    name: 'country',
    type: FormField::TYPE_COMBO,
    // options передаются через дополнительные параметры — см. tests/Pdf/AcroForm*
);

$signature = new FormField(
    name: 'signature1',
    type: FormField::TYPE_SIGNATURE,
    widthPt: 200, heightPt: 60,
);
```

Type constants: `TYPE_TEXT`, `TYPE_TEXT_MULTILINE`, `TYPE_PASSWORD`,
`TYPE_CHECKBOX`, `TYPE_RADIO_GROUP`, `TYPE_COMBO`, `TYPE_LIST`,
`TYPE_SIGNATURE`, `TYPE_SUBMIT_BUTTON`, `TYPE_RESET_BUTTON`,
`TYPE_PUSH_BUTTON`.

Поддерживаемые типы: text (single/multiline/password), checkbox, radio,
combo, list, push button, submit/reset, signature placeholder, JavaScript
actions (validate/calculate/format), default appearance (/DA).

### High-level vs low-level API

**High-level** (recommended для типичных случаев):
- `Document` → `Section` → `Paragraph`/`Table`/... AST
- One-shot rendering через `$doc->toBytes(new Engine)`

**Low-level** (для encryption, signing, Tagged PDF, PDF/A):
```php
$engine = new Engine;
$pdf = $engine->render($doc);  // → Pdf\Document
$pdf->encrypt('user');
$pdf->enableTagged();
$pdf->metadata(title: 'My Doc', author: 'Me');
file_put_contents('out.pdf', $pdf->toBytes());
```

`Engine::render($document)` возвращает mutable `Pdf\Document` instance,
на котором доступны все low-level operations. Финальный `toBytes()` или
`toStream($resource)` запускает emission.

### Encryption

Encryption работает через low-level `Pdf\Document` API после рендеринга:

```php
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;

$engine = new Engine;
$pdf = $engine->render($doc);

// RC4-128 (PDF 1.4+)
$pdf->encrypt('user-password');

// AES-128 (PDF 1.6+, recommended baseline)
$pdf->encrypt(
    userPassword: 'user',
    ownerPassword: 'owner',
    algorithm: EncryptionAlgorithm::Aes_128,
);

// AES-256 V5 R5 (PDF 1.7)
$pdf->encrypt('user', algorithm: EncryptionAlgorithm::Aes_256);

// AES-256 V5 R6 (PDF 2.0, ISO 32000-2) — current best practice
$pdf->encrypt('user', algorithm: EncryptionAlgorithm::Aes_256_R6);

// Permissions (default = print + copy)
use Dskripchenko\PhpPdf\Pdf\Encryption;
$pdf->encrypt(
    'user',
    permissions: Encryption::PERM_PRINT | Encryption::PERM_COPY,
);

file_put_contents('encrypted.pdf', $pdf->toBytes());
```

### Digital signing

```php
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$config = new SignatureConfig(
    certificatePem: file_get_contents('cert.pem'),
    privateKeyPem: file_get_contents('key.pem'),
    privateKeyPassword: 'optional-passphrase',
    reason: 'Document approval',
    location: 'Moscow, RU',
    contactInfo: 'denskrp90@gmail.com',
    signedAt: new \DateTimeImmutable, // optional override
);

$engine = new Engine;
$pdf = $engine->render($doc);
$pdf->sign($config);

// File output (streaming для seekable streams)
$pdf->toStream(fopen('signed.pdf', 'wb'));
```

Требует AcroForm с signature placeholder field (см. Forms выше). PKCS#7
detached с ByteRange + Contents placeholder, post-emit patching.

### PDF/A and Tagged PDF

```php
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;

$engine = new Engine;
$pdf = $engine->render($doc);

// PDF/A-1b (basic conformance — fonts embedded, no encryption)
$pdf->enablePdfA(new PdfAConfig(
    iccProfilePath: '/path/to/sRGB.icc',
    conformance: PdfAConfig::CONFORMANCE_B,
    title: 'My Document',
    author: 'Author Name',
    lang: 'en',
));

// PDF/A-1a (accessible — auto-enables Tagged PDF)
$pdf->enablePdfA(new PdfAConfig(
    iccProfilePath: '/path/to/sRGB.icc',
    conformance: PdfAConfig::CONFORMANCE_A,
));

// PDF/A-2u Unicode (part 2)
$pdf->enablePdfA(new PdfAConfig(
    iccProfilePath: '/path/to/sRGB.icc',
    conformance: PdfAConfig::CONFORMANCE_U,
    part: PdfAConfig::PART_2,
));
```

Tagged PDF (без PDF/A):
```php
$pdf = $engine->render($doc);
$pdf->enableTagged();
```

H1-H6 headings, /Table/TR/TD, /L/LI, /Link, /Artifact для headers/footers,
image alt-text — все интегрируется автоматически когда engine видит
Heading/Table/ListNode/Hyperlink/Image alt атрибуты.

### Streaming output

Для memory-efficient generation больших документов:

```php
$doc = new Document(...);
$engine = new Engine;
$pdf = $engine->render($doc);

// Stream к file handle (низкий memory footprint)
$fp = fopen('large.pdf', 'wb');
$pdf->toStream($fp);
fclose($fp);

// Stream к HTTP response
$pdf->toStream(fopen('php://output', 'wb'));

// Convenience wrapper
$pdf->toFile('large.pdf');
```

XRef streams (PDF 1.5+) для compact metadata:
```php
$doc = new Document(
    new Section([...]),
    useXrefStream: true, // ~50% smaller metadata
);
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  HTML / Markdown / Custom Source                            │
│         │                                                   │
│         ▼ (frontend parser — opt-in)                        │
│  AST: Document → Section → Paragraph/Table/etc.             │
│         │                                                   │
│         ▼ Layout\Engine                                     │
│  Computed boxes → glyph runs → text positioning             │
│         │                                                   │
│         ▼                                                   │
│  Pdf\Document → Pdf\Writer → PDF bytes                      │
└─────────────────────────────────────────────────────────────┘
```

**Three-layer separation:**

1. **AST layer** (`Dskripchenko\PhpPdf\*`) — immutable value objects:
   `Document`, `Section`, `Paragraph`, `Run`, `Table`, `Image`, etc.
   Никакой логики рендеринга.

2. **Layout layer** (`Dskripchenko\PhpPdf\Layout\*`) — `Engine` walks AST,
   computes layout (line breaking, page breaks, image scaling, table
   sizing), and emits drawing primitives к Pdf\Document.

3. **PDF emission layer** (`Dskripchenko\PhpPdf\Pdf\*`) — low-level PDF
   structure: `Document` (catalog/pages/resources), `Writer` (xref,
   trailer, streaming), `Encryption`, `Page`, `PdfFont`.

Layers общаются однонаправленно (AST → Layout → PDF), без backref'ов.
Это позволяет независимо тестировать каждый layer и подключать новые
input frontends (HTML parser, Markdown, etc.) или output backends.

См. [docs/adr/](docs/adr/) для Architecture Decision Records.

---

## Roadmap

См. [ROADMAP.md](ROADMAP.md) для активного backlog'а v1.6+ scope items.

См. [CHANGELOG.md](CHANGELOG.md) для истории phases.

**Высокоприоритетные v1.6 кандидаты:**
- QR V11-V40 large versions (нужен verified ISO 18004 spec source)
- DataMatrix 144×144 special interleaved layout
- Public-key encryption (/PubSec)
- Knuth-Plass optimal line breaking
- CIDFont vertical writing full integration (Adobe-Japan1 CMap)

---

## Requirements

- PHP 8.2+
- ext-mbstring
- ext-zlib
- ext-dom
- ext-openssl (для encryption и signing)

---

## License

MIT — см. [LICENSE](LICENSE).

Lambda bundle `dskripchenko/php-pdf-fonts-liberation` распространяется
под OFL (Open Font License) — изолирован от MIT-зоны host-библиотеки.
