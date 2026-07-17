# Migrating from FPDI

`setasign/fpdi` is free, but the moment you need to read PDFs that use
compressed cross-reference streams (the default output of most modern
producers) you need the **commercial** FPDI PDF-Parser add-on.
`dskripchenko/php-pdf` reads classic xref, xref streams, object streams
and hybrid files — plus encrypted input (RC4 / AES-128 / AES-256) and
corrupt-xref recovery — under MIT.

## Route 1 — the compat facade

The classic FPDI flow maps one-to-one:

```php
// before                                   // after
use setasign\Fpdi\Fpdi;                     use Dskripchenko\PhpPdf\Compat\Fpdi;

$pdf = new Fpdi();                          $pdf = new Fpdi();
$count = $pdf->setSourceFile('in.pdf');     $count = $pdf->setSourceFile('in.pdf');
$tpl = $pdf->importPage(1);                 $tpl = $pdf->importPage(1);
$pdf->AddPage('', $pdf->getTemplateSize($tpl));
$pdf->useTemplate($tpl, x: 0, y: 0);        // same call
$pdf->Output('F', 'out.pdf');               // same call (both arg orders work)
```

Coordinates keep FPDF conventions: top-left origin, y downward, user
units (mm by default; `pt`, `cm`, `in` supported in the constructor).
`useTemplate()` scales proportionally when you pass only `width` or only
`height`, exactly like FPDI.

Instead of FPDF's `Cell()`/`SetFont()` drawing API, the facade exposes
the underlying objects — `$pdf->page()` returns the native
`Pdf\Page` (text, images, form fields), `$pdf->document()` the native
`Pdf\Document` (encryption, signing, metadata):

```php
$pdf->page()->showText('Copy — not an original', 40, 40,
    \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 9);
```

## Route 2 — the native API

| FPDI | php-pdf |
|---|---|
| `$pdf->setSourceFile($f)` | `$src = ReaderDocument::fromBytes(file_get_contents($f))` (optional password argument) |
| *(page count — return value)* | `count($src->pages())` |
| `$tpl = $pdf->importPage($n)` | `$form = PageImporter::intoDocument($doc, $src, $n - 1)` — note 0-based index |
| `$pdf->useTemplate($tpl, $x, $y, $w, $h)` | `$page->useFormXObject($form, $x, $y, $w, $h)` — PDF coordinates: bottom-left origin, points |
| `$pdf->getTemplateSize($tpl)` | `$form->bboxWidth()` / `$form->bboxHeight()` |
| whole-file concatenation loop | `PdfMerger::create()->append(PdfSource::fromFile($a))->append(...)->toBytes()` |
| stamp/watermark loop | `PdfMerger::create()->append($src)->stamp(PdfSource::fromFile($stamp), placement: Placement::fit())` |
| encrypted source *(unsupported without add-on)* | `PdfSource::fromFile($f, password: '...')` / `ReaderDocument::fromBytes($bytes, '...')` |
| xref-stream sources *(commercial parser add-on)* | supported out of the box |

**Which native tool when:**

- `PdfMerger` — appending/reordering whole documents. Carries annotations,
  outlines and named destinations across (FPDI drops them).
- `PageImporter` — FPDI-style: place an imported page as an XObject inside
  a freshly generated document, draw over or under it.

## Gotchas

- **Coordinates**: the facade keeps FPDF's top-left/mm conventions; the
  native API is PDF-native — bottom-left origin, points. Convert with
  `y_pdf = pageHeight − y_mm × 72/25.4 − height_pt`.
- **Page indexes**: FPDI's `importPage()` is 1-based; the native
  `PageImporter::intoDocument()` is 0-based. The facade stays 1-based.
- **Annotations**: like FPDI, `importPage`/`PageImporter` imports page
  *content* only. If you need links/outlines preserved, use `PdfMerger`.
- **`adjustPageSize`**: instead of FPDI's flag, pass the template size to
  `AddPage('', $pdf->getTemplateSize($tpl))` — explicit and equivalent.

The mappings above are exercised by `tests/Compat/FpdiCompatTest.php`.

---

Language: [English](MIGRATION-FROM-FPDI.md) · [Русский](../ru/MIGRATION-FROM-FPDI.md) · [中文](../zh/MIGRATION-FROM-FPDI.md) · [Deutsch](../de/MIGRATION-FROM-FPDI.md)
