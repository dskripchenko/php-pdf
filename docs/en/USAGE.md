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
  - [Footnotes](#footnotes)
  - [Page setup and section breaks](#page-setup-and-section-breaks)
- [HTML / CSS input](#html--css-input)
- [Custom fonts](#custom-fonts)
  - [Fonts in per-request processes](#fonts-in-per-request-processes)
- [Barcodes](#barcodes)
- [Charts](#charts)
- [Math expressions](#math-expressions)
- [SVG](#svg)
- [Hyperlinks and bookmarks](#hyperlinks-and-bookmarks)
- [Forms (AcroForm)](#forms-acroform)
- [Fill an existing form (AcroForm)](#fill-an-existing-form-acroform)
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
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

$style = new ParagraphStyle(
    alignment: Alignment::Both,   // justified
    spaceBeforePt: 6.0,
    spaceAfterPt: 6.0,
    indentLeftPt: 36.0,
    indentFirstLinePt: 18.0,
    lineHeightMult: 1.5,
    paddingTopPt: 8.0,
    paddingBottomPt: 8.0,
    backgroundColor: 'f0f0f0',
);
```

Apply it with `ParagraphBuilder::style($style)`. Padding is set per side:
`paddingTopPt`, `paddingRightPt`, `paddingBottomPt`, `paddingLeftPt`.

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

`ListFormat` covers `Bullet`, `Decimal`, `LowerLetter`, `UpperLetter`,
`LowerRoman`, `UpperRoman`.

### Images

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Supported formats: JPEG (grayscale, RGB, CMYK) and PNG — grayscale
(1–16 bit), gray + alpha, RGB and RGBA (8/16 bit, alpha via SMask) and palette
(1–8 bit, tRNS transparency via SMask), interlaced (Adam7) or not. The same image used N times across a document is
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
            $p->text('Page ')->pageNumber()->text(' of ')->totalPages());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` accepts a `PdfImage`. Both watermarks have
opacity controls via `watermarkTextOpacity()` / `watermarkImageOpacity()`.

### Footnotes

`new Footnote('text')` in a paragraph puts a numbered mark in the line and
the text at the foot of its page. Where that foot is, the section decides:

```php
use Dskripchenko\PhpPdf\Section;

new Section($body, footnotesAtPageFoot: true);   // as much room as the page's notes take
new Section($body, footnoteBottomReservedPt: 60); // a fixed area on every page
new Section($body);                               // endnotes after the section
```

With `footnotesAtPageFoot` a page without notes loses nothing, and a line
whose notes do not fit on its page moves on with them — the way a word
processor places them. `new Footnote('text', new RunStyle(sizePt: 8))` sets
the note's own text style.

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
        margins: new PageMargins(topPt: 36, rightPt: 36, bottomPt: 36, leftPt: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

Paper sizes (`PaperSize`): `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`,
`Tabloid`, `Executive`. Any other size goes in points:
`new PageSetup(customDimensionsPt: [252, 144])` (a 3.5 × 2 in card); on
`Pdf\Document` it is `addPage(customDimensionsPt: [...])` or the
constructor's `defaultCustomDimensionsPt`.

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
  `<caption>` / `<colgroup>` / `<col>`, `<blockquote>`, `<pre>`,
  `<dl>` / `<dt>` / `<dd>`; a whole document (`<html>`, `<head>`,
  `<body>`) is fine.
- Inline: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Legacy: `<center>`, `<font color face size>`, table attributes
  `border`, `cellpadding`, `cellspacing`, `width`, `align`, `bgcolor`,
  `valign`.

**CSS** — from `<style>` blocks and `style` attributes, with the cascade:

- Selectors: type, `.class`, `#id`, `*`, `[attr]` / `[attr=value]`,
  `:root`, `:first-child`, `:last-child`, `:only-child`, `:nth-child()`, `:not()`,
  descendant, `>`, `+`, `~`; specificity, `!important`. `@media print`
  and `all` apply, `@media screen` is skipped.
- Custom properties: `--name` declarations are inherited, and
  `var(--name, fallback)` substitutes them in any value, shorthands
  included; a property whose variable is missing and has no fallback is
  dropped, as in a browser.
- Inherited, as in a browser: `color`, `font-*`, `text-decoration`,
  `text-transform`, `letter-spacing`, `text-align`, `line-height`,
  `text-indent`, `white-space` — set on `body`, a `div` or a `td`, they
  reach the text inside.
- Text: `color`, `background-color` (painted under the text),
  `font-size` (pt, px, mm, cm, in, em, rem, %, keywords), `font-family`,
  `font-weight` (`normal` switches bold off), `font-style`,
  `text-decoration`, `text-transform`, `letter-spacing`,
  `vertical-align: super / sub`, `white-space` (`pre`, `pre-wrap`,
  `pre-line`, `nowrap`). `&nbsp;` is kept.
- Blocks: `margin`, `padding`, `border` (shorthand, per side, `-width`,
  `-style`, `-color`), `background-color`, `text-align`, `line-height`
  (multiplier, %, length), `text-indent`, `display: none / block / flex /
  grid`.
- Containers: a `div` (`section`, …) holding blocks keeps them as
  blocks; its margin and padding space and indent them, a background or a
  border draws a box around them. `display: flex` puts the children side
  by side (`gap`, `width` / `flex-basis`, `align-items`); with
  `flex-wrap: wrap` they go into as many lines as their widths need,
  `flex-direction: column` stacks them. `display: grid` lays the children
  out row by row in the columns of `grid-template-columns` (lengths, `%`,
  `fr`, `auto`, `repeat()`, `minmax()`), `grid-column: span n` (or
  `a / b`) spans columns, `gap` / `row-gap` / `column-gap` separate them.
- Floats and positioning: `float: left / right` puts the element and the
  content after it side by side, up to an element with `clear`; the text
  does not run on under a float that ends early. `position: absolute`
  places the element by `top` / `right` / `bottom` / `left` in the page
  area — or from the start of a positioned (`relative`, …) ancestor —
  without taking room; `position: fixed` does the same on every page from
  there on. `position: relative` offsets themselves are not applied.
- `break-inside: avoid` (`page-break-inside`) on any block keeps it on one
  page; a block taller than a page still splits.
- Generated content: `::before` / `::after` (also `:before` / `:after`)
  with `content` — strings with CSS escapes, `attr(name)`, `open-quote` /
  `close-quote`, and the page counters `counter(page)` / `counter(pages)`
  (page number, page count) — styled by their own rules; other counters
  are not supported.
- Style sheets: `<style>` and `<link rel="stylesheet">` in document order;
  a link may point to a local file (unless `localFiles: false`) or a
  `data:text/css` URL — remote style
  sheets are not fetched (the parser makes no network requests).
- Tables: `width`, `table-layout` (`auto` sizes columns by their content,
  as browsers do), `border-collapse`, `border-spacing`, `border`, `margin`
  (`auto` centres); `<col>` widths; cells: `border`, `background-color`,
  `padding`, `vertical-align`, `width`, `height`, `colspan`, `rowspan`;
  rows: `height`, `break-inside: avoid`. `<thead>` rows repeat on every
  page.
- Lists: `<ol start>`, `type` and `list-style-type` (decimal, alpha,
  roman, `none`), `<li value>`; `::marker { content }` with strings,
  `counter(list-item)` and `counters(list-item, ".")` labels the items —
  `3.1.` under item 3, the way Word's multilevel numbering is written; an
  item with `list-style-type: none` and no content of its own only carries
  its nested list. A list's `padding-left` / `margin-left` and an item's
  `margin-left` place the items' text; the label hangs in front of it and
  a tab after it puts the text on the indent, as Word does. A list's
  `margin-top` / `margin-bottom` and an item's margins give the space
  around them.
- Images: `width` / `height` (`%` of the line), `display: block` with
  `margin: auto` centres. An `<svg>` in a line of text is drawn as a block
  of its own: the paragraph splits around it, and it takes the text-align.
- Pages: `page-break-before` / `-after` (`break-before` / `-after`);
  `@page { size; margin }` sets the page of `Document::fromHtml()`
  unless a `$sectionTemplate` is given.

`em` and `%` refer to the current font size; where nothing sets one, to
`$baseFontSizePt` of `Document::fromHtml()` (12 by default) — pass the
engine's `defaultFontSizePt` there.

**Not supported:** remote style sheets and `@import`,
`grid-template-areas` and explicit grid row placement, `position: relative`
offsets, text running on under a float, `:hover` and other dynamic
pseudo-classes, forms, JavaScript.

### Extending the parser

An application can decide what an element becomes before the parser does
— a signature field, a footnote, a barcode, a custom tag — and where
images come from:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Html\ElementHandler;
use Dskripchenko\PhpPdf\Html\HtmlContext;

final class TemplateElements implements ElementHandler
{
    public function handle(\DOMElement $element, HtmlContext $context): ?array
    {
        return match (true) {
            $element->hasAttribute('data-signature') => [new SignatureField($element->getAttribute('data-signature'))],
            $context->inline && str_contains($element->getAttribute('class'), 'footnote') => [new Footnote(trim($element->textContent))],
            default => null, // the parser's own reading
        };
    }
}

$doc = Document::fromHtml($html,
    elementHandlers: [new TemplateElements],
    imageSource: fn (string $src): ?string => str_starts_with($src, 'storage://') ? $storage->get(substr($src, 10)) : null,
);
```

Handlers are asked in order about every element — block-level ones
between blocks, the rest where text flows (`$context->inline`); the first
non-null answer wins. `$context->style` is the text style at the element,
and `$context->inlines($element)` / `$context->blocks($element)` read its
content as the parser would, so a handler can wrap it. `imageSource` gets
each `src` first; returning null falls back to `data:` URLs and readable
local files.

A block returned for an element in a line of text — a signature field
for an `<img>` inside `<p>` — splits the paragraph there: the text before
and after it become paragraphs of their own, the first keeping the space
above, the last the space below. `$context->alignment` is the text-align
in effect, for placing such a block.

For HTML from untrusted authors, pass `localFiles: false`: `<img src>`
and `<link href>` then read nothing from the disk — only `data:` URLs and
`imageSource`.

Page numbers need no handler: `content: counter(page)` and
`counter(pages)` in `::before` / `::after` print the page number and the
page count —
`.page-number::after { content: "Page " counter(page) " of " counter(pages) }`.

---

## Custom fonts

Without an embedded font, text is set in the base-14 fonts (WinAnsi:
Western European Latin; other characters show as `?`): Helvetica — or
the `fallbackStandard` family — in the run's weight and slant, Times for a
serif `fontFamily`, Courier for a monospace one, measured with their real
widths. The font for body text is the engine's `defaultFont`, with
optional `boldFont`, `italicFont` and `boldItalicFont`:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(
    defaultFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans.ttf')),
    boldFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans-Bold.ttf')),
    // Words the main font has no glyphs for (CJK here) are set in a fallback.
    fallbackFonts: [new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DroidSansFallback.ttf'))],
);

DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — Γειά σου κόσμε — 你好世界')
    ->toFile('fonts.pdf', $engine);
```

`fallbackFonts` is tried word by word: a word the main font cannot draw
is set in the first fallback that has all its glyphs.

A `FontProvider` serves text that names a font family — a run's
`RunStyle(fontFamily: ...)` or CSS `font-family` (the first family of
the list the provider has; without an embedded font, generic names pick
the base-14 face — `serif` Times, `monospace` Courier). It does not change the default font:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Font\ChainedFontProvider;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdfFontsLiberation\LiberationFontProvider;

$fonts = new ChainedFontProvider(
    new DirectoryFontProvider(__DIR__.'/fonts'),  // DejaVuSans.ttf, DejaVuSans-Bold.ttf, …
    new LiberationFontProvider,                  // dskripchenko/php-pdf-fonts-liberation
);

$engine = new Engine(
    defaultFont: new PdfFont($fonts->resolve('LiberationSerif-Regular')),
    boldFont: new PdfFont($fonts->resolve('LiberationSerif-Bold')),
    fontProvider: $fonts,
);

Document::fromHtml(<<<'HTML'
<p>Body text in Liberation Serif.</p>
<p style="font-family: DejaVuSans">DejaVu Sans, <b>bold</b>.</p>
<p style="font-family: Arial">Arial → Liberation Sans.</p>
HTML)->toFile('fonts-provider.pdf', $engine);
```

`DirectoryFontProvider` scans a directory for `.ttf` / `.otf` files and resolves
them by PostScript name (`DejaVuSans`, `DejaVuSans-Bold`). For a family
`X` the engine asks for `X-BoldItalic`, `X-Bold`, `X-Italic`,
`X-Regular`, then `X`, as the run's weight and style need.
`ChainedFontProvider` asks its providers in order and takes the first
font found — it resolves names, not missing glyphs; per-glyph fallback is
`fallbackFonts`. [dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation)
ships `LiberationFontProvider`: Liberation Sans, Serif and Mono, also
under the names `Arial`, `Times New Roman` and `Courier New`.

TrueType-outline fonts (`.ttf`, TrueType-flavoured `.otf`) are subset on
demand — only the glyphs you used end up in the file. CFF-based OpenType
fonts (`.otf` with a `CFF ` table) are embedded whole, without subsetting,
as a CIDFontType0 with a `/FontFile3 /CIDFontType0C` program (allowed in
PDF/A-1 too); expect the full CFF size in the output (100–200 KB for a Latin
plus Cyrillic face). CFF2 (variable CFF) fonts are rejected. Kerning, basic ligatures, and ToUnicode CMaps are emitted
automatically.

### Fonts in per-request processes

`TtfFile::fromFile()` keeps parsed fonts for the life of the process
(`TtfFile::$cacheLimit` of them), so a queue worker or Octane parses each
font once. Under PHP-FPM every request starts empty and parses its fonts
again — about a millisecond for a Latin face, several for a CJK one.
`exportState()` saves a parsed font as plain data (ints, strings, bools
and arrays), and `TtfFile::fromState()` restores it from that data and the
same font file without parsing:

```php
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

function cachedFont(string $path, string $cacheDir): TtfFile
{
    $bytes = (string) file_get_contents($path);
    $file = $cacheDir.'/'.basename($path).'.v'.TtfFile::STATE_FORMAT.'.php';

    if (is_file($file)) {
        try {
            return TtfFile::fromState(require $file, $bytes);
        } catch (\InvalidArgumentException) {
            // another font file or a damaged state: parse again below
        }
    }

    $font = new TtfFile($bytes);
    $tmp = $file.'.'.getmypid();
    file_put_contents($tmp, '<?php return '.var_export($font->exportState(), true).';');
    rename($tmp, $file);

    return $font;
}

$regular = new PdfFont(cachedFont(__DIR__.'/fonts/DejaVuSans.ttf', __DIR__.'/var/fonts'));
```

A state written with `var_export()` and read back with `require` sits in
OPcache's shared memory, so the restore costs a fraction of a parse. JSON
works too; with `serialize()`, read it back with
`unserialize($s, ['allowed_classes' => false])` — a state never holds
objects.

The state is for your application's own cache: `fromState()` checks its
shape, not its integrity, so never restore one that came from outside.
It refuses — with an `InvalidArgumentException` — a state of another
`TtfFile::STATE_FORMAT`, of another font file, or a damaged one; fall back
to parsing, as above. The pair is experimental: a release that changes
the layout bumps `STATE_FORMAT`, the cache misses once and fills again,
and nothing else breaks. Keying the cache by `STATE_FORMAT`, as above,
keeps states of different versions apart.

Embedded font bodies are compressed at zlib level 6. Where documents are
rendered per request, `PdfFont::$compressionLevel = 1;` compresses a
subset two to five times faster for a stream a few percent larger. Values
outside -1..9 are refused with an `InvalidArgumentException`.

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

$vcard = QrEncoder::vCard([
    'name' => 'Jane Doe',
    'org' => 'Acme Corp',
    'email' => 'jane@example.com',
    'tel' => '+1-555-0100',
]);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$email = QrEncoder::email('hello@example.com', subject: 'Hi');
```

`vCard()` (3.0) takes `name`, `org`, `title`, `tel`, `email`, `url` and
`address`. The other factories are `url()`, `sms($phone, $message)` and
`geo($latitude, $longitude, zoom: ...)`.

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

`<text>` is set in the document's fonts — the same family chain, fallbacks
and bold/italic faces as a paragraph — so Cyrillic and other non-Latin text
prints as it does in the body; without an embedded font it falls back to a
base-14 face, which has Latin glyphs only. `font-family`, `font-weight`,
`font-style`, `font-size`, `fill` and `text-anchor` are read from the
element, its `style` or the nearest ancestor; a group's `transform` turns and
scales the glyphs with the shapes. Not supported: `<tspan>` positioning,
`<textPath>`, `dx`/`dy`.

Inline SVG works inside HTML too — `<svg>` tags pass through to the
SVG renderer.

---

## Hyperlinks and bookmarks

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Contents')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site')
          ->text(', or jump to ')->internalLink('terms', 'the terms');
    })
    ->heading(1, 'Chapter 1')          // h1–h6 build the outline
    ->heading(2, 'Section 1.1')
    ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('terms')->text('Terms apply.'))
    ->toFile('linked.pdf');
```

The outline panel (bookmarks) is built from the headings: `heading(1, …)`
is a top-level entry, `heading(2, …)` nests under it, and so on down to
level 6. `ParagraphBuilder::bookmark($name)` marks a named destination;
`internalLink($name, …)` jumps to it, `link($url, …)` opens a URI. On a
`Pdf\Page` there are also `addExternalLink()`, `addInternalLink()`,
`addNamedActionLink()`, `addJavaScriptLink()` and `addLaunchLink()` for
absolutely positioned link areas.

Heading auto-anchors: `<h1 id="intro">` becomes a named destination, so
`<a href="#intro">jump</a>` works inside HTML input.

---

## Forms (AcroForm)

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\FormField;

DocumentBuilder::new()
    ->heading(1, 'Application form')
    ->block(new FormField('full_name', tooltip: 'Full name'))
    ->block(new FormField('agree', FormField::TYPE_CHECKBOX, widthPt: 14, heightPt: 14))
    ->block(new FormField('country', FormField::TYPE_COMBO,
        defaultValue: 'US', options: ['US', 'UK', 'DE', 'RU', 'CN']))
    ->block(new FormField('send', FormField::TYPE_SUBMIT_BUTTON, widthPt: 80, heightPt: 28,
        buttonCaption: 'Submit', submitUrl: 'https://example.com/submit'))
    ->toFile('form.pdf');
```

`FormField` is a block in the flow: it takes `name`, `type` (text by
default), `defaultValue`, `widthPt` / `heightPt` (200 × 20 by default),
`tooltip`, `required`, `readOnly`, `options` and the script and button
arguments below. To place a field at absolute coordinates, add it to a
`Pdf\Page`:

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$page = $doc->addPage();
$page->addFormField('text', 'full_name', x: 100, y: 700, width: 200, height: 24);
$page->addFormField('checkbox', 'agree', x: 100, y: 660, width: 14, height: 14, defaultValue: 'on');
$doc->toFile('form-absolute.pdf');
```

Field types: `text`, `text-multiline`, `password`, `checkbox`,
`radio-group`, `combo`, `list`, `push`, `submit`, `reset`, `signature`.

Per-field JavaScript hooks: `keystrokeScript`, `validateScript`,
`calculateScript`, `formatScript`, `clickScript`. Document-level
events: `WC` (WillClose), `WS` (WillSave), `DS` (DidSave), `WP`
(WillPrint), `DP` (DidPrint).

---

## Fill an existing form (AcroForm)

The above authors a brand-new form. To open a PDF someone else produced —
an uploaded template, a government form — and fill in the values of its
already-defined fields by name, use `ExistingFormFiller` instead:

```php
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;

ExistingFormFiller::fromFile('template.pdf')
    ->useFont('fonts/LiberationSans-Regular.ttf') // for non-Latin values
    ->setValues([
        'full_name' => 'Jane Roe',
        'agree' => true,
        'employer.name' => 'Acme Corp', // resolved via /Parent inheritance
        'country' => 'Germany',         // combo box option (export value or label)
    ])
    ->stampImage(0, 'signature.png', x: 100, y: 600, width: 120, height: 40)
    ->flatten()
    ->toFile('filled.pdf');
```

The source document is never mutated — every call works on a deep copy of
the *entire* object graph, so anything not touched (outlines, metadata,
other annotations) survives untouched into the output. `fields()` returns
each field's fully-qualified dotted name, type, current value, accepted
options, `/MaxLen` and `/TU` tooltip, so a caller can discover a template's
shape before filling it:

```php
foreach (ExistingFormFiller::fromFile('template.pdf')->fields() as $name => $field) {
    echo "{$name}: {$field->type} = ".json_encode($field->value)."\n";
}
```

Values by field type:

| Type | Accepted value |
|---|---|
| `text`, `text-multiline` | a string; `/MaxLen` is enforced |
| `checkbox` | `true`/`false`, `on`/`yes`/`true`/`1`, `off`/`no`/`false`/`0`, or its export value |
| `radio` | one of `options` (case insensitive), or `Off` |
| `combo`, `list` | an option's export value or its label; a list of them for a multi-select list box |
| `signature`, `push` | cannot be set — `LogicException` |

An unknown option is rejected with an `InvalidArgumentException` rather than
silently ignored.

Filled text and choice fields get a freshly generated appearance stream —
the field's `/DA` font, size (auto size included), colour and alignment, its
`/MK` background and border, comb cells, multi-line wrapping — so the value
shows in every viewer, not only those that honour `/NeedAppearances`. When
the form's own font cannot show a value (Cyrillic, Greek, CJK, …), the
value is drawn with an embedded TrueType font: pass one to `useFont()` (or a
`FontProvider` to `useFontProvider()`), or install
`dskripchenko/php-pdf-fonts-liberation`, which is then picked up
automatically. Without one, such a value throws a `RuntimeException` naming
the field.

`flatten(?array $fieldNames = null)` turns fields into static page content
the way Acrobat does: each widget's own appearance — check marks, radio
dots, backgrounds, borders — is drawn on the page and the widget is
removed. Pass a list of names to flatten a subset and leave the rest
interactive, or omit it to flatten every widget and drop `/AcroForm`.

`stampImage()`/`stampImageBytes()` place a PNG (with alpha) or JPEG,
independent of any field — useful for a signature or photo that isn't
itself a form field. `x`/`y` is the image's top-left corner in points from
the top-left of the page *as a viewer shows it* (the CropBox, after
`/Rotate`); `pageSize($index)` returns that size.

The output is a full rewrite of the document, unencrypted. Existing digital
signatures no longer verify, and once anything changes an XFA form
definition is removed (XFA-aware viewers would otherwise show the XFA layer
instead of the filled values).

`toBytes(incremental: true)` writes only the changes after the original
file instead: signatures stay valid (see [filling a signed form](SIGNING.md#filling-a-signed-form)).

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
    x: 72, y: 716, width: 100, height: 16,
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
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$doc = Document::new();
$page = $doc->addPage();

// The signature field, shown as a stamp filled from the certificate.
$page->addSignatureField('approval', x: 72, y: 600, width: 250, height: 75,
    appearance: SignatureAppearance::simple());

$doc->sign(new SignatureConfig(
    certPem: file_get_contents('signer.crt'),
    privateKeyPem: file_get_contents('signer.key'),
    privateKeyPassphrase: 'optional',
    signerName: 'Jane Doe',
    reason: 'Document approval',
    location: 'Berlin',
));

$doc->toFile('signed.pdf');
```

The PDF is emitted with placeholder `/ByteRange` and `/Contents`, then
patched in place with the detached CMS signature (PAdES by default). Signature fields in the
layout flow, stamp design, placeholders, fonts and several signers are
covered in the [signing guide](SIGNING.md). For keys that never leave a
device or service there are ready-made signers:
[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) (HSMs,
smart cards, USB tokens),
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)
(AWS KMS, Google Cloud KMS, Azure Key Vault) and
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
(CryptoPro CSP on the server) — see [SIGNING.md](SIGNING.md#keys-and-certificates).

---

## PDF/A and Tagged PDF

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;

$doc = Document::new();
$doc->enablePdfA(new PdfAConfig(
    iccProfilePath: 'vendor/dskripchenko/php-pdf/resources/icc/sRGB2014.icc',
    part: PdfAConfig::PART_2,                 // PART_1, PART_2 or PART_3
    conformance: PdfAConfig::CONFORMANCE_U,   // or CONFORMANCE_A, CONFORMANCE_B
    title: 'Archive copy',
    author: 'Acme Corp',
    lang: 'en-US',
));
// Conformance 'A' auto-enables Tagged PDF.
$doc->addPage();
$doc->toFile('pdfa.pdf');
```

The output intent needs an sRGB ICC profile; the package ships one in
`resources/icc/sRGB2014.icc`. PDF/A-1 takes conformance A or B; PDF/A-2
and PDF/A-3 also U. PDF/A also requires every font to be embedded — the
base-14 fonts fail validation, so set text in a TTF (`PdfFont`, or an
`Engine` with `defaultFont` for laid-out documents).

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
    iccProfilePath: 'vendor/dskripchenko/php-pdf/resources/icc/sRGB2014.icc',
    variant: PdfXConfig::VARIANT_X4,
    title: 'Print master',
    trapped: PdfXConfig::TRAPPED_FALSE,
));
$doc->addPage();
$doc->toFile('print.pdf');
```

Variants: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. The bundled sRGB
profile serves X-3 and X-4; for a press condition pass its CMYK profile
(for X-1a a CMYK profile is required — none is bundled) with
`iccProfileName`, `outputConditionIdentifier` (e.g. `FOGRA39`) and
`outputCondition`. Caller is responsible for content-level compliance
(e.g. CMYK conversion for X-1a, no transparency for X-1a / X-3).

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

// Filled rectangle (x, y, width, height, RGB 0..1).
$page->fillRect(100, 100, 200, 50, r: 0.9, g: 0.1, b: 0.1);

// Outlined rectangle and a line, 2 pt wide.
$page->strokeRect(100, 300, 200, 50, lineWidthPt: 2.0);
$page->strokeLine(50, 200, 550, 200, lineWidthPt: 2.0);

$doc->metadata(title: 'Ticket', author: 'Acme Corp');
file_put_contents('low-level.pdf', $doc->toBytes());
```

Other drawing methods on `Pdf\Page`: `fillRoundedRect()`,
`strokeRoundedRect()`, `strokeDashedLine()`, `fillPolygon()`,
`strokePolyline()`, `drawImage()`.

`Pdf\Document` has no layout engine — coordinates are in PDF points
(1/72 inch), origin at the bottom-left. Use it for ticket-style or
overlay-style PDFs where positioning is fully calculated by the
caller.

Configuration on `Pdf\Document`:

- `metadata(title: ..., author: ..., subject: ..., keywords: ...,
  creator: ..., producer: ..., creationDate: ...)` — the `/Info`
  dictionary.
- `useXrefStream(true)` — emit cross-reference as a stream object
  (PDF 1.5+), ~50% smaller metadata footprint.
- `useObjectStreams(true)` — pack non-stream dictionaries into Object
  Streams, ~15–30% smaller files for metadata-heavy documents.
- `setViewerPreferences(['hideToolbar' => true, ...])`.
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`.
- `setOpenAction('fit-page', pageIndex: 3)`.
- `attachFile($name, $bytes, mimeType: 'application/json')`.

See `src/Pdf/Document.php` for the complete surface.
