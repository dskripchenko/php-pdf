# Bestehende PDFs lesen & zusammenführen

Über das Erzeugen von PDFs hinaus kann `dskripchenko/php-pdf` **bestehende
PDF-Dateien lesen** und **kombinieren**: ein Dokument an ein anderes anhängen,
einzelne Seiten auswählen und eine Seite eines Dokuments auf Seiten eines anderen
stempeln.

Alles in reinem PHP und MIT-lizenziert — kein FPDI, keine externen Binaries.

## Inhalt

- [Ein PDF lesen](#ein-pdf-lesen)
- [Dokumente anhängen](#dokumente-anhängen)
- [Seiten auswählen und umordnen](#seiten-auswählen-und-umordnen)
- [Eine Seite einbetten (stempeln)](#eine-seite-einbetten-stempeln)
- [Annotationen und Lesezeichen](#annotationen-und-lesezeichen)
- [Import in ein generiertes Dokument (FPDI-Stil)](#import-in-ein-generiertes-dokument-fpdi-stil)
- [Platzierung](#platzierung)
- [Verschlüsselte Eingabe](#verschlüsselte-eingabe)
- [Was der Reader beherrscht](#was-der-reader-beherrscht)
- [Einschränkungen (v1)](#einschränkungen-v1)

## Ein PDF lesen

```php
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

$doc = ReaderDocument::fromBytes(file_get_contents('report.pdf'));

echo $doc->pageCount();               // Anzahl der Seiten
foreach ($doc->pages() as $page) {
    printf("%.0f × %.0f pt\n", $page->width(), $page->height());
}
```

Ein `ReaderDocument` löst Objekte lazy auf, folgt der Querverweiskette
(klassische Tabellen, XRef-Streams und Objekt-Streams) und fällt auf einen
vollständigen Datei-Scan zurück, wenn die Querverweise beschädigt sind.

## Dokumente anhängen

`PdfMerger` verkettet Seiten aus einer oder mehreren Quellen zu einem neuen
Dokument (pdftk-Stil):

```php
use Dskripchenko\PhpPdf\Pdf\Merge\{PdfMerger, PdfSource};

$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('cover.pdf'))
    ->append(PdfSource::fromFile('body.pdf'))
    ->append(PdfSource::fromFile('appendix.pdf'))
    ->toBytes();

file_put_contents('combined.pdf', $bytes);
// oder: PdfMerger::create()->append(...)->toFile('combined.pdf');
```

`PdfSource` akzeptiert einen Dateipfad oder Rohbytes:

```php
PdfSource::fromFile('/path/to.pdf');
PdfSource::fromBytes($binaryString);
```

## Seiten auswählen und umordnen

Übergeben Sie 1-basierte Seitennummern in der gewünschten Ausgabereihenfolge:

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'), pages: [1, 3, 5])   // Teilmenge
    ->append(PdfSource::fromFile('b.pdf'), pages: [2, 1])      // umgeordnet
    ->toBytes();
```

Lassen Sie `pages` weg, um alle Seiten in Lesereihenfolge zu übernehmen. Jede
Ausgabeseite behält ihre Quellgeometrie (MediaBox, CropBox, Rotation).

## Eine Seite einbetten (stempeln)

`stamp()` zeichnet eine Seite eines Dokuments über bereits angehängte Seiten —
die Quellseite wird zu einem wiederverwendbaren Form-XObject. Nützlich für
Wasserzeichen, Briefköpfe, Hintergründe oder das Platzieren einer Seite als
Abbildung.

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('invoices.pdf'))          // Basisseiten
    ->stamp(
        PdfSource::fromFile('watermark.pdf'),
        page: 1,                                            // welche Quellseite
        onPages: null,                                      // null = jede Ausgabeseite
        placement: Placement::fit(),
    )
    ->toBytes();
```

Gezielt bestimmte Ausgabeseiten mit `onPages` (1-basiert):

```php
->stamp(PdfSource::fromFile('logo.pdf'), page: 1, onPages: [1], placement: Placement::at(40, 40, 0.5))
```

Der eigene Inhalt der Basisseite bleibt erhalten und wird zuerst gezeichnet; das
Overlay wird darüber aus einem sauberen Grafikzustand gezeichnet.

## Annotationen und Lesezeichen

Seitenannotationen und die Dokumentgliederung (Lesezeichen) werden **standardmäßig**
in die zusammengeführte Ausgabe übernommen. Interne Links und Lesezeichenziele —
einschließlich benannter Ziele — werden auf die neuen Seiten umgemappt. Ein Link
oder Lesezeichen, dessen Zielseite nicht Teil der Ausgabe ist, wird verworfen;
externe `URI`-Links bleiben immer erhalten.

```php
PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'))
    ->withoutAnnotations()   // Annotationsübernahme deaktivieren
    ->withoutOutlines()      // Lesezeichenübernahme deaktivieren
    ->toBytes();
```

Formularfeld-Widgets (AcroForm) und Popup-Annotationen werden nicht übernommen.

## Import in ein generiertes Dokument (FPDI-Stil)

`stamp()` kombiniert bestehende PDFs. Um eine importierte Seite in ein Dokument
zu platzieren, das Sie mit php-pdf erstellen — mit eigenem Text, Wasserzeichen
oder Grafik darüber oder darunter — verwenden Sie `PageImporter::intoDocument()`.
Die importierte Seite wird zu einem Form-XObject, das Sie mit
`Page::useFormXObject()` positionieren:

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Pdf\Merge\PageImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

$src  = ReaderDocument::fromBytes(file_get_contents('contract.pdf'));
[$w, $h] = PageImporter::pageSize($src, 0);

$doc  = new Document();
$page = $doc->addPage(customDimensionsPt: [$w, $h]);

$form = PageImporter::intoDocument($doc, $src, pageIndex: 0);
$page->useFormXObject($form, 0, 0, $w, $h);              // importierte Seite als Hintergrund
$page->showText('DRAFT', 200, 400, StandardFont::Helvetica, 48); // eigener Inhalt darüber

$doc->toFile('stamped-contract.pdf');
```

Rotation und CropBox der Seite werden automatisch behandelt. Schriften und Bilder
der importierten Seite werden unverändert in die Ausgabe kopiert.

## Platzierung

`Placement` steuert, wie die eingebettete Seite (in Punkt bemessen) auf die
Zielseite abgebildet wird:

| Factory | Verhalten |
|---|---|
| `Placement::fit()` | Skalieren zum Einpassen, unter Beibehaltung des Seitenverhältnisses, zentriert. |
| `Placement::stretch()` | Die Zielseite exakt füllen (Seitenverhältnis wird ignoriert). |
| `Placement::at($x, $y, $scale = 1.0)` | Untere linke Ecke bei `($x, $y)`, skaliert um `$scale`. |

Rotierte Quellseiten (`/Rotate` 90/180/270) werden über die `/Matrix` des Forms
aufrecht eingebrannt, sodass die Platzierung in intuitiven, aufrechten Koordinaten
funktioniert.

## Verschlüsselte Eingabe

Verschlüsselte Quellen werden beim Lesen transparent entschlüsselt und in der
zusammengeführten Ausgabe **unverschlüsselt** neu ausgegeben:

```php
PdfSource::fromFile('protected.pdf', password: 'secret');
```

Unterstützt: RC4 (40/128-Bit), AES-128 (AESV2) und AES-256 (V5 R5/R6). Sowohl das
Benutzer- als auch das Eigentümerpasswort werden versucht. Ein leeres Passwort
(der häufige Fall „nur Eigentümer-Beschränkungen") funktioniert ohne Argument.
Prädiktor-kodierte Streams werden bei jeder Bittiefe dekodiert (8-Bit und 16-Bit,
plus Sub-Byte für PNG-Prädiktoren).

Der Public-Key-Security-Handler (`/Adobe.PubSec`, zertifikatsbasiert) wird nicht
unterstützt — solche Dateien lösen einen klaren „Unsupported security handler"-Fehler
aus, statt Müll zu erzeugen.

## Was der Reader beherrscht

- Klassische `xref`-Tabellen, XRef-Streams (PDF 1.5+), hybrides `/XRefStm` und
  inkrementelle Updates (`/Prev`).
- Objekt-Streams (komprimierte Objekte).
- Filter: Flate (mit PNG/TIFF-Prädiktoren), LZW, ASCII85, ASCIIHex,
  RunLength. Bildfilter (DCT/JPX/CCITT/JBIG2) werden unverändert durchgereicht.
- Wiederherstellung bei beschädigten/fehlenden Querverweisen durch Scannen der
  Objekt-Header.
- Seitenbaum-Verflachung mit vererbtem MediaBox/CropBox/Rotate/Resources.

## Einschränkungen (v1)

Das Zusammenführen baut jede Seite aus ihrem Inhalt und ihren Ressourcen neu auf.
Folgendes wird **noch nicht** in die Ausgabe übernommen:

- Interaktive Formularfelder (AcroForm) sowie Widget-/Popup-Annotationen — verworfen.
- Struktur-Tags (Tagged PDF / PDF-A-Konformität des Ergebnisses).
- Bild- und Schrift-Streams werden unverändert kopiert, nie neu kodiert.

Annotationen und Gliederungen (Lesezeichen) mit internen/benannten Zielen **werden**
übernommen und umgemappt — siehe [Annotationen und Lesezeichen](#annotationen-und-lesezeichen).

Dies ist dokumentiert, damit „unterstützt Merge" nicht mit „bewahrt alles"
verwechselt wird. Der vollständige Umfang und die Roadmap stehen in
`docs/design/pdf-merge.md`.
