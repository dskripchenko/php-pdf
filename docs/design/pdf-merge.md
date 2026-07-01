# Design & work plan: PDF read + merge

Status: **Draft / in progress** · Branch: `feature/pdf-merge` · Target: `dskripchenko/php-pdf` 1.x minor

## 1. Motivation

`php-pdf` is currently **write-only** — it emits PDF from scratch (`Pdf\Document` → `Pdf\Writer`) but cannot read an existing PDF. This document specifies a new **reader + merge** subsystem so callers can:

1. **Append** — take document A, add document B after it (pdftk-style page concatenation).
2. **Embed** — place selected pages of one document onto a page of another, with scale/position control (FPDI-style page-as-XObject).

The existing PHP stack solves this only through proprietary/copyleft add-ons (FPDI, smalot/pdfparser). A native MIT reader is a direct differentiator and keeps the "no licensing friction" promise intact.

## 2. Scope

Input class: **arbitrary third-party PDFs** (scans, varied producers, encrypted). This drives a robust parser, not a happy-path one.

### 2.1 In scope (v1)

**File structure**
- Classic `xref` tables and cross-reference **streams** (§7.5.8), including hybrid-reference files.
- Incremental updates: multiple sections chained via `/Prev`; latest object wins.
- Object streams (§7.5.7) — required (our own Writer emits them).
- **Recovery**: rebuild the object index by scanning `N G obj` when `xref` is broken or missing.

**Stream filters (decode)**
- `FlateDecode` with PNG/TIFF predictors, `LZWDecode`, `ASCII85Decode`, `ASCIIHexDecode`, `RunLengthDecode`.
- `DCTDecode` / `JPXDecode` / `CCITTFaxDecode` / `JBIG2Decode`: **not decoded** — image streams are copied to the output byte-for-byte. Merge never needs pixels.

**Encryption (decode)**
- Standard security handler: RC4 (40/128), AESV2-128, AESV3-256; crypt filters; empty-user-password common case and caller-supplied password. Built on the existing `Pdf\Encryption` primitives.

**Page model**
- Page-tree flattening with inheritance of `/Resources`, `/MediaBox`, `/CropBox`, `/Rotate`.

**Operations**
- `PdfMerger::append()` — concatenate all/selected pages of N sources, object renumbering, rebuilt `/Pages`.
- `PdfMerger::embedPage()` — import a page as a Form XObject (BBox from CropBox, Matrix from Rotate) placed on a target page.

### 2.2 Out of scope (v1) — explicit non-goals

Stated so "supports merge" is not read as "supports everything":

- **AcroForm** field-tree merge — interactive fields are flattened or dropped (with a warning), not reconciled.
- **/StructTree** (tagged PDF) remap — structure tags on imported pages are lost; output PDF/A conformance is not guaranteed after import.
- Image/font **re-encoding** — resources are copied verbatim, never decoded/re-subset.
- Text reflow or editing.
- Optional-content (layer) configuration reconciliation — references carried, configs not merged.

## 3. Architecture

One public entry point over a shared reader; two operation layers.

```
src/Pdf/Reader/
  Lexer.php          Tokenizer for PDF object syntax
  ObjectParser.php   dict / array / name / string(lit+hex) / ref / stream / num / bool / null
  XrefReader.php     classic + xref-stream + /Prev chain + recovery scan
  ObjectStream.php   decompress §7.5.7 compressed objects
  Filters/           Flate(+predictor), LZW, ASCII85, ASCIIHex, RunLength
  Decryptor.php      wraps existing Pdf\Encryption for RC4/AES
  ReaderDocument.php lazy object-graph resolver (cycle guard) + trailer/Root
  PageTree.php       walk /Pages, resolve inherited attributes

src/Pdf/Merge/
  PdfMerger.php       public API: append(), embedPage()
  ObjectImporter.php  copy an object subtree into a target Document with id remapping
```

**Shared primitive — object import with renumbering.** Both operations copy a subtree of foreign objects into the target document. For arbitrary input the only safe transfer is **verbatim byte pass-through** of stream objects (re-encoding foreign fonts/images guarantees regressions). This requires one Writer addition: a "raw pass-through object" primitive that emits an already-serialized foreign object as-is.

**append.** For each source page: import the page dict, remap resource refs via `ObjectImporter`, attach to the target `/Pages`. Byte-faithful.

**embed.** Concatenate the page's content stream(s) into a Form XObject; `/BBox` from CropBox; `/Matrix` compensates `/Rotate`; import the page's transitive resource closure (fonts, images, nested XObjects, ExtGState, ColorSpace, Shading, Pattern). Requires extending `Pdf\PdfFormXObject` to carry its own `/Resources` (today it explicitly does not — "scope kept small").

## 4. Public API sketch (to be refined in Phase 0)

```php
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;

// Append B after A into a new document
$out = PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'))
    ->append(PdfSource::fromFile('b.pdf'), pages: [1, 3, 5])   // subset
    ->toBytes();

// Embed page 2 of A onto a freshly built page
$doc  = new Document();
$page = $doc->addPage();
$src  = PdfSource::fromFile('a.pdf', password: null);
$xobj = $merger->importPageAsXObject($src, pageIndex: 2);
$page->useFormXObject($xobj, x: 50, y: 400, w: 200, h: 280);
```

Exact shapes finalized in Phase 0.

## 5. Testing strategy

Third-party input makes the corpus the real deliverable.

- **Producer corpus:** LibreOffice, Word, Chrome print-to-PDF, mpdf, scanner output, and our own `php-pdf`.
- **Structure variants:** classic xref, xref-stream, broken/absent xref (recovery), incremental updates, encrypted (RC4/AES, empty + real password), rotated pages, CropBox ≠ MediaBox.
- **Round-trip goldens:** import → emit → re-read yields identical page count and page dimensions.
- **Render smoke:** rasterize via an external renderer in CI, pixel-diff against baseline.

## 6. Work plan (phased)

Even though the goal is both operations, they share the reader and `append` is strictly simpler than `embed`. Land order: **Reader → append → embed**, behind an API designed for both from the start.

- [x] **P0 — Design freeze.** Finalize `PdfMerger` / `PdfSource` API, error taxonomy, non-goals. (this doc)
- [x] **P1 — Lexer + ObjectParser.** Tokenize and parse every object type; unit tests on literal fixtures. — `6ad7bd7`, 14 tests.
- [ ] **P2 — Xref (classic) + trailer + Root + lazy resolver.** Read our own `php-pdf` output; assert `pageCount()`.
- [ ] **P3 — Filters.** FlateDecode (+predictors), LZW, ASCII85/Hex, RunLength; decode round-trip tests.
- [ ] **P4 — Xref streams + object streams.** Full support for our own object-stream/xref-stream output.
- [ ] **P5 — Xref recovery scan.** Rebuild index from `N G obj` on corrupt/missing xref.
- [ ] **P6 — PageTree flatten.** Inherit Resources/MediaBox/CropBox/Rotate; expose per-page metadata.
- [ ] **P7 — Decryptor.** RC4 + AESV2/V3 over `Pdf\Encryption`; empty + supplied password.
- [ ] **P8 — ObjectImporter + Writer raw pass-through.** Copy subtree with id remap; emit verbatim foreign objects.
- [ ] **P9 — `PdfMerger::append()`.** Concatenate pages; round-trip goldens across the producer corpus.
- [ ] **P10 — `PdfFormXObject` /Resources + `embedPage()`.** Page-as-XObject with BBox/Rotate; render smoke.
- [ ] **P11 — Docs + CHANGELOG.** User-facing `docs/*/MERGE.md`, README feature row, USAGE section.

Each phase ends green (`phpunit`) before the next starts.
