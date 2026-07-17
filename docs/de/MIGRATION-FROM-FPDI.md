# Migration von FPDI

`setasign/fpdi` hat einen Haken, den alle im ungünstigsten Moment
entdecken: Die freie Version kann keine PDFs mit komprimierten
Querverweis-Streams (xref streams) lesen — dem **Standard-Ausgabeformat**
der meisten modernen PDF-Erzeuger (PDF 1.5+). Sobald ein Kunde so eine
Datei hochlädt, braucht es das kommerzielle FPDI-PDF-Parser-Add-on.
`dskripchenko/php-pdf` (MIT) liest von Haus aus, was das freie FPDI
nicht kann:

- klassisches xref **und xref streams**, Object Streams, hybride Dateien;
- **verschlüsselte Eingaben** — RC4, AES-128, AES-256 (R5/R6), Benutzer-
  und Besitzerpasswort;
- **Wiederherstellung defekter xrefs** durch Scannen der Objekt-Header;
- validiert gegen ein Fremdkorpus: pdfTeX, LibreOffice, Google Docs/Skia,
  Qt/pdfkit, Ghostscript, ImageMagick, FPDF2, pypdf.

## Route 1 — die Compat-Fassade

Der klassische FPDI-Ablauf mappt eins zu eins:

```php
// vorher                                    // nachher
use setasign\Fpdi\Fpdi;                      use Dskripchenko\PhpPdf\Compat\Fpdi;

$pdf = new Fpdi();                           $pdf = new Fpdi();
$count = $pdf->setSourceFile('in.pdf');      $count = $pdf->setSourceFile('in.pdf');
$tpl = $pdf->importPage(1);                  $tpl = $pdf->importPage(1);
$pdf->AddPage('', $pdf->getTemplateSize($tpl));
$pdf->useTemplate($tpl, x: 0, y: 0);         // gleicher Aufruf
$pdf->Output('F', 'out.pdf');                // gleicher Aufruf (beide Argumentreihenfolgen)
```

Koordinaten folgen den FPDF-Konventionen: Ursprung oben links, y nach
unten, Benutzereinheiten (mm als Standard; `pt`, `cm`, `in` im
Konstruktor). `useTemplate()` skaliert proportional, wenn nur `width`
oder nur `height` übergeben wird — genau wie FPDI.

Statt der FPDF-Zeichen-API (`Cell()`/`SetFont()`) reicht die Fassade die
nativen Objekte durch: `$pdf->page()` liefert die native `Pdf\Page`
(Text, Bilder, Formularfelder), `$pdf->document()` das native
`Pdf\Document` (Verschlüsselung, Signatur, Metadaten):

```php
$pdf->page()->showText('KOPIE - kein Original', 40, 40,
    \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 9);
```

## Route 2 — die native API

| FPDI | php-pdf |
|---|---|
| `$pdf->setSourceFile($f)` | `$src = ReaderDocument::fromBytes(file_get_contents($f))` (optionales Passwort) |
| *(Seitenzahl — Rückgabewert)* | `count($src->pages())` |
| `$tpl = $pdf->importPage($n)` | `$form = PageImporter::intoDocument($doc, $src, $n - 1)` — 0-basierter Index |
| `$pdf->useTemplate($tpl, $x, $y, $w, $h)` | `$page->useFormXObject($form, $x, $y, $w, $h)` — PDF-Koordinaten: Ursprung unten links, Punkte |
| `$pdf->getTemplateSize($tpl)` | `$form->bboxWidth()` / `$form->bboxHeight()` |
| Schleife zur Dateiverkettung | `PdfMerger::create()->append(PdfSource::fromFile($a))->append(...)->toBytes()` |
| Stempel-/Wasserzeichen-Schleife | `PdfMerger::create()->append($src)->stamp(PdfSource::fromFile($stamp), placement: Placement::fit())` |
| verschlüsselte Quelle *(ohne Add-on nicht möglich)* | `PdfSource::fromFile($f, password: '...')` / `ReaderDocument::fromBytes($bytes, '...')` |
| xref-stream-Quellen *(kommerzieller Parser)* | out of the box unterstützt |

**Welches native Werkzeug wann:**

- `PdfMerger` — ganze Dokumente anhängen/umordnen. Anmerkungen, Lesezeichen
  und benannte Ziele werden übernommen (FPDI verwirft sie).
- `PageImporter` — FPDI-Stil: importierte Seite als XObject in einem frisch
  erzeugten Dokument, darüber und darunter kann gezeichnet werden.

## Stolpersteine

- **Koordinaten**: Die Fassade behält FPDFs oben-links/mm-Konventionen;
  die native API ist PDF-nativ — unten links, Punkte. Umrechnung:
  `y_pdf = Seitenhöhe − y_mm × 72/25.4 − Höhe_pt`.
- **Seitenindizes**: FPDIs `importPage()` ist 1-basiert; das native
  `PageImporter::intoDocument()` 0-basiert. Die Fassade bleibt 1-basiert.
- **Anmerkungen**: Wie FPDI importiert `importPage`/`PageImporter` nur den
  Seiten-*Inhalt*. Sollen Links/Lesezeichen erhalten bleiben: `PdfMerger`.
- **`adjustPageSize`**: Statt des FPDI-Flags die Vorlagengröße explizit an
  `AddPage('', $pdf->getTemplateSize($tpl))` übergeben — äquivalent.

Alle Mappings werden von `tests/Compat/FpdiCompatTest.php` abgedeckt.

---

Sprache: [English](../en/MIGRATION-FROM-FPDI.md) · [Русский](../ru/MIGRATION-FROM-FPDI.md) · [中文](../zh/MIGRATION-FROM-FPDI.md) · [Deutsch](MIGRATION-FROM-FPDI.md)
