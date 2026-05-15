# Vergleich mit mpdf, tcpdf, dompdf, FPDF

Ein Feature-für-Feature-Vergleich von `dskripchenko/php-pdf` mit den
vier am häufigsten genutzten PHP-PDF-Bibliotheken auf Packagist. Die
Performance-Zahlen finden Sie in [BENCHMARKS.md](BENCHMARKS.md).

## Lizenzierung

| Bibliothek               | Lizenz         | OEM- / proprietäres Bundle |
|--------------------------|----------------|----------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ ohne Reibung |
| mpdf/mpdf                | GPL-2.0-only   | ❌ erfordert GPL-Bundle oder kommerzielle Lizenz |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ Feinheiten beim statischen Linken |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ wie bei tcpdf |
| setasign/fpdf            | re-licensable  | ✅ aber FPDI und andere Erweiterungen sind proprietär |

MIT ist die freizügigste PHP-Lizenz. Verwenden Sie die Bibliothek
überall, auch in Closed-Source-Produkten, ohne den Quellcode anpassen
oder veröffentlichen zu müssen.

## Engineering-Grundlagen

| Eigenschaft                       | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|-----------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| PHP-Mindestversion                | **8.2** | 7.4  | 7.1   | 7.1    | 4+   |
| Strict Types durchgängig          | ✅      | ❌   | ❌    | ❌     | ❌   |
| Readonly-Klassen, Enums           | ✅      | ❌   | ❌    | ❌     | ❌   |
| Einzeldatei vs. modular           | modular | modular | **Einzeldatei** (~30k LOC) | modular | modular |
| Externe Binär-Abhängigkeiten      | ✅ keine | ✅ keine | ✅ keine | ✅ keine | ✅ keine |

## Eingabe

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Imperative Low-Level-API               | ✅      | ⚠️    | ✅    | ⚠️      | ✅   |
| Fluent-Builder (Document → Section)    | ✅      | ❌   | ❌    | ❌     | ❌   |
| HTML/CSS-Eingabe                       | ✅      | ✅   | ⚠️ einfach | ✅  | ❌   |
| Inline-`style`-Attribute               | ✅      | ✅   | ⚠️    | ✅     | ❌   |
| `<style>`-Blöcke / externes CSS        | ❌      | ✅   | ⚠️    | ✅     | ❌   |
| Float-Layout                           | ❌      | ✅   | ⚠️    | ✅     | ❌   |

Für komplexes HTML oder CSS (Flexbox, Multi-Column, `@media`, Floats)
gehen `dompdf` und `mpdf` weiter. Für Geschäftsdokumente (Absätze,
Tabellen, Listen, Inline-Styling, Überschriften) ist `php-pdf` auf
Augenhöhe und bietet bessere Performance.

## Typografie

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| 14 Standard-Schriftarten               | ✅      | ✅   | ✅    | ✅     | ✅   |
| TTF-Einbettung mit Subsetting          | ✅      | ✅   | ✅    | ✅     | ⚠️ (tFPDF) |
| Kerning                                | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| GSUB-Ligaturen (einfach)               | ✅      | partial | ⚠️ | ❌     | ❌   |
| ToUnicode-CMap (durchsuchbar CJK/Kyrillisch) | ✅ | ✅   | ✅    | ✅     | ❌   |
| Variable Fonts (fvar/gvar/MVAR/HVAR)   | ✅      | ❌   | ❌    | ❌     | ❌   |
| Bidi (UAX#9, X1–X10 explizit)          | ✅      | partial | partial | ❌  | ❌   |
| Arabische Schaping                     | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| Indische Schaping (Devanagari, Bengali, Gujarati) | ✅ | partial | ❌ | ❌     | ❌   |
| Vertikale Schreibrichtung              | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| Knuth–Plass-Zeilenumbruch              | ✅      | ❌   | ❌    | ❌     | ❌   |
| Silbentrennung / weiches Trennzeichen  | ✅      | ✅   | ⚠️    | ⚠️      | ❌   |
| Mehrspaltiges Layout                   | ✅      | ✅   | ⚠️    | ⚠️ partial | ❌ |

## Tabellen, Listen, Kopfzeilen

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Tabellen mit rowspan / colspan         | ✅      | ✅   | ✅    | ✅     | ❌   |
| Border-Collapse, doppelte Rahmen       | ✅      | ✅   | ✅    | ✅     | ❌   |
| Border-Radius                          | ✅      | ✅   | ⚠️    | ✅     | ❌   |
| Wiederholte Tabellenkopfzeile bei Überlauf | ✅  | ✅   | ✅    | ⚠️      | ❌   |
| Kopf-/Fußzeilen/Wasserzeichen          | ✅      | ✅   | ✅    | ⚠️      | ⚠️    |
| Fußnoten                               | ✅      | ✅   | ❌    | ❌     | ❌   |
| Abschnittsumbrüche (Seitenaufbau pro Abschnitt) | ✅ | ✅ | ⚠️  | ⚠️      | ❌   |

## Barcodes

| Format                                 | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Code 128 (A/B/C, GS1-128)              | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 39                                | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 93                                | ✅      | ✅   | ✅    | ❌     | ❌   |
| Code 11                                | ✅      | ❌   | ✅    | ❌     | ❌   |
| Codabar / NW-7                         | ✅      | ❌   | ✅    | ❌     | ❌   |
| ITF / ITF-14                           | ✅      | ✅   | ✅    | ❌     | ❌   |
| MSI Plessey                            | ✅      | ❌   | ✅    | ❌     | ❌   |
| Pharmacode (Laetus)                    | ✅      | ❌   | ❌    | ❌     | ❌   |
| EAN-13 / EAN-8                         | ✅      | ✅   | ✅    | ❌     | ❌   |
| EAN-2 / EAN-5 Add-ons                  | ✅      | ❌   | ❌    | ❌     | ❌   |
| UPC-A / UPC-E                          | ✅      | ✅   | ✅    | ❌     | ❌   |
| QR Code (Numeric/Alphanum/Byte/Kanji)  | ✅      | ✅   | ✅    | ❌     | ❌   |
| QR ECI                                 | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| QR Structured Append                   | ✅      | ❌   | ❌    | ❌     | ❌   |
| QR FNC1 (GS1 + AIM)                    | ✅      | ❌   | ❌    | ❌     | ❌   |
| Data Matrix ECC 200 (alle Größen)      | ✅      | partial | ✅ | ❌     | ❌   |
| Data Matrix 144×144                    | ✅      | ❌   | ⚠️    | ❌     | ❌   |
| PDF417 (Byte/Text/Numeric, Macro, GS1) | ✅      | partial | ✅ | ❌     | ❌   |
| Aztec Compact + Full                   | ✅      | ❌   | ❌    | ❌     | ❌   |
| Aztec Structured Append + FLG/ECI      | ✅      | ❌   | ❌    | ❌     | ❌   |

## Diagramme und Mathematik

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| Balken-/Linien-/Kreisdiagramm          | ✅      | ❌   | ⚠️ (TCPDF Graph-Erweiterung) | ❌ | ❌ |
| Flächen-/Donut-/Streudiagramm          | ✅      | ❌   | ⚠️ ext  | ❌    | ❌   |
| Gruppierte / gestapelte Balken         | ✅      | ❌   | ⚠️ ext  | ❌    | ❌   |
| Mathematik (LaTeX-Subset)              | ✅      | ❌   | ❌    | ❌     | ❌   |
| SVG (Pfade, Verläufe, Transformationen) | ✅     | ✅   | ⚠️ einfach | ⚠️    | ❌   |

## Interaktive Funktionen

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| AcroForm-Widgets                       | ✅      | ✅   | ✅    | ❌     | ❌   |
| AcroForm-Appearance-Streams (NeedAppearances) | ✅ | ⚠️ | ✅  | ❌     | ❌   |
| JavaScript-Aktionen pro Feld           | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Markup-Annotationen (12 Arten)         | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Hyperlinks (URI / Dest / Named / JS / Launch) | ✅ | ✅ | ✅  | ✅     | ⚠️    |
| Dokumentweite Aktionen (Will/Did)      | ✅      | ❌   | ✅    | ❌     | ❌   |
| Optional Content Groups (Ebenen)       | ✅      | ❌   | ✅    | ❌     | ❌   |

## Sicherheit

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| RC4-128-Verschlüsselung                | ✅      | ✅   | ✅    | ⚠️      | ❌   |
| AES-128 (V4 R4)                        | ✅      | ✅   | ✅    | ❌     | ❌   |
| AES-256 V5 R5 (Adobe Supplement)       | ✅      | ✅   | ✅    | ❌     | ❌   |
| **AES-256 V5 R6 (PDF 2.0)**            | ✅      | ❌   | ❌    | ❌     | ❌   |
| Verschlüsselte Strings + Streams + Catalog | ✅  | ✅   | ✅    | ❌     | ❌   |
| PKCS#7 Detached Signing                | ✅      | ❌   | ✅    | ❌     | ❌   |
| Public-Key-Verschlüsselung (/PubSec)   | ❌      | ❌   | ✅    | ❌     | ❌   |

## Konformität und Barrierefreiheit

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| PDF/A-1b                               | ✅      | ✅   | ✅    | ❌     | ❌   |
| PDF/A-1a (Barrierefreiheits-Variante)  | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| PDF/A-2u                               | ✅      | ✅   | ⚠️    | ❌     | ❌   |
| PDF/X-1a / X-3 / X-4                   | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| Tagged PDF (H1–H6, Table, L)           | ✅      | partial | ⚠️ | ❌     | ❌   |
| Eigene RoleMap                         | ✅      | ❌   | ❌    | ❌     | ❌   |
| `/Lang`, `/MarkInfo`, ViewerPreferences | ✅     | ✅   | ✅    | ❌     | ❌   |
| Seitenbeschriftungen (Roman, Alpha, Präfix) | ✅  | ⚠️    | ⚠️    | ❌     | ❌   |

## Ausgabe-Funktionen

| Funktion                                | php-pdf | mpdf | tcpdf | dompdf | FPDF |
|----------------------------------------|:-------:|:----:|:-----:|:------:|:----:|
| XRef-Streams (PDF 1.5+)                | ✅      | ❌   | ❌    | ❌     | ❌   |
| Object Streams (PDF 1.5+)              | ✅      | ❌   | ❌    | ❌     | ❌   |
| Ausgeglichener Page Tree               | ✅      | ❌   | ❌    | ❌     | ❌   |
| Stream-Ausgabe (`toStream`)            | ✅      | ❌   | ❌    | ❌     | ❌   |
| Eingebettete Dateien / Anhänge         | ✅      | ⚠️    | ✅    | ❌     | ❌   |
| Form-XObjects (`/Do` wiederverwendbare Streams) | ✅ | ✅ | ✅ | ❌  | ⚠️    |
| Patterns (Kachelung + axial + radial)  | ✅      | ⚠️    | ⚠️    | ❌     | ❌   |
| Mehrstufige Verläufe (Stitching-Funktionen) | ✅  | ⚠️    | ⚠️    | ❌     | ❌   |

## Wann welche Bibliothek wählen

### **dskripchenko/php-pdf** wählen, wenn
- Sie ein Closed-Source-/OEM-Produkt ausliefern und MIT benötigen.
- Sie Geschäftsdokumente erzeugen (Rechnungen, Berichte, Verträge,
  Formulare, Zertifikate), bei denen Typografie, Barcodes, AcroForms,
  Signing oder PDF/A wichtig sind.
- Sie die breiteste Barcode-Abdeckung in reinem PHP benötigen.
- Sie PHP 8.2+ ansprechen und modernen, typsicheren Code wünschen.
- Sie PDF-2.0-Funktionen benötigen (AES-256 R6).
- Ihnen Ausgabegröße und Latenz pro Rendervorgang wichtig sind.

### **mpdf/mpdf** wählen, wenn
- Sie es bereits im Stack haben und die Migration nicht gerechtfertigt
  ist.
- Ihr Projekt GPL-kompatibel ist.
- Sie umfangreichere CSS-Abdeckung benötigen (Flexbox, `@media`,
  komplexe Selektoren).
- Sie die mpdf-Dokumentation und das Stack-Overflow-Korpus bevorzugen.

### **tecnickcom/tcpdf** wählen, wenn
- Sie eine bestehende Enterprise-PHP-Anwendung pflegen, die es bereits
  verwendet.
- Sie speziell Public-Key-Verschlüsselung (`/Filter /PubSec`)
  benötigen.
- Sie mit einer einzelnen 30k-Zeilen-Datei und PHP-7.1+-Idiomen
  zurechtkommen.

### **dompdf/dompdf** wählen, wenn
- Sie beliebiges HTML/CSS mit Float-Layout rendern müssen — Inhalte,
  die für Browser gedacht sind, nicht für Geschäftsdokumente.
- Performance und Speicher unkritisch sind (das vollständige DOM wird
  vor dem Layout im Speicher aufgebaut).

### **setasign/fpdf** wählen, wenn
- Sie einfache Rechnungen oder Quittungen mit manueller Positionierung
  erzeugen.
- Sie den kleinstmöglichen Abhängigkeits-Fußabdruck wollen.
- Sie kein UTF-8, HTML, Tabellen, Diagramme, Verschlüsselung oder
  Signing benötigen.
