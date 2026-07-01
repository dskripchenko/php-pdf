# Reading & merging existing PDFs

Beyond generating PDFs from scratch, `dskripchenko/php-pdf` can **read existing
PDF files** and **combine** them: append one document after another, pick
individual pages, and stamp a page from one document onto pages of another.

All of it is pure PHP and MIT-licensed — no FPDI, no external binaries.

## Contents

- [Reading a PDF](#reading-a-pdf)
- [Appending documents](#appending-documents)
- [Selecting and reordering pages](#selecting-and-reordering-pages)
- [Embedding (stamping) a page](#embedding-stamping-a-page)
- [Placement](#placement)
- [Encrypted input](#encrypted-input)
- [What the reader handles](#what-the-reader-handles)
- [Limitations (v1)](#limitations-v1)

## Reading a PDF

```php
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

$doc = ReaderDocument::fromBytes(file_get_contents('report.pdf'));

echo $doc->pageCount();               // number of pages
foreach ($doc->pages() as $page) {
    printf("%.0f × %.0f pt\n", $page->width(), $page->height());
}
```

A `ReaderDocument` resolves objects lazily, follows the cross-reference chain
(classic tables, XRef streams, and object streams), and falls back to a
full-file scan when the cross-reference is corrupt.

## Appending documents

`PdfMerger` concatenates pages from one or more sources into a new document
(pdftk-style):

```php
use Dskripchenko\PhpPdf\Pdf\Merge\{PdfMerger, PdfSource};

$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('cover.pdf'))
    ->append(PdfSource::fromFile('body.pdf'))
    ->append(PdfSource::fromFile('appendix.pdf'))
    ->toBytes();

file_put_contents('combined.pdf', $bytes);
// or: PdfMerger::create()->append(...)->toFile('combined.pdf');
```

`PdfSource` accepts a file path or raw bytes:

```php
PdfSource::fromFile('/path/to.pdf');
PdfSource::fromBytes($binaryString);
```

## Selecting and reordering pages

Pass 1-based page numbers, in the order you want them in the output:

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'), pages: [1, 3, 5])   // subset
    ->append(PdfSource::fromFile('b.pdf'), pages: [2, 1])      // reordered
    ->toBytes();
```

Omit `pages` to take every page in reading order. Each output page keeps its
source geometry (MediaBox, CropBox, rotation).

## Embedding (stamping) a page

`stamp()` draws a page from one document on top of already-appended pages — the
source page becomes a reusable Form XObject. Use it for watermarks, letterheads,
backgrounds, or placing a page as a figure.

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('invoices.pdf'))          // base pages
    ->stamp(
        PdfSource::fromFile('watermark.pdf'),
        page: 1,                                            // which source page
        onPages: null,                                      // null = every output page
        placement: Placement::fit(),
    )
    ->toBytes();
```

Target specific output pages with `onPages` (1-based):

```php
->stamp(PdfSource::fromFile('logo.pdf'), page: 1, onPages: [1], placement: Placement::at(40, 40, 0.5))
```

The base page's own content is preserved and drawn first; the overlay is drawn
on top from a clean graphics state.

## Annotations and bookmarks

Page annotations and the document outline (bookmarks) are carried into the
merged output **by default**. Internal links and bookmark destinations —
including named destinations — are remapped to the new pages. A link or
bookmark whose target page is not part of the output is dropped; external
`URI` links are always kept.

```php
PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'))
    ->withoutAnnotations()   // opt out of annotation carry-over
    ->withoutOutlines()      // opt out of bookmark carry-over
    ->toBytes();
```

Form-field widgets (AcroForm) and popup annotations are not carried.

## Placement

`Placement` controls how the embedded page (sized in points) maps onto the
target page:

| Factory | Behaviour |
|---|---|
| `Placement::fit()` | Scale to fit, preserving aspect ratio, centered. |
| `Placement::stretch()` | Fill the target page exactly (aspect ratio ignored). |
| `Placement::at($x, $y, $scale = 1.0)` | Lower-left corner at `($x, $y)`, scaled by `$scale`. |

Rotated source pages (`/Rotate` 90/180/270) are baked upright via the form's
`/Matrix`, so placement works in intuitive upright coordinates.

## Encrypted input

Encrypted sources are decrypted transparently on read and re-emitted
**unencrypted** in the merged output:

```php
PdfSource::fromFile('protected.pdf', password: 'secret');
```

Supported: RC4 (40/128-bit), AES-128 (AESV2), and AES-256 (V5 R5/R6). Both the
user and owner password are tried. An empty password (the common "owner-only
restrictions" case) works without any argument.

## What the reader handles

- Classic `xref` tables, XRef streams (PDF 1.5+), hybrid `/XRefStm`, and
  incremental updates (`/Prev`).
- Object streams (compressed objects).
- Filters: Flate (with PNG/TIFF predictors), LZW, ASCII85, ASCIIHex,
  RunLength. Image filters (DCT/JPX/CCITT/JBIG2) are copied through verbatim.
- Corrupt/missing cross-reference recovery by scanning object headers.
- Page-tree flattening with inherited MediaBox/CropBox/Rotate/Resources.

## Limitations (v1)

Merging rebuilds each page from its content and resources. The following are
**not** carried into the output yet:

- Interactive form fields (AcroForm) and widget/popup annotations — dropped.
- Structure tags (Tagged PDF / PDF-A conformance of the result).
- Image and font streams are copied verbatim, never re-encoded.

Annotations and outlines (bookmarks) with internal/named destinations *are*
carried and remapped — see [Annotations and bookmarks](#annotations-and-bookmarks).

These are documented so "supports merge" is not mistaken for "preserves
everything". See `docs/design/pdf-merge.md` for the full scope and roadmap.
