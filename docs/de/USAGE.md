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
  - [Seitenaufbau und Abschnittsumbrüche](#seitenaufbau-und-abschnittsumbrüche)
- [HTML- / CSS-Eingabe](#html--css-eingabe)
- [Eigene Schriftarten](#eigene-schriftarten)
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

`ListFormat` umfasst Decimal, UpperRoman, LowerRoman, UpperAlpha,
LowerAlpha.

### Bilder

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Unterstützte Formate: JPEG, PNG (8-Bit Truecolor, 8-Bit Palette, mit
Alphakanal über SMask). Dasselbe Bild, das N-mal in einem Dokument
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
            $p->text('Page ')->pageNumber()->text(' of ')->pageCount());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` akzeptiert ein `PdfImage`. Beide
Wasserzeichen verfügen über Deckkraft-Steuerungen via
`watermarkTextOpacity()` / `watermarkImageOpacity()`.

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
        margins: new PageMargins(top: 36, right: 36, bottom: 36, left: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

Papierformate: A0–A6, B0–B6, Letter, Legal, Executive, Tabloid sowie
benutzerdefinierte Größen via `defaultCustomDimensionsPt`.

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
  `<caption>`, `<blockquote>`, `<pre>`, `<dl>` / `<dt>` / `<dd>`.
- Inline: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Legacy: `<center>`, `<font color face size>`.

**Unterstütztes Inline-CSS (`style`-Attribut):**

- `color`, `background-color` — hex `#rrggbb` / `#rgb`, `rgb()`, 21
  benannte Farben.
- `font-size` (pt, px, em, mm, cm, in), `font-family` (erster Wert
  aus der Komma-Liste), `font-weight` (`bold`, `bolder`, 700+),
  `font-style: italic`.
- `text-decoration` (`underline`, `line-through`), `text-transform`
  (`uppercase`, `lowercase`, `capitalize`), `text-align`,
  `text-indent`.
- `margin`, `padding` Kurzschreibweise (1/2/3/4 Werte), `border`
  Kurzschreibweise (`solid`, `double`, `dashed`, `dotted`, `none` +
  Breite + Farbe).
- `line-height` (Multiplikator oder Prozent).

**Nicht unterstützt:** externes CSS (`<link rel="stylesheet">`),
`<style>`-Blöcke, komplexe Selektoren, `@media`, JavaScript, Floats,
`position: absolute / fixed`, Flexbox.

Für `<style>`-/Klassenunterstützung HTML zuvor durch einen externen
Inliner (z. B. `pelago/emogrifier`) verarbeiten.

---

## Eigene Schriftarten

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;

$fonts = new DirectoryFontProvider(__DIR__ . '/fonts');

$bytes = DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — 你好世界 — مرحبا')
    ->toBytes(new Engine(fontProvider: $fonts));
```

`DirectoryFontProvider` durchsucht ein Verzeichnis nach `.ttf`-/`.otf`-
Dateien und macht sie über den Familiennamen verfügbar.
`ChainedFontProvider` ermöglicht das Zusammensetzen von Providern,
sodass die Engine bei fehlenden Glyphen auf Systemschriften zurückgreifen
kann.

Eingebettete Schriftarten werden bedarfsgerecht als Subset
eingebunden — nur die tatsächlich verwendeten Glyphen landen in der
Datei. Kerning, einfache Ligaturen und ToUnicode-CMaps werden
automatisch ausgegeben.

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

$vcard = QrEncoder::vCard(
    fullName: 'Jane Doe',
    org: 'Acme Corp',
    email: 'jane@example.com',
    phone: '+1-555-0100',
);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$mailto = QrEncoder::mailto('hello@example.com', subject: 'Hi');
```

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

Inline-SVG funktioniert auch innerhalb von HTML — `<svg>`-Tags werden
an den SVG-Renderer durchgereicht.

---

## Hyperlinks und Lesezeichen

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

Ausgegebene Hyperlink-Arten: `URI`, `Dest` (benanntes Ziel),
JavaScript, Launch und benannte Seitenziele. Der Outline-Bereich
(Lesezeichen) ist mehrstufig — übergeben Sie `level: 2` für einen
Unterabschnitt, `level: 3` für eine weitere Unterebene usw.

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
        'full_name' => 'Erika Mustermann',
        'agree' => true,
        'employer.name' => 'Acme GmbH', // über /Parent-Vererbung aufgelöst
        'country' => 'Deutschland',    // Combo-Box-Option (Exportwert oder Beschriftung)
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
    x1: 72, y1: 720, x2: 200, y2: 735,
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

Das PDF wird mit Platzhaltern für `/ByteRange` und `/Contents`
ausgegeben und anschließend nach der PKCS#7-Detached-Signatur an Ort
und Stelle gepatcht.

---

## PDF/A und Tagged PDF

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
    variant: PdfXConfig::VARIANT_X4,
    outputConditionIdentifier: 'FOGRA39',
    outputCondition: 'Coated FOGRA39',
    registryName: 'http://www.color.org',
    title: 'Print master',
    trapped: 'False',
));
$doc->toFile('print.pdf');
```

Varianten: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. Der Aufrufer ist
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

`Pdf\Document` besitzt keine Layout-Engine — Koordinaten werden in
PDF-Punkten (1/72 Zoll) angegeben, der Ursprung liegt unten links.
Verwenden Sie es für ticket- oder überlagerungsartige PDFs, bei denen
der Aufrufer die Positionierung vollständig selbst berechnet.

Konfiguration auf `Pdf\Document`:

- `setMetadata(...)` — Title, Author, Subject, Keywords, Creator,
  Producer, CreationDate.
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
