# dskripchenko/php-pdf

> **Ein MIT-Paket ersetzt mpdf + FPDI — ohne GPL-Reibung, und
> [schneller](BENCHMARKS.md).** Reines PHP-Toolkit zum **Erzeugen,
> Lesen und Zusammenführen** von PDFs: GPL-freie mpdf-Alternative für
> HTML→PDF und freie FPDI-Alternative für Import/Stempeln — inklusive
> XRef-Stream- und verschlüsselter Quellen. Die Migration ist mechanisch:
> [von mpdf](MIGRATION-FROM-MPDF.md) · [von FPDI](MIGRATION-FROM-FPDI.md).

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/conformance.yml?branch=main&label=PDF%2FA%20%C2%B7%20PDF%2FX%20%C2%B7%20visual&logo=github)](../en/CONFORMANCE.md)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-pdf?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-pdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-pdf)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../../LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**Sprachen:** [English](../en/README.md) · [Русский](../ru/README.md) · [中文](../zh/README.md) · [Deutsch](README.md)

---

## Inhalt

- [Warum diese Bibliothek](#warum-diese-bibliothek)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Funktionsüberblick](#funktionsüberblick)
- [Dokumentation](#dokumentation)
- [Die php-pdf-Familie](#die-php-pdf-familie)
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
- **PAdES-Signaturen** (B-B, B-T mit RFC-3161-Zeitstempel) — RSA, ECDSA und
  GOST R 34.10-2012, Schlüssel als PEM / PKCS#12 / eigener `Signer` (Token,
  HSM) oder **zweistufiges Signieren außerhalb des Servers** (CryptoPro-Plug-in,
  HSM, Signaturdienst) — mit sichtbarem **Signaturstempel** aus den Zertifikatsdaten;
  **bestehende PDFs signieren** per inkrementeller Aktualisierung (mehrere
  Unterzeichner, Zertifizierung, gesperrte Felder) und **Langzeitvalidierung**
  (B-LT mit DSS, B-LTA mit Dokumentzeitstempel); **Signaturvalidierung** gegen
  die bekannten Angriffe auf signierte PDFs; **abgetrennte CAdES-Signaturen**
  (`.p7s`) beliebiger Bytes, signiert und geprüft.
  Fertige Signer für PKCS#11-Token und HSMs
  ([php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)), Cloud-KMS
  — AWS, Google, Azure
  ([php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)) —
  und CryptoPro CSP auf dem Server
  ([php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)).
- **PDF/A-1a, 1b, 2b, 2u und 3b** (im CI mit veraPDF validiert) und
  **PDF/X-1a / 3 / 4** Konformität mit eingebettetem ICC-Profil und
  XMP-Metadaten.
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
`dom`. Fügen Sie `openssl` für AES-Verschlüsselung oder digitale Signaturen
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
- CSS aus `<style>`-Blöcken und `style`-Attributen, mit Kaskade:
  Typ-, Klassen-, ID- und Attributselektoren, `:first-child`, `:last-child`,
  `:nth-child()`, `:not()`, Nachfahren- / `>` / `+` / `~`-Kombinatoren,
  Spezifität, `!important` — kein CSS-Inliner nötig.
- `@media print` wird angewendet, `@media screen` übersprungen; `@page`-Größe
  und -Ränder.
- Vererbung wie im Browser: color, font, text-align, line-height und
  Ähnliches, gesetzt an `body`, einem `div` oder einer `td`, erreichen den
  Text darin.
- `div`-Container mit margin, padding, background und border;
  `display: flex` (Zeilen, `flex-wrap`, `flex-direction: column`, `gap`),
  `display: grid` (`grid-template-columns` mit Längen, `%`, `fr`,
  `repeat()`, `minmax()`; `grid-column: span n`; `gap`), `display: none`.
- `float: left / right` mit `clear`; `position: absolute` und `fixed`
  (ein fixed-Element wiederholt sich auf jeder Seite); `break-inside: avoid`
  hält einen Block auf einer Seite.
- `::before` / `::after` mit `content` (Strings, `attr()`, Anführungszeichen);
  `<link rel="stylesheet">` auf lokale Dateien und `data:`-URLs;
  `font-family`-Listen (die erste verwendbare Familie).
- Tabellenstile: `width`, `table-layout: auto`, `border-collapse` /
  `border-spacing`, Zellen-`padding` und `vertical-align`, `<col>`-Breiten;
  `<thead>`-Zeilen wiederholen sich auf jeder Seite.
- `page-break-*` / `break-*`, `white-space: pre` / `pre-wrap` /
  `pre-line`; durchgezogene, gestrichelte, gepunktete und doppelte Rahmen.
- Näherungen: Text neben einem Float läuft nicht unter ihm weiter;
  `position: relative` dient nur als Bezug für absolute Kinder (die eigenen
  Versätze werden nicht angewandt); entfernte Stylesheets werden nicht
  geladen. Nicht unterstützt: `grid-template-areas` und explizite
  Zeilenplatzierung, `@import`, `:hover` und andere dynamische
  Pseudoklassen.

### Layout und Typografie
- Knuth–Plass-Zeilenumbruch nach dem Box-Glue-Penalty-Modell mit
  adaptivem Penalty.
- Mehrspaltiges Layout (`ColumnSet`) mit Spalten-zuerst-Fluss.
- Tabellen mit rowspan, colspan, Border-Collapse, doppelten, gestrichelten
  und gepunkteten Rahmen, Border-Radius, Zellenabstand.
- Kopf- und Fußzeilen, Wasserzeichen (Text und Bild), Abschnittsumbrüche.
- Fußnoten mit Positionierung am Seitenfuß.

### Schriftarten
- 14 Adobe-Base-14-Schriftarten (WinAnsi).
- Schriften mit TrueType-Outlines (`.ttf`, TrueType-basierte `.otf`) mit
  On-Demand-Subsetting; CFF-basiertes OpenType (`.otf`) wird vollständig,
  ohne Subsetting eingebettet; CFF2 (variables CFF) wird nicht unterstützt.
- Kerning, einfache GSUB-Ligaturen, ToUnicode-CMap.
- Variable-Font-Instanzen (fvar, gvar, MVAR, HVAR, avar).
- Bidi (UAX#9), Arabische Schaping, einfache indische Schaping.
- Fertiges Liberation-Bundle (metrisch kompatibel zu Arial / Times New
  Roman / Courier New): [dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation).

### Barcodes
- Linear: Code 128 (A/B/C automatisch, GS1-128), Code 39, Code 93,
  Code 11, Codabar, ITF/ITF-14, MSI Plessey, Pharmacode, EAN-13/EAN-8
  mit 2-/5-stelligen Add-ons, UPC-A, UPC-E.
- 2D: QR V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI, Structured
  Append, FNC1), Data Matrix ECC 200 (alle Größen inkl. 144×144,
  rechteckig, 6 Modi), PDF417 (Byte/Text/Numeric, Macro, GS1, ECI),
  Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- QR-Komfort-Factories: vCard 3.0, WiFi (Joinware), URL, SMS,
  E-Mail (`mailto:`), Geo-Position.

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
- PAdES- (`ETSI.CAdES.detached`) oder PKCS#7-Detached-Signatur mit Begründung,
  Ort, Unterzeichner, Zertifikatskette und RFC-3161-Zeitstempeln;
  Signaturstempel (Text, Bilder, QR) mit Zertifikats-Platzhaltern, mehrere
  Signaturfelder, unsichtbare Signaturen.
- Fertige Signer: PKCS#11-Token und HSMs
  ([php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)), AWS
  KMS, Google Cloud KMS und Azure Key Vault
  ([php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)),
  CryptoPro CSP auf dem Server
  ([php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)).
- PDF/A-1a, PDF/A-1b, PDF/A-2b, PDF/A-2u, PDF/A-3b mit eingebettetem sRGB-ICC,
  im CI mit veraPDF validiert.
- PDF/X-1a, PDF/X-3, PDF/X-4 mit /OutputIntent /S /GTS_PDFX.
- Tagged PDF / PDF/UA-tauglicher Strukturbaum.

### Lesen und Zusammenführen
- Bestehende PDFs lesen (`ReaderDocument`) — klassische und Stream-xref,
  Objekt-Streams, Wiederherstellung bei beschädigter xref, Entschlüsselung
  (RC4 / AES-128 / AES-256).
- Text extrahieren (`ReaderDocument::text()`) — ToUnicode-Tabellen,
  Kodierungen einfacher Schriften mit `/Differences`, zusammengesetzte
  Schriften, Form-XObjects; Zeilen und Wortabstände aus den Glyphpositionen.
- Zusammenführen (`PdfMerger`) — ganze oder ausgewählte Seiten aus mehreren
  Dateien anhängen und umordnen; Seitenannotationen und Lesezeichen werden
  mit umgemappten internen Links und benannten Zielen übernommen.
- Overlays stempeln (Wasserzeichen, Briefköpfe) und FPDI-artiger Seitenimport
  in ein frisch erzeugtes Dokument via `PageImporter::intoDocument()`.
- Siehe den [Lese- & Merge-Leitfaden](MERGE.md).

Eine vollständige Anleitung findet sich in [USAGE.md](USAGE.md).

---

## Dokumentation

- 📖 [Anwendungsleitfaden](USAGE.md) — Absätze, Tabellen, Diagramme,
  Barcodes, Formulare, Verschlüsselung, Signing, PDF/A.
- ✍️ [Digitale Signaturen](SIGNING.md) — PAdES- und GOST-Signaturen,
  Stempel, Signieren außerhalb des Servers (CryptoPro, HSM), bestehende PDFs
  und mehrere Unterzeichner, Zertifizierung, Langzeit- und Signaturvalidierung,
  abgetrennte `.p7s`-Signaturen.
- 🔗 [PDFs lesen & zusammenführen](MERGE.md) — bestehende Dateien lesen,
  Seiten anhängen/umordnen, Overlays stempeln, verschlüsselte Eingaben
  verarbeiten.
- ⚖️ [Vergleich mit mpdf / tcpdf / dompdf / FPDF](COMPARISON.md) —
  Feature-Matrix, wann welche Bibliothek zu wählen ist.
- 📊 [Benchmarks](BENCHMARKS.md) — reproduzierbare Messungen zu
  Wall-Time, Speicher und Ausgabegröße.
- ✅ [Konformität](../en/CONFORMANCE.md) — PDF/A mit veraPDF validiert,
  PDF/X mit Ghostscript geprüft, Renderings gegen Golden-Dateien verglichen
  und Signaturen mit OpenSSL und pyHanko verifiziert — bei jedem Push.
- 🔀 [Migration von mpdf](MIGRATION-FROM-MPDF.md) — Compat-Fassade
  (`WriteHTML`/`Output` funktionieren weiter) und vollständige Mapping-Tabelle.
- 🔀 [Migration von FPDI](MIGRATION-FROM-FPDI.md) —
  `setSourceFile`/`importPage`/`useTemplate`-Fassade; liest XRef-Stream- und
  verschlüsselte Quellen ohne kommerziellen Parser.

### Beispiele

Lauffähige Skripte in [`examples/`](../../examples/), Ausgaben eingecheckt in
[`samples/`](../../samples/) — jede wird im CI per Rendering geprüft:

| Aufruf | Ausgabe | Zeigt |
|---|---|---|
| `php examples/01-html-to-pdf.php` | [PDF](../../samples/01-html-to-pdf.pdf) | HTML/CSS-Subset → PDF |
| `php examples/02-builder.php` | [PDF](../../samples/02-builder.pdf) | Fluent-Builder, Tabellen |
| `php examples/03-barcodes.php` | [PDF](../../samples/03-barcodes.pdf) | QR, Code 128, EAN-13, DataMatrix |
| `php examples/04-charts.php` | [PDF](../../samples/04-charts.pdf) | Vektor-Kreis-/Balken-/Liniendiagramme |
| `php examples/05-forms.php` | [PDF](../../samples/05-forms.pdf) | ausfüllbare AcroForm-Felder |
| `php examples/06-signature.php` | [PDF](../../samples/06-signature.pdf) | digitale PAdES-Signatur mit sichtbarem Stempel |
| `php examples/07-pdfa.php` | [PDF](../../samples/07-pdfa.pdf) | PDF/A-2u (veraPDF-sauber); benötigt `scripts/fetch-fonts.sh` |
| `php examples/08-merge.php` | [PDF](../../samples/08-merge.pdf) | bestehende PDFs anhängen + umordnen |
| `php examples/09-stamp.php` | [PDF](../../samples/09-stamp.pdf) | Wasserzeichen/Briefkopf stempeln |

Das [Torture-Set](../../examples/torture/) prüft die schwierigen Pfade (Arabisch,
CJK, rechteckige DataMatrix, …) — siehe [VIEWER-MATRIX](../en/VIEWER-MATRIX.md).

---

## Die php-pdf-Familie

| Paket | Was es hinzufügt |
|---|---|
| **php-pdf** (dieses Paket) | Der Kern: HTML mit CSS oder ein Builder zu PDF, lesen, zusammenführen, Formulare ausfüllen, signieren und validieren. |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Laravel-Bridge: eine Blade-View direkt als PDF, `response()->pdf()`, Signieren aus der Konfiguration. |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans, Serif und Mono, metrisch kompatibel zu Arial, Times New Roman und Courier New (OFL, aus dem MIT-Kern herausgehalten). |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | Signiert mit einem Schlüssel in CryptoPro CSP auf dem Server: GOST R 34.10-2012, russische qualifizierte Signaturen. |
| [php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) | Signiert mit einem Schlüssel auf einem HSM, einer Smartcard oder einem USB-Token, über PKCS#11. |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | Signiert mit einem Schlüssel in AWS KMS, Google Cloud KMS oder Azure Key Vault. |

---

## Performance

Median aus 5 isolierten Subprozess-Läufen (Umgebung und Versionen im Bericht). Die
vollständige Methodik und ein Reproducer finden sich in
[BENCHMARKS.md](BENCHMARKS.md).

<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->
| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |
|---|---:|---:|---:|---:|---:|
| HTML → PDF article (~5 pages) | **15.2 ms** | 61.9 ms | 38.0 ms | 48.1 ms | _n/a_ |
| 100-page invoice (50 rows/page) | **632 ms** | 2300 ms | 1342 ms | 9156 ms | 26.4 ms |
| Image grid (20 pages × 4 JPEGs) | **7.7 ms** | 36.5 ms | 16.9 ms | 31.8 ms | 1.0 ms |
| Hello world (single page, one paragraph) | **5.0 ms** | 29.4 ms | 15.0 ms | 11.7 ms | 0.9 ms |
<!-- bench:table:end -->

FPDF gewinnt in den einfachsten Szenarien (kein HTML, kein Umbruch,
kein Tabellenfluss), kann jedoch kein HTML→PDF erzeugen und unterstützt
weder UTF-8, Diagramme, Barcodes, Formulare, Verschlüsselung noch
Signing.

---

## Voraussetzungen

- PHP **8.2** oder neuer.
- Erforderlich: `ext-mbstring`, `ext-zlib`, `ext-dom`.
- Optional: `ext-openssl` (AES-Verschlüsselung und digitale Signaturen).
- Keine externen Binärdateien — reines PHP.

---

## Tests

```bash
composer install
vendor/bin/phpunit
```

2.480+ Tests, ~120k Assertions, laufen im CI auf PHP 8.2 / 8.3 / 8.4 / 8.5 (siehe den [Tests-Workflow](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)).

---

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).

Copyright © 2026 Denis Skripchenko.
