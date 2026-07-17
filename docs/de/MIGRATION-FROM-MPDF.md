# Migration von mpdf

`mpdf/mpdf` steht unter GPL-2.0-only: Die Bündelung in einem proprietären
oder OEM-Produkt erfordert entweder eine Auslieferung unter GPL oder eine
kommerzielle Lizenz. `dskripchenko/php-pdf` ist MIT — und in jedem von uns
gemessenen HTML→PDF-Szenario [schneller](BENCHMARKS.md).

Es gibt zwei Migrationsrouten; beide werden hier beschrieben.

## Route 1 — die Compat-Fassade (am schnellsten)

Für die mit Abstand häufigste mpdf-Nutzung — `WriteHTML()` + `Output()` —
genügt es, den Import zu tauschen; die Aufrufe bleiben:

```php
// vorher
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');

// nachher
$mpdf = new \Dskripchenko\PhpPdf\Compat\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');
```

Abgedeckt: wiederholte `WriteHTML()`-Aufrufe, `AddPage()`, alle vier
`Output()`-Ziele (`F` Datei / `S` String / `D` Download / `I` inline),
`SetTitle` / `SetAuthor` / `SetCreator` / `SetSubject` / `SetKeywords`
sowie die Konfigschlüssel `format` (inklusive `A4-L`-Suffixen),
`orientation`, `margin_left/right/top/bottom` (in mm, wie bei mpdf).

Bewusst **nicht** abgedeckt: mpdf-spezifische HTML-Erweiterungen
(`<pagebreak>`, `<barcode>`, `<watermarktext>`, …), `WriteHTML()`-Modi,
`SetHeader`/`SetFooter`-Shortcodes und Font-Konfigurationsarrays — dort
ist die native API (Route 2) schlicht besser. `toDocument()` liefert das
zusammengebaute native `Document`, wenn die Fassade nicht mehr reicht.

**Nicht-lateinischer Text:** mpdf bringt DejaVu mit und wählt es
automatisch. Die Standard-Engine der Fassade nutzt nur die
PDF-Base-14-Fonts (WinAnsi — nur Latein). Für Kyrillisch/Griechisch/
Arabisch/CJK eine Engine mit eingebetteten TTFs übergeben:

```php
use Dskripchenko\PhpPdf\Compat\Mpdf;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile('/path/DejaVuSans.ttf')));
$mpdf = new Mpdf([], $engine);
```

## Route 2 — die native API

| mpdf | php-pdf |
|---|---|
| `new \Mpdf\Mpdf()` | *(für HTML-Eingabe ist kein Konfig-Objekt nötig)* |
| `$mpdf->WriteHTML($html)` | `$doc = Document::fromHtml($html)` |
| `$mpdf->Output($f, 'F')` | `$doc->toFile($f)` |
| `$mpdf->Output('', 'S')` | `$doc->toBytes()` |
| `$mpdf->Output('x.pdf', 'D')` | Header senden + `echo $doc->toBytes()` (Laravel: `response()->pdf(...)` aus dskripchenko/laravel-php-pdf) |
| `$mpdf->Output('', 'I')` | Header senden + `echo $doc->toBytes()` |
| `['format' => 'A4-L', 'margin_left' => 15]` | `new Section($blocks, pageSetup: new PageSetup(paperSize: PaperSize::A4, orientation: Orientation::Landscape, margins: new PageMargins(leftPt: 15 * 72 / 25.4)))` |
| `$mpdf->SetTitle('T')` / `SetAuthor` | `new Document($section, metadata: ['Title' => 'T', 'Author' => ...])` oder `Document::fromHtml($html, metadata: [...])` |
| `$mpdf->AddPage()` | `new PageBreak`-Element zwischen den Blöcken |
| `SetHeader('text')` / `SetFooter` | `Section(headerBlocks: [...], footerBlocks: [...])` — vollwertige Blockelemente statt Shortcodes |
| `SetWatermarkText('DRAFT')` | `Section(watermarkText: 'DRAFT')` |
| eigene TTFs (`fontdata`-Konfig) | `new Engine(defaultFont: new PdfFont(TtfFile::fromFile($path)), boldFont: ..., fontProvider: ...)` an `toBytes()/toFile()` |
| `SetProtection([...], $user, $owner)` | `new Document($section, encryption: new EncryptionParams($user, $owner, ...))` |
| `PDFA`-Konfigflag | `new Document($section, pdfA: new PdfAConfig($iccPath, ...))` — [in CI mit veraPDF validiert](../en/CONFORMANCE.md) |
| digitale Signatur (über externe Tools) | eingebaut: `new Document($section, signature: new SignatureConfig($certPem, $keyPem))` |
| `<barcode code="..." type="QR">` | `new Barcode('...', BarcodeFormat::Qr)`-Element (12 lineare + 4 2D-Formate) |

## Suchen/Ersetzen-Spickzettel

| Suchen | Ersetzen durch |
|---|---|
| `use Mpdf\Mpdf;` | `use Dskripchenko\PhpPdf\Compat\Mpdf;` |
| `new Mpdf(` | `new Mpdf(` *(mit Fassaden-Import unverändert)* |
| `\Mpdf\Output\Destination::FILE` | `'F'` |
| `\Mpdf\Output\Destination::STRING_RETURN` | `'S'` |
| `\Mpdf\Output\Destination::DOWNLOAD` | `'D'` |
| `\Mpdf\Output\Destination::INLINE` | `'I'` |
| `\Mpdf\MpdfException` | `\Throwable` (php-pdf wirft SPL-Exceptions) |

## Stolpersteine

- **Fonts**: Nichts wird automatisch eingebettet. Base-14 deckt Latein ab
  (WinAnsi); alles andere braucht ein TTF über die `Engine` (siehe oben).
  Anders als bei mpdf werden TTFs standardmäßig subsettet — die Ausgabe
  bleibt klein.
- **CSS-Abdeckung** unterscheidet sich: php-pdf parst eine
  HTML5/Inline-CSS-Teilmenge (siehe [Nutzungshandbuch](USAGE.md));
  mpdf-spezifische Tags und `@page`-Regeln werden nicht erkannt. Komplexe
  `@page`-Setups wandern nach `Section`/`PageSetup`.
- **Einheiten**: mpdf-Konfigs sind mm; die native API rechnet in Punkten
  (`1 mm = 72 / 25.4 pt`). Die Compat-Fassade konvertiert selbst.
- **Temp-Verzeichnisse**: php-pdf schreibt nichts auf die Platte — kein
  `tempDir`, kein zu pflegender `ttfontdata`-Cache.
- **Exceptions**: kein `MpdfException`; Fehler werfen SPL-Exceptions
  (`InvalidArgumentException`, `LogicException`, `RuntimeException`).

Beide Routen sind durch die Testsuite abgedeckt
(`tests/Compat/MpdfCompatTest.php`) — die Beispiele oben sind die
getesteten, keine Absichtserklärungen.

---

Sprache: [English](../en/MIGRATION-FROM-MPDF.md) · [Русский](../ru/MIGRATION-FROM-MPDF.md) · [中文](../zh/MIGRATION-FROM-MPDF.md) · [Deutsch](MIGRATION-FROM-MPDF.md)
