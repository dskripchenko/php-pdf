# Anwendungsleitfaden

Dieser Leitfaden führt durch `dskripchenko/php-pdf` von der einfachsten
HTML-zu-PDF-Konvertierung bis hin zur Low-Level-Seitenerzeugung. Jeder
Abschnitt ist in sich abgeschlossen — lesen Sie von oben nach unten für
einen Rundgang oder springen Sie direkt zum benötigten Feature.

## Inhalt

- [Drei Einstiegspunkte](#drei-einstiegspunkte)
- [Dokumente erstellen](#dokumente-erstellen)
  - [Absätze und Überschriften](#absätze-und-überschriften)
  - [Run-Styling](#run-styling)
  - [Tabellen](#tabellen)
  - [Listen](#listen)
  - [Bilder](#bilder)
  - [Kopf-, Fußzeilen, Wasserzeichen](#kopf--fußzeilen-wasserzeichen)
  - [Fußnoten](#fußnoten)
  - [Seitenaufbau und Abschnittsumbrüche](#seitenaufbau-und-abschnittsumbrüche)
- [HTML- / CSS-Eingabe](#html---css-eingabe)
- [Eigene Schriftarten](#eigene-schriftarten)
  - [Schriftarten in Prozessen pro Anfrage](#schriftarten-in-prozessen-pro-anfrage)
- [Barcodes](#barcodes)
- [Diagramme](#diagramme)
- [Mathematische Ausdrücke](#mathematische-ausdrücke)
- [SVG](#svg)
- [Hyperlinks und Lesezeichen](#hyperlinks-und-lesezeichen)
- [Formulare (AcroForm)](#formulare-acroform)
- [Ein bestehendes Formular ausfüllen (AcroForm)](#ein-bestehendes-formular-ausfüllen-acroform)
- [Annotationen](#annotationen)
- [Verschlüsselung](#verschlüsselung)
- [Digitale Signatur](#digitale-signatur)
- [PDF/A und Tagged PDF](#pdfa-und-tagged-pdf)
- [PDF/X-Druckkonformität](#pdfx-druckkonformität)
- [Streaming-Ausgabe](#streaming-ausgabe)
- [Optional Content Groups (Ebenen)](#optional-content-groups-ebenen)
- [Low-Level-Ausgabe](#low-level-ausgabe)

---

## Drei Einstiegspunkte

Die Bibliothek bietet drei Schichten, die jeweils eigenständig nutzbar
sind.

1. **`Document::fromHtml($html)`** — am einfachsten. Parst HTML, legt
   es um und liefert ein schreibbereites `Document` zurück. Geeignet
   für Rechnungen, Berichte und alle Inhalte, die bereits als HTML
   vorliegen.
2. **`Build\DocumentBuilder`** — fluent. Verkette Methoden für Absätze,
   Tabellen, Listen, Diagramme, Barcodes. Kompiliert in denselben AST
   wie der HTML-Einstiegspunkt. Geeignet, wenn die Inhalte berechnet
   werden.
3. **`Pdf\Document`** — Low-Level. Seiten hinzufügen, Text und Formen
   an absoluten Koordinaten zeichnen. Keine Layout-Engine, kein
   Textfluss. Geeignet für präzise Positionierung wie bei Tickets.

Sie können diese mischen. `DocumentBuilder` erzeugt einen AST; die
Layout-Engine erzeugt daraus ein `Pdf\Document`; sowohl `toBytes()` als
auch `toFile()` funktionieren in jeder Schicht.

---

## Dokumente erstellen

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$bytes = DocumentBuilder::new()
    ->heading(1, 'Annual Report 2026')
    ->paragraph('Revenue is up 12% year-over-year.')
    ->toBytes();
```

`toBytes()` liefert das PDF als String zurück. `toFile($path)` schreibt
direkt auf den Datenträger und gibt die Byte-Anzahl zurück. `build()`
liefert das AST-`Document` zurück, wenn Sie es inspizieren oder
nachbearbeiten möchten.

### Absätze und Überschriften

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

### Run-Styling

Ein `Run` ist ein gestyltes Textfragment. `RunStyle` steuert alles,
was das Rendern der Glyphen beeinflusst.

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

Das Blocklayout liegt in `ParagraphStyle`:

```php
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

$style = new ParagraphStyle(
    alignment: Alignment::Both,   // Blocksatz
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

Angewendet wird er mit `ParagraphBuilder::style($style)`. Der Innenabstand
wird pro Seite gesetzt: `paddingTopPt`, `paddingRightPt`, `paddingBottomPt`,
`paddingLeftPt`.

### Tabellen

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

Zeilen-Spans, Spalten-Spans, Ausrichtung, Rahmen und Styling pro Zelle
werden alle unterstützt. Die vollständige API finden Sie in
`src/Build/CellBuilder.php`.

### Listen

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

`ListFormat` umfasst `Bullet`, `Decimal`, `LowerLetter`, `UpperLetter`,
`LowerRoman`, `UpperRoman`.

### Bilder

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Unterstützte Formate: JPEG (Graustufen, RGB, CMYK) und PNG — Graustufen
(1–16 Bit), Grau + Alpha, RGB und RGBA (8/16 Bit, Alpha über SMask) und Palette
(1–8 Bit, tRNS-Transparenz über SMask), mit oder ohne Interlacing (Adam7). Dasselbe Bild, das N-mal in einem Dokument
verwendet wird, wird als ein einziges XObject eingebettet (Deduplizierung
per Inhalts-Hash).

### Kopf-, Fußzeilen, Wasserzeichen

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

`->watermarkImage($image)` akzeptiert ein `PdfImage`. Beide
Wasserzeichen verfügen über Deckkraft-Steuerungen via
`watermarkTextOpacity()` / `watermarkImageOpacity()`.

### Fußnoten

`new Footnote('Text')` in einem Absatz setzt eine nummerierte Marke in die
Zeile und den Text an den Fuß ihrer Seite. Wo dieser Fuß liegt, bestimmt
der Abschnitt:

```php
use Dskripchenko\PhpPdf\Section;

new Section($body, footnotesAtPageFoot: true);   // so viel Platz, wie die Fußnoten der Seite brauchen
new Section($body, footnoteBottomReservedPt: 60); // ein fester Bereich auf jeder Seite
new Section($body);                               // Endnoten nach dem Abschnitt
```

Mit `footnotesAtPageFoot` verliert eine Seite ohne Fußnoten nichts, und
eine Zeile, deren Fußnoten nicht mehr auf ihre Seite passen, wandert mit
ihnen weiter — so, wie eine Textverarbeitung sie setzt.
`new Footnote('Text', new RunStyle(sizePt: 8))` legt den eigenen Textstil
der Fußnote fest.

### Seitenaufbau und Abschnittsumbrüche

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

Papierformate (`PaperSize`): `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`,
`Tabloid`, `Executive`. Jede andere Größe wird in Punkten angegeben:
`new PageSetup(customDimensionsPt: [252, 144])` (eine Karte mit
3,5 × 2 Zoll); bei `Pdf\Document` ist es `addPage(customDimensionsPt: [...])`
oder `defaultCustomDimensionsPt` im Konstruktor.

---

## HTML- / CSS-Eingabe

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

**Unterstütztes HTML5:**

- Block: `<p>`, `<div>`, `<section>`, `<article>`, `<h1>`–`<h6>`,
  `<header>`, `<footer>`, `<nav>`, `<aside>`, `<main>`, `<figure>`,
  `<figcaption>`, `<hr>`, `<ul>` / `<ol>` / `<li>`, `<table>` /
  `<thead>` / `<tbody>` / `<tfoot>` / `<tr>` / `<td>` / `<th>` /
  `<caption>` / `<colgroup>` / `<col>`, `<blockquote>`, `<pre>`,
  `<dl>` / `<dt>` / `<dd>`; auch ein ganzes Dokument (`<html>`,
  `<head>`, `<body>`).
- Inline: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Veraltet: `<center>`, `<font color face size>`, Tabellenattribute
  `border`, `cellpadding`, `cellspacing`, `width`, `align`, `bgcolor`,
  `valign`.

**CSS** — aus `<style>`-Blöcken und `style`-Attributen, mit Kaskade:

- Selektoren: Typ, `.class`, `#id`, `*`, `[attr]` / `[attr=value]`,
  `:root`, `:first-child`, `:last-child`, `:only-child`, `:nth-child()`, `:not()`,
  Nachfahre, `>`, `+`, `~`; Spezifität, `!important`. `@media print` und
  `all` gelten, `@media screen` wird übersprungen.
- Benutzerdefinierte Eigenschaften: `--name`-Deklarationen werden
  vererbt, und `var(--name, Ersatz)` setzt sie in jeden Wert ein,
  Kurzschreibweisen eingeschlossen; eine Eigenschaft, deren Variable fehlt
  und keinen Ersatz hat, entfällt — wie im Browser.
- Vererbt wie im Browser: `color`, `font-*`, `text-decoration`,
  `text-transform`, `letter-spacing`, `text-align`, `line-height`,
  `text-indent`, `white-space` — auf `body`, einem `div` oder `td`
  gesetzt, erreichen sie den Text darin.
- Text: `color`, `background-color` (unter dem Text gemalt),
  `font-size` (pt, px, mm, cm, in, em, rem, %, Schlüsselwörter),
  `font-family`, `font-weight` (`normal` schaltet Fett ab),
  `font-style`, `text-decoration`, `text-transform`, `letter-spacing`,
  `vertical-align: super / sub`, `white-space` (`pre`, `pre-wrap`,
  `pre-line`, `nowrap`). `&nbsp;` bleibt erhalten.
- Blöcke: `margin`, `padding`, `border` (Kurzform, je Seite, `-width`,
  `-style`, `-color`), `background-color`, `text-align`, `line-height`
  (Faktor, %, Länge), `text-indent`, `display: none / block / flex /
  grid`.
- Container: ein `div` (`section`, …) mit Blöcken behält sie als Blöcke;
  Margin und Padding rücken sie ein und ab, Hintergrund oder Rahmen
  zeichnen einen Kasten darum. `display: flex` stellt die Kinder
  nebeneinander (`gap`, `width` / `flex-basis`, `align-items`); mit
  `flex-wrap: wrap` verteilen sie sich auf so viele Zeilen, wie ihre
  Breiten verlangen, `flex-direction: column` stapelt sie. `display: grid`
  ordnet die Kinder Zeile für Zeile in die Spalten von
  `grid-template-columns` ein (Längen, `%`, `fr`, `auto`, `repeat()`,
  `minmax()`), `grid-column: span n` (oder `a / b`) überspannt Spalten,
  `gap` / `row-gap` / `column-gap` trennen sie.
- Floats und Positionierung: `float: left / right` stellt das Element und
  den nachfolgenden Inhalt nebeneinander, bis zu einem Element mit
  `clear`; unter einem früher endenden Float läuft der Text nicht weiter.
  `position: absolute` platziert das Element über `top` / `right` /
  `bottom` / `left` im Seitenbereich — oder ab dem Anfang eines
  positionierten (`relative`, …) Vorfahren —, ohne Platz zu belegen;
  `position: fixed` tut dasselbe ab dort auf jeder Seite. Die Versätze von
  `position: relative` selbst werden nicht angewandt.
- `break-inside: avoid` (`page-break-inside`) an einem beliebigen Block
  hält ihn auf einer Seite; ein Block, der höher als eine Seite ist, wird
  trotzdem geteilt.
- Generierter Inhalt: `::before` / `::after` (auch `:before` / `:after`)
  mit `content` — Strings mit CSS-Escapes, `attr(name)`, `open-quote` /
  `close-quote` sowie die Seitenzähler `counter(page)` / `counter(pages)`
  (Seitenzahl, Seitenanzahl) —, gestaltet über eigene Regeln; andere
  Zähler werden nicht unterstützt.
- Stylesheets: `<style>` und `<link rel="stylesheet">` in
  Dokumentreihenfolge; ein Link darf auf eine lokale Datei (außer bei
  `localFiles: false`) oder eine
  `data:text/css`-URL zeigen — entfernte Stylesheets werden nicht geladen
  (der Parser stellt keine Netzwerkanfragen).
- Tabellen: `width`, `table-layout` (`auto` bemisst Spalten nach Inhalt
  wie Browser), `border-collapse`, `border-spacing`, `border`, `margin`
  (`auto` zentriert); `<col>`-Breiten; Zellen: `border`,
  `background-color`, `padding`, `vertical-align`, `width`, `height`,
  `colspan`, `rowspan`; Zeilen: `height`, `break-inside: avoid`.
  `<thead>`-Zeilen wiederholen sich auf jeder Seite.
- Listen: `<ol start>`, `type` und `list-style-type` (decimal, alpha,
  roman, `none`), `<li value>`; `::marker { content }` mit Strings,
  `counter(list-item)` und `counters(list-item, ".")` beschriftet die
  Einträge — `3.1.` unter Eintrag 3, so wie die mehrstufige Nummerierung
  von Word geschrieben wird; ein Eintrag mit `list-style-type: none` ohne
  eigenen Inhalt trägt nur seine verschachtelte Liste. `padding-left` /
  `margin-left` einer Liste und `margin-left` eines Eintrags legen fest, wo
  der Text der Einträge beginnt; die Beschriftung hängt davor, und ein
  Tabulator danach setzt den Text auf den Einzug, wie in Word.
  `margin-top` / `margin-bottom` einer Liste und die Ränder eines Eintrags
  bestimmen den Abstand um sie herum.
- Bilder: `width` / `height` (`%` der Zeile), `display: block` mit
  `margin: auto` zentriert. Ein `<svg>` in einer Textzeile wird als eigener
  Block gezeichnet: der Absatz wird um ihn geteilt, und er übernimmt die
  Textausrichtung.
- Seiten: `page-break-before` / `-after` (`break-before` / `-after`);
  `@page { size; margin }` legt die Seite von `Document::fromHtml()`
  fest, sofern kein `$sectionTemplate` übergeben wird.

`em` und `%` beziehen sich auf die aktuelle Schriftgröße; wo keine
gesetzt ist, auf `$baseFontSizePt` von `Document::fromHtml()`
(Standard 12) — übergeben Sie dort die `defaultFontSizePt` der Engine.

**Nicht unterstützt:** entfernte Stylesheets und `@import`,
`grid-template-areas` und explizite Grid-Zeilenplatzierung, Versätze von
`position: relative`, Text, der unter einem Float weiterläuft, `:hover`
und andere dynamische Pseudoklassen, Formulare, JavaScript.

### Den Parser erweitern

Eine Anwendung kann vor dem Parser entscheiden, was aus einem Element
wird — ein Signaturfeld, eine Fußnote, ein Barcode, ein eigenes Tag —
und woher Bilder kommen:

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

Handler werden der Reihe nach zu jedem Element befragt — Block-Elemente
zwischen Blöcken, die übrigen dort, wo Text fließt (`$context->inline`);
die erste Antwort ungleich null gewinnt. `$context->style` ist der
Textstil am Element, und `$context->inlines($element)` /
`$context->blocks($element)` lesen seinen Inhalt so, wie der Parser es
täte, damit ein Handler ihn umschließen kann. `imageSource` erhält jedes
`src` zuerst; null fällt auf `data:`-URLs und lesbare lokale Dateien
zurück.

Ein Block, den ein Handler für ein Element in einer Textzeile liefert —
ein Signaturfeld für ein `<img>` in `<p>` —, teilt den Absatz an dieser
Stelle: der Text davor und danach wird zu eigenen Absätzen, der erste
behält den Abstand oben, der letzte den unten. `$context->alignment` ist
die geltende Textausrichtung, um einen solchen Block zu platzieren.

Für HTML von nicht vertrauenswürdigen Autoren übergeben Sie
`localFiles: false`: `<img src>` und `<link href>` lesen dann nichts von
der Festplatte — nur `data:`-URLs und `imageSource`.

Seitenzahlen brauchen keinen Handler: `content: counter(page)` und
`counter(pages)` in `::before` / `::after` drucken Seitenzahl und
Seitenanzahl —
`.page-number::after { content: "Seite " counter(page) " von " counter(pages) }`.

---

## Eigene Schriftarten

Ohne eingebettete Schrift wird Text in den Base-14-Schriften gesetzt
(WinAnsi: westeuropäisches Latein, andere Zeichen erscheinen als `?`):
Helvetica — oder die Familie von `fallbackStandard` — in Stärke und Neigung
des Laufs, Times für eine Serifen-`fontFamily`, Courier für eine
Monospace-Familie, gemessen mit ihren echten Breiten. Die Schrift für den Fließtext ist `defaultFont` der Engine, mit
optionalen `boldFont`, `italicFont` und `boldItalicFont`:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(
    defaultFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans.ttf')),
    boldFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans-Bold.ttf')),
    // Wörter, für die die Hauptschrift keine Glyphen hat (hier CJK), werden in einer Fallback-Schrift gesetzt.
    fallbackFonts: [new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DroidSansFallback.ttf'))],
);

DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — Γειά σου κόσμε — 你好世界')
    ->toFile('fonts.pdf', $engine);
```

`fallbackFonts` wird Wort für Wort probiert: Ein Wort, das die Hauptschrift
nicht darstellen kann, wird in der ersten Fallback-Schrift gesetzt, die alle
seine Glyphen besitzt.

Ein `FontProvider` bedient Text, der eine Schriftfamilie benennt — ein
`RunStyle(fontFamily: ...)` eines Runs oder CSS-`font-family` (die erste
Familie der Liste, die der Provider hat; ohne eingebettete Schrift wählen
generische Namen die Base-14-Schrift — `serif` Times, `monospace`
Courier). Die Standardschrift ändert er nicht:

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

`DirectoryFontProvider` durchsucht ein Verzeichnis nach `.ttf`-/`.otf`-
Dateien und löst sie über den PostScript-Namen auf (`DejaVuSans`,
`DejaVuSans-Bold`). Für eine Familie `X` fragt die Engine je nach Gewicht
und Stil des Runs nach `X-BoldItalic`, `X-Bold`, `X-Italic`, `X-Regular`
und dann `X`. `ChainedFontProvider` fragt seine Provider der Reihe nach und
nimmt die erste gefundene Schrift — er löst Namen auf, nicht fehlende
Glyphen; der Fallback pro Glyphe ist `fallbackFonts`.
[dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation)
liefert `LiberationFontProvider`: Liberation Sans, Serif und Mono, auch
unter den Namen `Arial`, `Times New Roman` und `Courier New`.

Schriften mit TrueType-Outlines (`.ttf`, TrueType-basierte `.otf`) werden
bedarfsgerecht als Subset eingebunden — nur die tatsächlich verwendeten
Glyphen landen in der Datei. CFF-basierte OpenType-Schriften (`.otf` mit
einer `CFF `-Tabelle) werden vollständig, ohne Subsetting eingebettet, als
CIDFontType0 mit einem `/FontFile3 /CIDFontType0C`-Programm (auch in
PDF/A-1 zulässig); die Ausgabe enthält also die volle CFF-Größe
(100–200 KB für eine Schrift mit Latein und Kyrillisch). Schriften mit
CFF2 (variables CFF) werden abgelehnt. Kerning, einfache Ligaturen und ToUnicode-CMaps werden
automatisch ausgegeben.

### Schriftarten in Prozessen pro Anfrage

`TtfFile::fromFile()` hält geparste Schriftarten für die Lebensdauer des
Prozesses vor (bis zu `TtfFile::$cacheLimit` Stück): Ein Queue-Worker oder
Octane parst jede Schriftart nur einmal. Unter PHP-FPM beginnt jede Anfrage
leer und parst ihre Schriftarten erneut — etwa eine Millisekunde für eine
lateinische Schrift, mehrere für eine CJK-Schrift. `exportState()` speichert
eine geparste Schriftart als einfache Daten (Ganzzahlen, Strings, Booleans
und Arrays), und `TtfFile::fromState()` stellt sie aus diesen Daten und
derselben Schriftdatei ohne Parsen wieder her:

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
            // andere Schriftdatei oder beschädigter Zustand: unten neu parsen
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

Ein mit `var_export()` geschriebener und per `require` gelesener Zustand
liegt im gemeinsamen Speicher von OPcache, die Wiederherstellung kostet
also einen Bruchteil des Parsens. JSON funktioniert ebenso; bei
`serialize()` lesen Sie mit `unserialize($s, ['allowed_classes' => false])`
zurück — ein Zustand enthält nie Objekte.

Der Zustand ist für den eigenen Cache Ihrer Anwendung gedacht:
`fromState()` prüft seine Form, nicht seine Integrität, stellen Sie also nie
einen Zustand aus fremder Quelle wieder her. Einen Zustand mit anderem
`TtfFile::STATE_FORMAT`, von einer anderen Schriftdatei oder einen
beschädigten weist es mit einer `InvalidArgumentException` zurück; dann wird
wie oben neu geparst. Die beiden Methoden sind experimentell: Ein Release,
das das Format ändert, erhöht `STATE_FORMAT`, der Cache verfehlt einmal und
füllt sich neu, sonst bricht nichts. Ist `STATE_FORMAT` wie oben Teil des
Cache-Schlüssels, bleiben Zustände verschiedener Versionen getrennt.

Eingebettete Schriftdaten werden mit zlib-Stufe 6 komprimiert. Wo Dokumente
pro Anfrage erzeugt werden, komprimiert `PdfFont::$compressionLevel = 1;`
eine Teilmenge zwei- bis fünfmal schneller, bei einem um wenige Prozent
größeren Stream. Werte außerhalb von -1..9 werden mit einer
`InvalidArgumentException` abgelehnt.

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

QR-Komfort-Factories:

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

`vCard()` (3.0) nimmt `name`, `org`, `title`, `tel`, `email`, `url` und
`address` entgegen. Die weiteren Factories sind `url()`, `sms($phone, $message)`
und `geo($latitude, $longitude, zoom: ...)`.

Die vollständige Liste der 16 unterstützten Formate findet sich in
[COMPARISON.md](COMPARISON.md#barcodes).

---

## Diagramme

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

Verfügbare Diagrammtypen: `BarChart`, `LineChart`, `PieChart`,
`AreaChart`, `DonutChart`, `GroupedBarChart`, `StackedBarChart`,
`MultiLineChart`, `ScatterChart`. Jeder Typ akzeptiert Achsentitel,
Beschriftungsdrehung, Gitterlinien, Legende, Farben und – wo sinnvoll –
Glättung.

---

## Mathematische Ausdrücke

Ein LaTeX-Subset wird über `MathExpression` ins PDF gerendert:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\MathExpression;

DocumentBuilder::new()
    ->block(new MathExpression('\frac{a^2 + b^2}{c^2} = 1'))
    ->block(new MathExpression('\sum_{i=1}^{n} i = \frac{n(n+1)}{2}'))
    ->block(new MathExpression('\begin{pmatrix} a & b \\ c & d \end{pmatrix}'))
    ->toFile('math.pdf');
```

Unterstützt: Brüche, sqrt, Hoch-/Tiefstellung, große Operatoren
(Summe, Produkt, Integral), Matrizen (`pmatrix`, `bmatrix`, `vmatrix`),
mehrzeilige Umgebungen (`align`, `gather`, `cases`).

---

## SVG

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\SvgElement;

DocumentBuilder::new()
    ->block(new SvgElement(file_get_contents('logo.svg'), widthPt: 200))
    ->toFile('svg.pdf');
```

Unterstütztes SVG: Pfade (vollständige Pfadsyntax inkl. Bögen und
Bézierkurven), Formen (`<rect>`, `<circle>`, `<ellipse>`, `<line>`,
`<polyline>`, `<polygon>`), Verläufe (linear, radial, mehrere Stops),
Transformationen (`translate`, `scale`, `rotate`, `skewX`, `skewY`,
`matrix`), `<use>` / `<defs>`, Styling über CSS-Klassen.

`fill-rule="evenodd"` — am Element, in seinem `style`, in einem Stylesheet
oder an einer Gruppe — füllt Pfade und Polygone nach der Gerade-Ungerade-Regel:
Ein Loch, das in derselben Richtung wie sein Umriss gezeichnet ist (ein Ring,
der Buchstabe O), bleibt leer.
Verläufe füllen jede Form, in beiden `gradientUnits`; `fill`, `stroke` und
ihre Deckkraft werden von Gruppen geerbt, und verschachtelte Transformationen
werden verkettet.

`<text>` wird in den Schriften des Dokuments gesetzt — dieselbe
Familienkette, dieselben Ersatzschriften und Fett-/Kursivschnitte wie ein
Absatz —, kyrillischer und anderer nicht-lateinischer Text erscheint also wie
im Fließtext; ohne eingebettete Schrift fällt er auf eine Base-14-Schrift
zurück, die nur lateinische Glyphen hat. `font-family`, `font-weight`,
`font-style`, `font-size`, `fill` und `text-anchor` werden vom Element, seinem
`style` oder dem nächsten Vorfahren gelesen; das `transform` einer Gruppe
dreht und skaliert die Glyphen mit den Formen. Nicht unterstützt:
Positionierung über `<tspan>`, `<textPath>`, `dx`/`dy`.

Inline-SVG funktioniert auch innerhalb von HTML — `<svg>`-Tags werden
an den SVG-Renderer durchgereicht.

---

## Hyperlinks und Lesezeichen

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Contents')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site')
          ->text(', or jump to ')->internalLink('terms', 'the terms');
    })
    ->heading(1, 'Chapter 1')          // h1–h6 bilden die Gliederung
    ->heading(2, 'Section 1.1')
    ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('terms')->text('Terms apply.'))
    ->toFile('linked.pdf');
```

Der Outline-Bereich (Lesezeichen) wird aus den Überschriften aufgebaut:
`heading(1, …)` ist ein Eintrag der obersten Ebene, `heading(2, …)` wird
darunter verschachtelt, und so weiter bis Ebene 6.
`ParagraphBuilder::bookmark($name)` markiert ein benanntes Ziel;
`internalLink($name, …)` springt dorthin, `link($url, …)` öffnet eine URI.
Auf einer `Pdf\Page` gibt es außerdem `addExternalLink()`,
`addInternalLink()`, `addNamedActionLink()`, `addJavaScriptLink()` und
`addLaunchLink()` für absolut positionierte Linkbereiche.

Automatische Anker bei Überschriften: `<h1 id="intro">` wird zu einem
benannten Ziel, sodass `<a href="#intro">jump</a>` innerhalb des HTML-
Inputs funktioniert.

---

## Formulare (AcroForm)

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

`FormField` ist ein Block im Textfluss: Er nimmt `name`, `type` (standardmäßig
Text), `defaultValue`, `widthPt` / `heightPt` (standardmäßig 200 × 20),
`tooltip`, `required`, `readOnly`, `options` sowie die unten beschriebenen
Skript- und Button-Argumente entgegen. Um ein Feld an absoluten Koordinaten
zu platzieren, fügen Sie es einer `Pdf\Page` hinzu:

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$page = $doc->addPage();
$page->addFormField('text', 'full_name', x: 100, y: 700, width: 200, height: 24);
$page->addFormField('checkbox', 'agree', x: 100, y: 660, width: 14, height: 14, defaultValue: 'on');
$doc->toFile('form-absolute.pdf');
```

Feldtypen: `text`, `text-multiline`, `password`, `checkbox`,
`radio-group`, `combo`, `list`, `push`, `submit`, `reset`, `signature`.

JavaScript-Hooks pro Feld: `keystrokeScript`, `validateScript`,
`calculateScript`, `formatScript`, `clickScript`. Ereignisse auf
Dokumentebene: `WC` (WillClose), `WS` (WillSave), `DS` (DidSave), `WP`
(WillPrint), `DP` (DidPrint).

---

## Ein bestehendes Formular ausfüllen (AcroForm)

Oben wird ein neues Formular erstellt. Um ein PDF zu öffnen, das jemand
anderes erzeugt hat — eine hochgeladene Vorlage, ein Behördenformular — und
die Werte seiner bereits definierten Felder per Name auszufüllen, verwenden
Sie stattdessen `ExistingFormFiller`:

```php
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;

ExistingFormFiller::fromFile('template.pdf')
    ->useFont('fonts/LiberationSans-Regular.ttf') // für nicht-lateinische Werte
    ->setValues([
        'full_name' => 'Jane Roe',
        'agree' => true,
        'employer.name' => 'Acme Corp', // über /Parent-Vererbung aufgelöst
        'country' => 'Germany',         // Combo-Box-Option (Exportwert oder Beschriftung)
    ])
    ->stampImage(0, 'signature.png', x: 100, y: 600, width: 120, height: 40)
    ->flatten()
    ->toFile('filled.pdf');
```

Das Quelldokument wird nie verändert — jeder Aufruf arbeitet auf einer
tiefen Kopie des *gesamten* Objektgraphen, sodass alles Unberührte
(Lesezeichen, Metadaten, andere Annotationen) unverändert in die Ausgabe
übernommen wird. `fields()` liefert für jedes Feld den vollqualifizierten
Punktnamen, Typ, aktuellen Wert, zulässige Optionen, `/MaxLen` und den
`/TU`-Tooltip, sodass man den Aufbau einer Vorlage vor dem Ausfüllen
erkunden kann:

```php
foreach (ExistingFormFiller::fromFile('template.pdf')->fields() as $name => $field) {
    echo "{$name}: {$field->type} = ".json_encode($field->value)."\n";
}
```

Werte je Feldtyp:

| Typ | Akzeptierter Wert |
|---|---|
| `text`, `text-multiline` | ein String; `/MaxLen` wird durchgesetzt |
| `checkbox` | `true`/`false`, `on`/`yes`/`true`/`1`, `off`/`no`/`false`/`0` oder sein Exportwert |
| `radio` | eine der `options` (ohne Groß-/Kleinschreibung) oder `Off` |
| `combo`, `list` | Exportwert oder Beschriftung einer Option; eine Liste davon bei Mehrfachauswahl |
| `signature`, `push` | nicht setzbar — `LogicException` |

Eine unbekannte Option wird mit `InvalidArgumentException` abgelehnt, statt
stillschweigend ignoriert zu werden.

Ausgefüllte Text- und Auswahlfelder erhalten einen neu erzeugten
Appearance-Stream — Schrift, Größe (inklusive Auto-Größe), Farbe und
Ausrichtung aus `/DA`, Hintergrund und Rahmen aus `/MK`, Comb-Zellen,
mehrzeiliger Umbruch —, sodass der Wert in jedem Viewer erscheint, nicht nur
in solchen, die `/NeedAppearances` beachten. Kann die formulareigene Schrift
einen Wert nicht darstellen (Kyrillisch, Griechisch, CJK, …), wird er mit
einer eingebetteten TrueType-Schrift gezeichnet: Übergeben Sie eine an
`useFont()` (oder einen `FontProvider` an `useFontProvider()`), oder
installieren Sie `dskripchenko/php-pdf-fonts-liberation`, das dann
automatisch verwendet wird. Ohne Schrift löst ein solcher Wert eine
`RuntimeException` mit dem Feldnamen aus.

`flatten(?array $fieldNames = null)` macht Felder so zu statischem
Seiteninhalt, wie Acrobat es tut: Das eigene Erscheinungsbild jedes Widgets
— Häkchen, Radio-Punkte, Hintergründe, Rahmen — wird auf die Seite gezeichnet
und das Widget entfernt. Übergeben Sie eine Namensliste, um nur einen Teil
zu glätten und den Rest interaktiv zu lassen, oder lassen Sie sie weg, um
alle Widgets zu glätten und `/AcroForm` zu entfernen.

`stampImage()`/`stampImageBytes()` platzieren ein PNG (mit Alpha) oder JPEG
unabhängig von Feldern — nützlich für eine Unterschrift oder ein Foto, das
selbst kein Formularfeld ist. `x`/`y` ist die linke obere Ecke des Bildes in
Punkt, gemessen von der linken oberen Ecke der Seite *so, wie ein Viewer sie
zeigt* (CropBox, nach `/Rotate`); `pageSize($index)` liefert diese Größe.

Die Ausgabe ist eine vollständige, unverschlüsselte Neuschreibung des
Dokuments. Bestehende digitale Signaturen sind danach nicht mehr gültig, und
sobald sich etwas ändert, wird eine XFA-Formulardefinition entfernt
(XFA-fähige Viewer würden sonst die XFA-Ebene statt der ausgefüllten Werte
anzeigen).

`toBytes(incremental: true)` hängt stattdessen nur die Änderungen an die
ursprüngliche Datei an: Signaturen bleiben gültig (siehe [Signiertes Formular ausfüllen](SIGNING.md#signiertes-formular-ausfüllen)).

---

## Annotationen

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

Annotationsarten: `Text`, `Highlight`, `Underline`, `StrikeOut`,
`FreeText`, `Square`, `Circle`, `Line`, `Stamp`, `Ink`, `Polygon`,
`PolyLine`.

---

## Verschlüsselung

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

Algorithmen:

| Algorithmus   | V/R  | Chiffre      | PDF-Version |
|---------------|------|--------------|-------------|
| `Rc4_128`     | V2 R3 | RC4-128      | 1.4         |
| `Aes_128`     | V4 R4 | AES-128-CBC (AESV2) | 1.6 |
| `Aes_256`     | V5 R5 | AES-256-CBC (AESV3) | 1.7 |
| `Aes_256_R6`  | V5 R6 | AES-256 + Algorithm 2.B iterativer Hash | 2.0 |

Berechtigungsbits: `PERM_PRINT`, `PERM_MODIFY`, `PERM_COPY`,
`PERM_ANNOTATE`, `PERM_FILL_FORMS`, `PERM_ACCESSIBILITY`,
`PERM_ASSEMBLE`, `PERM_PRINT_HIGH`.

`ext-openssl` ist für AES erforderlich.

---

## Digitale Signatur

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$doc = Document::new();
$page = $doc->addPage();

// Das Signaturfeld, als Stempel mit den Zertifikatsdaten dargestellt.
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

Das PDF wird mit Platzhaltern für `/ByteRange` und `/Contents`
ausgegeben und anschließend an Ort und Stelle mit der abgetrennten
CMS-Signatur (standardmäßig PAdES) gepatcht.
Signaturfelder im Layoutfluss, Stempelgestaltung, Platzhalter, Schriften
und mehrere Unterzeichner beschreibt der [Signaturleitfaden](SIGNING.md).
Für Schlüssel, die ein Gerät oder einen Dienst nie verlassen, gibt es fertige
Signer: [php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)
(HSMs, Smartcards, USB-Token),
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)
(AWS KMS, Google Cloud KMS, Azure Key Vault) und
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
(CryptoPro CSP auf dem Server) — siehe [SIGNING.md](SIGNING.md#schlüssel-und-zertifikate).

---

## PDF/A und Tagged PDF

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

Der Output Intent braucht ein sRGB-ICC-Profil; das Paket liefert eines in
`resources/icc/sRGB2014.icc` mit. PDF/A-1 erlaubt die Konformitätsstufen A
oder B, PDF/A-2 und PDF/A-3 zusätzlich U. PDF/A verlangt außerdem, dass jede
Schrift eingebettet ist — die Base-14-Schriften fallen bei der Validierung
durch, setzen Sie Text daher in einer TTF (`PdfFont` oder, bei gesetzten
Dokumenten, eine `Engine` mit `defaultFont`).

`enablePdfA()` ist nicht mit `encrypt()` und `enablePdfX()`
kompatibel — die Bibliothek wirft im Konfliktfall eine Exception.

Tagged PDF ohne PDF/A:

```php
$doc->enableTagged();
$doc->setLang('en-US');
```

Die Tagged-Ausgabe erzeugt einen `/StructTreeRoot` mit `H1`–`H6`, `P`,
`Table`, `TR`, `TD`, `L`, `LI`, `Link` sowie einer optionalen
benutzerdefinierten `/RoleMap`
(`setStructRoleMap(['MyHeading' => 'H1', ...])`).

---

## PDF/X-Druckkonformität

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

Varianten: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. Das mitgelieferte
sRGB-Profil genügt für X-3 und X-4; für eine Druckbedingung übergeben Sie
deren CMYK-Profil (für X-1a ist ein CMYK-Profil Pflicht — keines ist
mitgeliefert) zusammen mit `iccProfileName`, `outputConditionIdentifier`
(z. B. `FOGRA39`) und `outputCondition`. Der Aufrufer ist
für die Inhaltskonformität verantwortlich (z. B. CMYK-Konvertierung
für X-1a, keine Transparenz bei X-1a / X-3).

---

## Streaming-Ausgabe

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

Das Streaming schreibt das zusammengesetzte Byte-Material direkt in den
Stream, anstatt einen kompletten Dokument-String im Speicher zu
puffern.

---

## Optional Content Groups (Ebenen)

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

Ebenen erscheinen im Layers-Panel von Acrobat; Reader können sie ein-
und ausschalten.

---

## Low-Level-Ausgabe

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

Weitere Zeichenmethoden auf `Pdf\Page`: `fillRoundedRect()`,
`strokeRoundedRect()`, `strokeDashedLine()`, `fillPolygon()`,
`strokePolyline()`, `drawImage()`.

`Pdf\Document` besitzt keine Layout-Engine — Koordinaten werden in
PDF-Punkten (1/72 Zoll) angegeben, der Ursprung liegt unten links.
Verwenden Sie es für ticket- oder überlagerungsartige PDFs, bei denen
der Aufrufer die Positionierung vollständig selbst berechnet.

Konfiguration auf `Pdf\Document`:

- `metadata(title: ..., author: ..., subject: ..., keywords: ...,
  creator: ..., producer: ..., creationDate: ...)` — das
  `/Info`-Dictionary.
- `useXrefStream(true)` — Cross-Reference als Stream-Objekt ausgeben
  (PDF 1.5+), ~50 % kleinerer Metadaten-Fußabdruck.
- `useObjectStreams(true)` — Nicht-Stream-Dictionaries in Object
  Streams packen, ~15–30 % kleinere Dateien bei metadatenlastigen
  Dokumenten.
- `setViewerPreferences(['hideToolbar' => true, ...])`.
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`.
- `setOpenAction('fit-page', pageIndex: 3)`.
- `attachFile($name, $bytes, mimeType: 'application/json')`.

Die vollständige Oberfläche finden Sie in `src/Pdf/Document.php`.
