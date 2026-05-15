# dskripchenko/php-pdf

> Reiner PHP-PDF-Generator unter der **MIT-Lizenz**. Eine sofort
> einsatzbereite Alternative zu `mpdf/mpdf` (GPL-2.0) — keine
> lizenzrechtlichen Reibungspunkte für OEM, On-Premise-Installer oder
> proprietäre Bundles.

[![Packagist](https://img.shields.io/packagist/v/dskripchenko/php-pdf.svg)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)
[![Tests](https://img.shields.io/badge/tests-1977%20passing-success.svg)](#testing)

**Sprachen:** [English](../en/README.md) · [Русский](../ru/README.md) · [中文](../zh/README.md) · [Deutsch](README.md)

---

## Inhalt

- [Warum diese Bibliothek](#warum-diese-bibliothek)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Funktionsüberblick](#funktionsüberblick)
- [Dokumentation](#dokumentation)
- [Performance](#performance)
- [Voraussetzungen](#voraussetzungen)
- [Tests](#tests)
- [Lizenz](#lizenz)

---

## Warum diese Bibliothek

**Lizenzierung.** MIT ist die freizügigste PHP-Lizenz — verwenden Sie
den Code überall, auch in Closed-Source-Produkten. Vergleich mit dem
gängigen PHP-PDF-Stack:

| Bibliothek               | Lizenz         | OEM- / proprietäres Bundle |
|--------------------------|----------------|----------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ ohne Reibung |
| mpdf/mpdf                | GPL-2.0-only   | ❌ erfordert GPL-Bundle oder kommerzielle Lizenz |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ Feinheiten beim statischen Linken |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ wie bei tcpdf |
| setasign/fpdf            | re-licensable  | ✅ aber Erweiterungen sind proprietär |

**Engineering.**

- **Modernes PHP 8.2+** — readonly Klassen, Enums, benannte Argumente,
  strict types. Saubere, typsichere API-Oberfläche.
- **Zweischichtiges Design** — `Pdf\Document` für das Low-Level-Emit,
  `Build\*` Fluent-Builder für hochwertige Dokumente,
  `Document::fromHtml()` für HTML/CSS-Eingaben.
- **Starke Typografie** — Knuth–Plass-Zeilenumbruch, TTF-Subsetting mit
  Kerning, GSUB-Ligaturen, ToUnicode-CMaps, Variable-Font-Instanzen,
  Bidi (UAX#9), Arabische Schaping, einfache indische Schaping,
  vertikale Schreibrichtung.
- **Breiteste Barcode-Abdeckung** — 12 lineare + 4 2D-Formate inklusive
  seltener Pharmacode, MSI Plessey, ITF-14, EAN-2/5-Add-ons.
- **Produktionsreife Kryptografie** — RC4-128, AES-128, AES-256 (V5 R5
  und R6 gemäß ISO 32000-2 / PDF 2.0).
- **PKCS#7 Detached Signing** mit automatischem Patchen des Platzhalter-
  /ByteRange.
- **PDF/A-1a / 1b / 2u und PDF/X-1a / 3 / 4** Konformität mit
  eingebettetem sRGB-ICC-Profil und XMP-Metadaten.
- **Tagged PDF / PDF/UA-tauglicher** Strukturbaum mit H1–H6, Table/TR/TD,
  L/LI, eigener RoleMap, ParentTree-Zahlenbaum.
- **Streaming-Ausgabe** an eine Stream-Ressource für große Dokumente.
- **XRef-Streams** (PDF 1.5+) und Object Streams für kompakte Ausgabe.

---

## Installation

```bash
composer require dskripchenko/php-pdf
```

PHP 8.2 oder neuer. Erforderliche Erweiterungen: `mbstring`, `zlib`,
`dom`. Fügen Sie `openssl` für AES-Verschlüsselung oder PKCS#7-Signing
hinzu.

---

## Schnellstart

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

### Programmatischer Builder

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

### Low-Level-Ausgabe

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

## Funktionsüberblick

### Eingabe
- HTML5-Subset-Parser via `Document::fromHtml()`.
- Block-Tags `<p>`, `<h1>`–`<h6>`, `<ul>`/`<ol>`/`<li>`, Tabellen (mit
  `<thead>`/`<tbody>`/`<tfoot>` und `<caption>`), `<blockquote>`, `<hr>`,
  `<pre>`, `<dl>`/`<dt>`/`<dd>`.
- Inline-Tags `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<del>`,
  `<sup>`/`<sub>`, `<br>`, `<a>`, `<span>`, sowie `<code>`, `<kbd>`,
  `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`,
  `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- HTML5-Semantik-Blöcke (`<header>`, `<footer>`, `<article>`, `<nav>`,
  `<section>` usw.) und Legacy-Elemente `<center>` / `<font>`.
- Inline-CSS: color, background, font-Eigenschaften, text-align,
  text-decoration, text-transform, margin/padding-Kurzschreibweise,
  borders, line-height, text-indent.

### Layout und Typografie
- Knuth–Plass-Zeilenumbruch nach dem Box-Glue-Penalty-Modell mit
  adaptivem Penalty.
- Mehrspaltiges Layout (`ColumnSet`) mit Spalten-zuerst-Fluss.
- Tabellen mit rowspan, colspan, Border-Collapse, doppelten Rahmen,
  Border-Radius, Zellenabstand.
- Kopf- und Fußzeilen, Wasserzeichen (Text und Bild), Abschnittsumbrüche.
- Fußnoten mit Positionierung am Seitenfuß.

### Schriftarten
- 14 Adobe-Base-14-Schriftarten (WinAnsi).
- TTF-Einbettung mit On-Demand-Subsetting (CFF und TrueType).
- Kerning, einfache GSUB-Ligaturen, ToUnicode-CMap.
- Variable-Font-Instanzen (fvar, gvar, MVAR, HVAR, avar).
- Bidi (UAX#9), Arabische Schaping, einfache indische Schaping.

### Barcodes
- Linear: Code 128 (A/B/C automatisch, GS1-128), Code 39, Code 93,
  Code 11, Codabar, ITF/ITF-14, MSI Plessey, Pharmacode, EAN-13/EAN-8
  mit 2-/5-stelligen Add-ons, UPC-A, UPC-E.
- 2D: QR V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI, Structured
  Append, FNC1), Data Matrix ECC 200 (alle Größen inkl. 144×144,
  rechteckig, 6 Modi), PDF417 (Byte/Text/Numeric, Macro, GS1, ECI),
  Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- QR-Komfort-Factories: vCard 3.0, WiFi Joinware, mailto.

### Diagramme
- BarChart, LineChart, PieChart, AreaChart, DonutChart, GroupedBarChart,
  StackedBarChart, MultiLineChart, ScatterChart.

### Interaktiv
- AcroForm-Widgets: Text (einzeilig / mehrzeilig / Passwort), Checkbox,
  Radio, Combo, List, Push- / Submit- / Reset-Buttons, Signatur.
- JavaScript-Aktionen pro Feld (keystroke, validate, calculate, format).
- Markup-Annotationen: Text, Highlight, Underline, StrikeOut, FreeText,
  Square, Circle, Line, Stamp, Ink, Polygon, PolyLine.

### Sicherheit und Konformität
- Verschlüsselung: RC4-128, AES-128, AES-256 (V5 R5 + R6 / PDF 2.0).
- PKCS#7 Detached Signing mit Zeitstempel, Begründung, Ort, Unterzeichner.
- PDF/A-1a, PDF/A-1b, PDF/A-2u mit eingebettetem sRGB-ICC.
- PDF/X-1a, PDF/X-3, PDF/X-4 mit /OutputIntent /S /GTS_PDFX.
- Tagged PDF / PDF/UA-tauglicher Strukturbaum.

Eine vollständige Anleitung findet sich in [docs/en/USAGE.md](USAGE.md).

---

## Dokumentation

- 📖 [Anwendungsleitfaden](USAGE.md) — Absätze, Tabellen, Diagramme,
  Barcodes, Formulare, Verschlüsselung, Signing, PDF/A.
- ⚖️ [Vergleich mit mpdf / tcpdf / dompdf / FPDF](COMPARISON.md) —
  Feature-Matrix, wann welche Bibliothek zu wählen ist.
- 📊 [Benchmarks](BENCHMARKS.md) — reproduzierbare Messungen zu
  Wall-Time, Speicher und Ausgabegröße.

---

## Performance

Median aus 5 isolierten Subprozess-Läufen auf macOS 25 / PHP 8.4. Die
vollständige Methodik und ein Reproducer finden sich in
[docs/en/BENCHMARKS.md](BENCHMARKS.md).

| Szenario                  | dskripchenko/php-pdf | mpdf      | tcpdf     | dompdf     | FPDF      |
|---------------------------|---------------------:|----------:|----------:|-----------:|----------:|
| HTML → PDF Artikel (~5 Seiten) | **10.8 ms**     | 61.1 ms   | 36.1 ms   | 46.9 ms    | _n/a_     |
| 100-seitige Rechnung (50 Zeilen/Seite) | **518 ms**    | 2367 ms   | 1349 ms   | 8891 ms    | 26 ms     |
| Bildraster (20 Seiten × 4) | **6.4 ms**          | 35.9 ms   | 15.3 ms   | 30.4 ms    | 1.0 ms    |
| Hello world (1 Seite)     | 4.6 ms              | 29.8 ms   | 14.8 ms   | 12.0 ms    | 0.9 ms    |

FPDF gewinnt in den einfachsten Szenarien (kein HTML, kein Umbruch,
kein Tabellenfluss), kann jedoch kein HTML→PDF erzeugen und unterstützt
weder UTF-8, Diagramme, Barcodes, Formulare, Verschlüsselung noch
Signing.

---

## Voraussetzungen

- PHP **8.2** oder neuer.
- Erforderlich: `ext-mbstring`, `ext-zlib`, `ext-dom`.
- Optional: `ext-openssl` (AES-Verschlüsselung und PKCS#7-Signing).
- Keine externen Binärdateien — reines PHP.

---

## Tests

```bash
composer install
vendor/bin/phpunit
```

1977 Tests, ~119k Assertions, alle erfolgreich auf PHP 8.2 / 8.3 / 8.4.

---

## Lizenz

MIT — siehe [LICENSE](LICENSE).

Copyright © 2026 Denis Skripchenko.
