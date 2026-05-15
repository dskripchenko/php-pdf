# Benchmarks

Ein reproduzierbarer Vergleich von `dskripchenko/php-pdf` mit den vier
am häufigsten genutzten PHP-PDF-Bibliotheken auf Packagist:
`mpdf/mpdf`, `tecnickcom/tcpdf`, `dompdf/dompdf` und `setasign/fpdf`.

## Methodik

Jedes (Bibliothek, Szenario)-Paar wird **5-mal** in einem eigenen
PHP-Subprozess ausgeführt, nach **1 Aufwärmlauf**. Berichtet werden der
Median der Wall-Time und der Speicherspitzenwert. Die Ausgabegröße ist
die Byte-Anzahl des erzeugten PDFs.

Die Subprozess-Isolation ist wichtig: Ein einzelner, langlaufender
PHP-Prozess sammelt geladenen Code und OPcache-Druck an, was den
Speicherspitzenwert desjenigen Szenarios verfälscht, das zuletzt
ausgeführt wird. Das Starten eines frischen Prozesses pro Lauf macht
die Speicherspalte aussagekräftig.

Die Wall-Time wird mit `hrtime(true)` gemessen, der Speicher mit
`memory_get_peak_usage(true)`.

**Testumgebung:**
- macOS 25.4 (Darwin), Apple Silicon.
- PHP 8.4 CLI (Zend OPcache aktiviert, JIT-Standardkonfiguration).
- Bibliotheksversionen (Packagist):
  - dskripchenko/php-pdf 1.0.0
  - mpdf/mpdf ^8.2
  - tecnickcom/tcpdf ^6.7
  - dompdf/dompdf ^3.0
  - setasign/fpdf ^1.8

## Szenarien

| Schlüssel | Beschreibung                                           |
|-----------|--------------------------------------------------------|
| `hello`   | Einzelne A4-Seite, ein `Hello, world!`-Absatz.        |
| `invoice` | 100 Seiten × 50 Zeilen; jede Seite hat eine `<h1>`-Überschrift und eine 4-spaltige Tabelle. |
| `images`  | 20 Seiten × 4 JPEG-Thumbnails (je 250 × 180 pt).      |
| `html`    | Ein `<h1>`, zwei `<h2>`-Abschnitte, 24 Lorem-Absätze mit Fett/Kursiv/Link sowie Aufzählungslisten. ~5 Seiten. |

FPDF unterstützt kein HTML und wird daher beim `html`-Szenario
übersprungen.

## Ergebnisse

### Hello world (eine Seite, ein Absatz)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1 KB |
| **phppdf** | **4.6** | **6.0 MB** | 0.8 KB |
| dompdf | 12.0 | 8.0 MB | 1 KB |
| tcpdf | 14.8 | 16.0 MB | 6 KB |
| mpdf | 29.8 | 20.0 MB | 14 KB |

### 100-seitige Rechnung (50 Zeilen/Seite)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 26.0 | 6.0 MB | 198 KB |
| **phppdf** | **518.2** | 50.0 MB | **192 KB** |
| tcpdf | 1348.9 | 26.0 MB | 462 KB |
| mpdf | 2366.9 | 30.0 MB | 811 KB |
| dompdf | 8890.6 | 350.0 MB | 2316 KB |

`phppdf` ist **~2.6× schneller als tcpdf**, **~4.6× schneller als
mpdf** und **~17× schneller als dompdf** bei einer Tabellen-Workload
mit 5000 Zeilen und erzeugt dabei die kleinste Ausgabe unter den
HTML-fähigen Bibliotheken.

### Bildraster (20 Seiten × 4 JPEGs)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 6 KB |
| **phppdf** | **6.4** | 6.0 MB | 8 KB |
| tcpdf | 15.3 | 16.0 MB | 33 KB |
| dompdf | 30.4 | 10.0 MB | 4 KB |
| mpdf | 35.9 | 20.0 MB | 24 KB |

Die Identitäts-Deduplizierung über den Inhalts-Hash bedeutet, dass
dasselbe JPEG, das 80-mal auf 20 Seiten verwendet wird, als ein
einziges XObject eingebettet wird.

### HTML → PDF Artikel (~5 Seiten)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| **phppdf** | **10.8** | 8.0 MB | 8 KB |
| tcpdf | 36.1 | 16.0 MB | 49 KB |
| dompdf | 46.9 | 12.0 MB | 19 KB |
| mpdf | 61.1 | 20.0 MB | 55 KB |
| fpdf | _übersprungen_ (kein HTML) | — | — |

## Reproduktion

```bash
git clone https://github.com/dskripchenko/php-pdf.git
cd php-pdf

# Install the library under test.
composer install

# Install the comparison harness with mpdf, tcpdf, dompdf, FPDF as
# isolated dev dependencies (does not pollute the library's composer.json).
cd scripts/bench
composer install

# Run all scenarios; ~30 seconds total wall time.
php run.php             # JSON to stdout
php run.php --md        # Markdown table form
```

Die Speicherlimits in `run.php` / `one.php` sind auf 1 GB festgelegt,
um dompdf im Rechnungsszenario genügend Platz zu geben.

## Hinweise und Einschränkungen

- **FPDF-Vorteile.** FPDF gewinnt bei `hello` und `images`, weil es
  eine minimale imperative API ist — kein HTML-Parsing, kein
  Textumbruch, kein Tabellenfluss, kein UTF-8. Es ist nur in gleich
  minimalistischen Szenarien ein fairer Benchmark; vergleichbare
  Funktionsabdeckung (HTML-Eingabe, Diagramme, Barcodes,
  Verschlüsselung, Signing, PDF/A, AcroForms) ist in FPDF nicht
  verfügbar.

- **Speicher bei dompdf-Rechnung.** dompdf baut vor dem Layout den
  vollständigen DOM- und CSS-Boxbaum im Speicher auf, was mit der
  gesamten HTML-Größe statt pro Seite skaliert. Der Spitzenwert von
  350 MB bei der 100-seitigen Rechnung spiegelt diese
  Architekturentscheidung wider.

- **Ausgabegröße.** Kleiner ist nicht immer besser — einige
  Bibliotheken betten aggressivere Schrift-Subsets ein oder nutzen
  unkomprimierte Streams. Alle obigen Szenarien verwenden die
  Standard-Schrift-/Komprimierungseinstellungen der jeweiligen
  Bibliothek.

- **HTML-Abdeckung.** Das HTML-Szenario nutzt Elemente (`<h1>`,
  `<h2>`, `<p>`, `<strong>`, `<em>`, `<a>`, `<ul>`, `<li>`), die alle
  Bibliotheken unterstützen. Komplexes CSS (Flexbox, Multi-Column,
  Floats) wird nicht gemessen.

- **Overhead beim ersten Lauf.** Die Wall-Time umfasst den Start des
  Subprozesses und den Bootstrap des Autoloaders, was für
  Serverless- bzw. Per-Request-Workloads realistisch ist. Lang
  laufende Worker sehen für alle Bibliotheken proportional kleineren
  Overhead pro Rendervorgang.
