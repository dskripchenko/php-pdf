# Migrating from mpdf

`mpdf/mpdf` is GPL-2.0-only: bundling it in a proprietary or OEM product
requires either shipping under GPL or negotiating a commercial license.
`dskripchenko/php-pdf` is MIT — and [faster](BENCHMARKS.md) on every
HTML-to-PDF scenario we measure.

There are two migration routes; both are covered below.

## Route 1 — the compat facade (fastest)

For the overwhelmingly common mpdf usage — `WriteHTML()` + `Output()` —
swap the import and keep the call sites:

```php
// before
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');

// after
$mpdf = new \Dskripchenko\PhpPdf\Compat\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');
```

The facade covers: repeated `WriteHTML()` calls, `AddPage()`, all four
`Output()` destinations (`F` file / `S` string / `D` download / `I`
inline), `SetTitle` / `SetAuthor` / `SetCreator` / `SetSubject` /
`SetKeywords`, and the config keys `format` (including `A4-L` suffixes),
`orientation`, `margin_left/right/top/bottom` (mm, as in mpdf).

It deliberately does **not** cover mpdf-specific HTML extensions
(`<pagebreak>`, `<barcode>`, `<watermarktext>`, …), `WriteHTML()` modes,
`SetHeader`/`SetFooter` shortcodes, or font-config arrays — that is where
the native API (route 2) is strictly better. `toDocument()` hands you the
assembled native `Document` when you outgrow the facade.

**Non-Latin text:** mpdf ships DejaVu and picks it automatically. The
facade's default engine uses the PDF base-14 fonts (WinAnsi — Latin
only). For Cyrillic/Greek/Arabic/CJK pass an engine with embedded TTFs:

```php
use Dskripchenko\PhpPdf\Compat\Mpdf;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile('/path/DejaVuSans.ttf')));
$mpdf = new Mpdf([], $engine);
```

## Route 2 — the native API

| mpdf | php-pdf |
|---|---|
| `new \Mpdf\Mpdf()` | *(no config object needed for HTML input)* |
| `$mpdf->WriteHTML($html)` | `$doc = Document::fromHtml($html)` |
| `$mpdf->Output($f, 'F')` | `$doc->toFile($f)` |
| `$mpdf->Output('', 'S')` | `$doc->toBytes()` |
| `$mpdf->Output('x.pdf', 'D')` | send headers + `echo $doc->toBytes()` (Laravel: `response()->streamDownload(...)`) |
| `$mpdf->Output('', 'I')` | send headers + `echo $doc->toBytes()` |
| `['format' => 'A4-L', 'margin_left' => 15]` | `new Section($blocks, pageSetup: new PageSetup(paperSize: PaperSize::A4, orientation: Orientation::Landscape, margins: new PageMargins(leftPt: 15 * 72 / 25.4)))` |
| `$mpdf->SetTitle('T')` / `SetAuthor` | `new Document($section, metadata: ['Title' => 'T', 'Author' => ...])` or `Document::fromHtml($html, metadata: [...])` |
| `$mpdf->AddPage()` | `new PageBreak` element between blocks |
| `SetHeader('text')` / `SetFooter` | `Section(headerBlocks: [...], footerBlocks: [...])` — full block elements, not shortcodes |
| `SetWatermarkText('DRAFT')` | `Section(watermarkText: 'DRAFT')` |
| custom TTF fonts (`fontdata` config) | `new Engine(defaultFont: new PdfFont(TtfFile::fromFile($path)), boldFont: ..., fontProvider: ...)` passed to `toBytes()/toFile()` |
| `SetProtection([...], $user, $owner)` | `new Document($section, encryption: new EncryptionParams($user, $owner, ...))` |
| `PDFA` config flag | `new Document($section, pdfA: new PdfAConfig($iccPath, ...))` — [validated with veraPDF in CI](CONFORMANCE.md) |
| digital signature (via external tools) | built-in: `new Document($section, signature: new SignatureConfig($certPem, $keyPem))` |
| `<barcode code="..." type="QR">` | `new Barcode('...', BarcodeFormat::Qr)` element (12 linear + 4 2D formats) |

## Find/replace cheat-sheet

| Find | Replace with |
|---|---|
| `use Mpdf\Mpdf;` | `use Dskripchenko\PhpPdf\Compat\Mpdf;` |
| `new Mpdf(` | `new Mpdf(` *(unchanged with the facade import)* |
| `\Mpdf\Output\Destination::FILE` | `'F'` |
| `\Mpdf\Output\Destination::STRING_RETURN` | `'S'` |
| `\Mpdf\Output\Destination::DOWNLOAD` | `'D'` |
| `\Mpdf\Output\Destination::INLINE` | `'I'` |
| `\Mpdf\MpdfException` | `\Throwable` (php-pdf throws SPL exceptions) |

## Gotchas

- **Fonts**: nothing is auto-embedded. Base-14 covers Latin (WinAnsi);
  everything else needs a TTF via `Engine` (see above). Unlike mpdf, TTFs
  are subset by default — output stays small.
- **CSS coverage** differs: php-pdf parses an HTML5/inline-CSS subset
  (see the [usage guide](USAGE.md)); mpdf-specific tags and `@page` rules
  are not recognized. Complex `@page` setups map to `Section`/`PageSetup`.
- **Units**: mpdf configs are mm; the native API is points
  (`1 mm = 72 / 25.4 pt`). The compat facade converts for you.
- **Temp dirs**: php-pdf writes nothing to disk — no `tempDir` config,
  no `ttfontdata` cache to manage or clean.
- **Exceptions**: no `MpdfException`; failures throw SPL exceptions
  (`InvalidArgumentException`, `LogicException`, `RuntimeException`).

Both compat routes are exercised by the test suite
(`tests/Compat/MpdfCompatTest.php`) — the examples above are the tested
ones, not aspirational.

---

Language: [English](MIGRATION-FROM-MPDF.md) — translations follow.
