# Changelog

All notable changes to `dskripchenko/php-pdf` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.1] — 2026-08-05

### Changed
- `keepWithNext` now also applies when the heading is a list item: the
  requirement is then carried by the list, and the whole list moves with the
  block that follows it.

### Notes
- Tried and rejected: "a row that fits on an empty page moves there whole
  instead of being split" — that is what Word did in the case under study, but
  across the corpus it cost three pages of agreement and pushed two more
  documents apart. Word's rule here is more than a single condition; the note
  is left in the code so the attempt is not repeated.

## [1.7.0] — 2026-08-05

### Added
- **`ParagraphStyle::$keepWithNext` — "keep with next".** A section heading
  must not be left as the last line of a page: when nothing fits after it,
  both it and the following block move to the next page. The check looks at
  the first line of the next block rather than the whole of it — demanding the
  entire block would throw away half a page for the sake of a long table.

## [1.6.0] — 2026-08-05

### Added
- **Floating objects** — `Image::$outOfFlow`. Word anchors stamps and
  signatures to a paragraph and offsets them from the anchor point so they lie
  over finished text. Such an object takes no room in the flow: while it stood
  in the flow as a line of its own, the document was pushed apart by its
  height, which cost an insurance policy an extra page. A floating image is
  drawn where its offset puts it, the cursor returns to where it stood, and
  the surrounding text does not move. Ordinary images are unaffected.

## [1.5.3] — 2026-08-05

### Fixed
- **A forced page break inside a table cell is ignored, as Word ignores it.**
  Word cannot start a page in the middle of a cell, so such a break survives
  in the file as a leftover of editing and renders as nothing. Acting on it
  tore a real application form apart: the row carrying the break went to a
  page of its own and everything after it began on the following page. Two
  such leftovers turned a seven-page document into nine. Breaks outside tables
  work exactly as before.

## [1.5.2] — 2026-08-05

### Fixed
- **Fill colour leaked into all the text that followed.** `rg` is graphics
  state: it holds until the next `rg`, yet "no colour given" was treated as
  "emit nothing". Everything after a coloured heading was drawn in that
  colour — an insurance application came out entirely blue, tables included.
  Absence of a colour now means black rather than "keep whatever is set".

  The state comparison was fixed along the way: `0 === 0.0` is false in PHP,
  so black never matched the current state and `rg` was re-emitted on every
  line.

## [1.5.1] — 2026-08-05

### Fixed
- **Letter spacing from one run spread across the whole document.** `Tc` is a
  text-state parameter: it lives until the next `Tc`, not until the nearest
  `ET`. The operator was emitted only for non-zero spacing, so everything
  after a tracked heading inherited it. On a real contract the result was
  unreadable: lines grew wider than their column, overlapped each other and
  ran off the page — text extraction returned two-letter fragments.

  The value is now tracked and reset to zero explicitly. Documents without
  letter spacing are unchanged: they still carry no `Tc` at all (the initial
  value per the specification is zero).

- **Footers took more from the body than they occupied.** The decision to
  raise the body's bottom edge compared "footer height + 8pt gap" against the
  page's bottom margin, ignoring the distance from the page edge at which the
  footer is declared. That gap has no business in the comparison: the body was
  shrinking for footers that fit into the margin perfectly well. On A4 with a
  single-line footer this cost ~15pt of height on every page — roughly six
  lines over five pages, enough to spill onto an extra page.

## [1.5.0] — 2026-08-05

### Fixed
- **Image transparency was lost — backgrounds printed black.** The alpha
  channel of a PNG (colour types 6 and 4) was simply dropped on insertion, so
  transparent pixels came out in whatever colour sat underneath: a signature
  and a stamp from an imported document ended up as black rectangles. PDF
  keeps transparency in a separate object, so alpha is now extracted into an
  8-bit `DeviceGray` mask and attached through `/SMask`. A fully opaque image
  gets no mask — an extra object in the file buys nothing.

### Added
- `PdfImage::$alphaData` — the alpha channel as a separate stream.

## [1.4.0] — 2026-08-04

### Added
- **Header and footer distances** — `PageSetup::$headerDistancePt` /
  `$footerDistancePt`. Word declares them separately from the page margin
  (`w:header`, `w:footer`), while the engine pinned the header to the very
  edge of the sheet: an imported document drifted two centimetres up relative
  to the original. The default is 4pt — the previous behaviour. The header
  zone now accounts for both the distance and its own height, so a large
  `w:header` no longer lets body text run into the header.

## [1.3.1] — 2026-08-04

### Fixed
- Types in the `renderRow()` docblocks — 1.3.0 was tagged with static analysis
  failing (three `missingType.iterableValue`). A stale baseline entry was
  dropped along the way: it described the earlier, less precise type of a
  by-reference parameter.

## [1.3.0] — 2026-08-04

### Added
- **A tall table row is split across pages.** Previously a row that did not
  fit into the remainder of a page was carried over whole — leaving a
  half-empty sheet behind. In an insurance policy under study this produced a
  page with 40 words instead of seven hundred: a long block of terms lived
  inside a table. Cell content is now split at the page boundary, the table
  header repeats on the continuation, and the printed document matched the
  original in page count.

  The split goes by blocks: a cell is a list of paragraphs and the boundary
  runs between them. We do not descend into a paragraph. Header rows are not
  split (they are repeated anyway), nor are rows with a vertical merge
  (`rowSpan`) — the break would drift apart from the neighbouring cells. If
  not a single block fits into the remainder, the row is carried over whole as
  before: otherwise it would loop forever.

## [1.2.7] — 2026-08-04

### Fixed
- **Adjacent runs with no space between them drifted apart.** Words inside a
  run are cut into tokens and the line is reassembled with spaces — so a space
  that does not exist in the text appeared at the seam between two runs. Word
  splits a line at every change of formatting, which is why "(" and
  "залогодатель" arrive as separate runs, and the imported document printed as
  "СТРАХОВАТЕЛЬ ( залогодатель )". A seam where neither run ended or started
  with a space is now glued. Found by comparing print output against a
  reference document in printable: text agreement with the original rose from
  99.9% to 100%.

## [1.2.6] — 2026-07-31

### Performance
- **The subset's `post` table no longer carries glyph names** (format 3.0
  instead of 2.0). Names of every glyph in the source font survived into the
  subset in full and were its largest table: 26 KB out of 61 KB on Liberation
  Sans (43%), while the outlines took 6 KB.

  A PDF has no use for glyph names: rendering goes by identifier and text
  extraction by the `ToUnicode` CMap, which the emitter writes separately.

  **Consumer-side documents: 54.6 → 30.0 KB (an invoice), 73.6 → 49.0 KB (a
  nine-page contract).** Rendering is unchanged — verified pixel by pixel
  through poppler on every page: zero differences. Goldens need no
  regeneration.

  The table header (slant, underline, monospace flags) is preserved — format
  3.0 differs only in dropping the names.

## [1.2.5] — 2026-07-31

### Performance
- **Compressed font bodies are remembered.** Level-6 `gzcompress` over a font
  body is the most expensive part of embedding: a measurement in the consuming
  application showed 2.2 ms per document with two 62 KB fonts, while the whole
  render took 12.6 ms. The body is identical from document to document.

  **12.6 ms → 9.6 ms per document (−24%).** The document itself does not
  change: embedded font bodies are byte-for-byte the same.

  The key is a content hash (`xxh3`) rather than a file name: it stays correct
  for a subset, which differs per document. Hashing 62 KB costs microseconds
  against a millisecond of compression.

  The cache is bounded by `PdfFont::$compressedCacheLimit` (24 by default);
  `PdfFont::forgetCompressedCache()` clears it. As with the parsed-font cache
  from 1.2.4, the gain goes to long-lived processes — a queue worker and
  Octane.

## [1.2.4] — 2026-07-30

### Performance
- **Parsed fonts are remembered.** `TtfFile::fromFile()` read the whole file
  and parsed `cmap`/`hmtx`/`name`/`post` (plus `GPOS` on demand) every time,
  even though the result for a given file is always the same and the object is
  immutable.

  Measured in a consuming application that builds the renderer anew for each
  document: **14.6 → 8.4 ms on a light document (−43%)**, −4.7 ms on a heavy
  one. The gain goes to long-lived processes (queue worker, Octane); under
  process-per-request the state is discarded between requests anyway.

  The cache is bounded by `TtfFile::$cacheLimit` (24 fonts by default) — a
  process serving many clients with their own font sets would otherwise
  accumulate all of them. `TtfFile::forgetCache()` clears it.

  The key includes the file's modification time and size: a replaced font is
  parsed again.

## [1.2.3] — 2026-07-28

### Changed
- CI: PHP **8.5** added to the test matrix — the supported range
  (8.2–8.5) is now verified on every push.
- Docs: the Fonts highlights link to the Liberation bundle; the
  `fallbackFonts` entry moved to the release section it shipped in; the
  mpdf migration guide points its download row at the Laravel bridge.

## [1.2.2] — 2026-07-18

### Fixed
- **Aztec: letter case inverted after a shifted uppercase character.**
  From Lower mode, code 28 is U/S — an upper shift valid for one
  character — but the encoder latched its mode variable, desynchronizing
  from the decoder ("Aztec-Test" scanned as "Aztec-TEst"). Found while
  reviewing findings frozen in the PHPStan baseline; verified with ZXing.

### Changed
- PHPStan baseline halved (129 → 65 entries): every missing iterable
  value type got a precise annotation, dangling docblocks re-attached,
  and dead code removed (legacy watermark renderers, superseded bidi
  helpers, unused mode-switch constants). Remaining entries are reviewed
  false positives, documented as such.

## [1.2.1] — 2026-07-18

### Fixed
- **QR codes were unreadable by real scanners.** The 15-bit format
  information (ECC level + mask) was written LSB-first into both matrix
  copies — a mirrored, BCH-invalid codeword. Viewers happily rendered the
  symbol; every decoder (zbar, ZXing, phone cameras) rejected it. Both
  copies now follow ISO/IEC 18004 bit order; decodability is covered by a
  pure-PHP BCH round-trip test plus an end-to-end zbar test.
- **SVG gradients, `<style>` and `<use>` were silently ignored for
  real-world files.** SimpleXML's xpath() cannot address the default
  namespace, so any SVG declaring `xmlns="http://www.w3.org/2000/svg"`
  (i.e. all of them) lost its `//linearGradient`, `//style` and `//use`
  lookups — a gradient-filled rect rendered opaque black. The renderer
  now strips the default-namespace declaration before parsing.

- **Tagged PDF: marked-content sequences no longer nest.** Table/TR/L
  used to open their own MCID sequences around their children's — invalid
  per ISO 32000 and flagged by veraPDF. Grouping elements (Table, TR, L)
  now live purely in the structure tree with their children in /K; only
  leaves (P, TD, LI, Hn, Figure) own marked content. The ParentTree is
  now correctly indexed by MCID.

### Added
- **PDF/X-1a conformance reference** with a CMYK output intent
  (CGATS21_CRPC1 from the ICC registry, fetched on demand); the `/N` key
  of embedded output-intent profiles now derives from the profile's
  colour space instead of a hardcoded RGB. The X-1a checker enforces
  `/N 4` and bans device-RGB operators in content streams.
- `<rect rx="...">` rounded corners in the SVG renderer (solid fills and
  strokes; pattern fills stay square for now).
- Viewer matrix: automated pdf.js (Firefox engine) and Quartz (macOS
  Preview engine) columns; barcode decodability verified with zbar,
  ZXing and libdmtx against rendered output.

## [1.2.0] — 2026-07-17

### Fixed
- **`Engine(fallbackFonts: [...])` never actually switched fonts.** The
  draw-call batcher merged equal-styled words before font resolution, so
  mixed-script paragraphs rendered `.notdef` boxes for every character the
  main font lacked. Fonts are now resolved per word and participate in
  batch compatibility — Latin words draw with the main font, CJK/Arabic
  words with the first covering fallback, even inside a single `Run`.
  Latin-only output is byte-identical to before.

### Added
- **Migration compat facades** for the two libraries php-pdf replaces:
  `Compat\Mpdf` (`WriteHTML`/`AddPage`/`Output` F·S·D·I, metadata
  setters, `format`/`orientation`/margin config keys) and `Compat\Fpdi`
  (`setSourceFile`/`importPage`/`getTemplateSize`/`AddPage`/
  `useTemplate`/`Output` with FPDF top-left/mm coordinate conventions,
  both `Output` argument orders, escape hatches to the native
  `Pdf\Page`/`Pdf\Document`). Migration guides with full mapping tables:
  `docs/en/MIGRATION-FROM-MPDF.md`, `docs/en/MIGRATION-FROM-FPDI.md`.

### Changed
- Benchmark harness is now a first-class reproducible artifact:
  `composer bench` from a clean clone installs the pinned competitors
  (`scripts/bench/composer.lock`, committed), runs isolated-subprocess
  measurements and writes `results.{json,csv,md}` with the full
  environment (PHP, OS, exact library versions). The tables in
  `docs/en/BENCHMARKS.md` and all README performance sections are
  generated by the harness (`--docs`), never edited by hand.

## [1.1.3] — 2026-07-17

### Fixed
- **PDF/X output was missing Info keys and page boxes required by
  ISO 15930.** `/GTS_PDFXVersion` and `/ModDate` are now written to `/Info`,
  and pages default to a `/TrimBox` equal to the MediaBox when neither
  TrimBox nor ArtBox was set.
- **Rectangular DataMatrix symbols (ECC 200, e.g. 12×26) rendered
  distorted.** The 2D matrix renderer assumed square symbols and read past
  the module matrix; it now honours the symbol's true width × height.
- **TrueType `cmap` subtables are now merged instead of picking one.**
  A format 12 subtable is not necessarily a superset of format 4 (e.g.
  DroidSansFallback maps CJK only in format 12); previously half the font's
  coverage could be lost.

### Added
- Torture set (`examples/torture/`) — 11 worst-case documents (complex
  tables, Cyrillic/Greek, Arabic bidi, CJK, 5 barcode symbologies, charts,
  SVG, AcroForm, signature, PDF/A-2u, merge+stamp), smoke-rendered by
  poppler and Ghostscript in CI; manual cross-viewer checklist in
  `docs/en/VIEWER-MATRIX.md`.
- PDF/X structural validation in CI (Ghostscript + byte-level checks) and
  visual regression against golden renders; conformance checks moved to a
  dedicated `conformance` workflow with its own badge and
  `docs/en/CONFORMANCE.md`.
- `scripts/fetch-fonts.sh` now also fetches Amiri (Arabic) and
  DroidSansFallback (CJK) for the torture set.
- OpenSSL CLI verification of PKCS#7 signatures in the test suite.

## [1.1.2] — 2026-07-17

### Fixed
- **Stream `/Length` counted the EOL delimiter before `endstream` as data.**
  ToUnicode CMap streams (emitted for every embedded TrueType font) and
  uncompressed content streams declared a `/Length` one byte too long,
  violating PDF/A clause 6.1.7 (ISO 19005). Viewers tolerate it; validators
  don't. All PDF/A flavours (1b, 1a, 2b, 2u, 3b) now validate as compliant
  with veraPDF.

### Added
- PDF/A conformance validation in CI: reference documents with embedded
  fonts, Cyrillic text and tables are generated per flavour and checked with
  veraPDF on every push (`scripts/conformance/`, job `conformance-pdfa`);
  reports are published as workflow artifacts.
- Vendored sRGB2014 ICC profile (`resources/icc/sRGB2014.icc`) usable as an
  `/OutputIntents` profile for `PdfAConfig` / `PdfXConfig`.

## [1.1.1] — 2026-07-06

### Fixed
- **Merged pages rendered blank in real PDF viewers.** Imported streams
  (page `/Contents`, image XObjects, embedded fonts) were written as a
  reference-to-a-reference (`N 0 obj  M 0 R  endobj`). Lenient readers
  dereferenced the chain, but poppler / Ghostscript / Acrobat rejected
  `/Contents` with "weird page contents" and drew blank pages. Fixed; merge
  output is now verified against a real renderer (pdftotext) in the test suite.

## [1.1.0] — 2026-07-01

### PDF reading & merging (new)
- `ReaderDocument` — parse existing PDFs: classic `xref`, XRef streams,
  object streams, hybrid `/XRefStm`, incremental updates, and corrupt-xref
  recovery by scanning object headers.
- Stream filters: Flate (with PNG/TIFF predictors at any bit depth — 8- and
  16-bit, plus sub-byte for PNG), LZW, ASCII85, ASCIIHex, RunLength; image
  filters (DCT/JPX/CCITT/JBIG2) passed through verbatim.
- Standard-security-handler decryption: RC4 (40/128), AES-128 (AESV2),
  AES-256 (V5 R5/R6), with user- and owner-password support. Wrong/missing
  passwords fail fast with a clear error (validated against /U) instead of
  producing corrupt output.
- Validated against a third-party corpus (pdfTeX, LibreOffice, Google
  Docs/Skia, Qt/pdfkit, Ghostscript, ImageMagick, FPDF2, pypdf).
- Page-tree flattening with inherited MediaBox/CropBox/Rotate/Resources.
- `PdfMerger` — append/reorder whole or selected pages from multiple sources
  (`PdfSource::fromFile()` / `fromBytes()`, optional password).
- `PdfMerger::stamp()` — embed a source page onto output pages as a Form
  XObject with `Placement::fit()/stretch()/at()`; rotation baked via `/Matrix`.
- `PageImporter::intoDocument()` (FPDI-style) — import an existing PDF page
  into a freshly generated `Pdf\Document` as a Form XObject, so new php-pdf
  content can be drawn over/under it. `Document::registerImportedForm()`
  injects the foreign resource closure into the writer with references
  renumbered; `PdfValueSerializer` re-emits value trees with a ref map.
- `PdfMerger` carries page annotations and the document outline (bookmarks)
  by default, remapping internal links and named destinations to the new
  pages (dangling ones dropped, external URI links kept). Opt out with
  `withoutAnnotations()` / `withoutOutlines()`. Widget/popup annotations are
  not carried. `ReaderDocument::namedDestinations()` resolves the /Dests
  dictionary and /Names /Dests name tree.
- See [docs/en/MERGE.md](docs/en/MERGE.md). v1 does not carry over AcroForm
  fields or structure tags (annotations and outlines *are* carried).

### Fixed
- **PHP 8.2 support restored** — several classes used typed class constants
  (`const string …`), a PHP 8.3+ feature, so the package failed to parse on
  PHP 8.2 despite declaring `"php": "^8.2"`. The constant types were removed
  (behaviour unchanged).
- LZWDecode code-width widening was off by one (EarlyChange), corrupting LZW
  streams that grow past 9-bit codes (large images); now validated against a
  real ImageMagick/libtiff stream.

### Tooling
- GitHub Actions CI (PHP 8.2 / 8.3 / 8.4) running the test suite, plus a
  PHPStan (level 6) gate.

## [1.0.0] — 2026-05-15

Initial public release.

### PDF emission
- ISO 32000-1 (PDF 1.7) and ISO 32000-2 (PDF 2.0) output.
- Cross-reference: classic `xref…trailer` and XRef streams (PDF 1.5+).
- Object Streams for compact metadata-heavy documents.
- Balanced Page Tree for large documents.
- Page boxes (CropBox, BleedBox, TrimBox, ArtBox), rotation, tab order.
- Named destinations, multi-level outline (bookmarks panel).
- Page transitions and auto-advance.
- Optional Content Groups (layers) with default-visible toggling.
- Streaming output to a stream resource for large documents.

### HTML and CSS input
- `Document::fromHtml()` HTML5 parser entry point.
- Block tags: `<p>`, `<h1>`–`<h6>`, `<ul>`, `<ol>`, `<li>`, `<table>`,
  `<thead>`, `<tbody>`, `<tr>`, `<td>`, `<th>`, `<blockquote>`, `<hr>`,
  `<pre>`, `<dl>`, `<dt>`, `<dd>`.
- Inline tags: `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<del>`,
  `<sup>`, `<sub>`, `<br>`, `<a>`, `<span>`, `<code>`, `<kbd>`, `<samp>`,
  `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`, `<cite>`,
  `<dfn>`, `<q>`, `<abbr>`.
- HTML5 semantic blocks: `<header>`, `<footer>`, `<nav>`, `<aside>`,
  `<main>`, `<article>`, `<section>`, `<figure>`, `<figcaption>`.
- Legacy tags: `<center>`, `<font>` (color, face, size 1–7).
- Inline CSS: color, background-color, font-family, font-size,
  font-weight, font-style, text-decoration, text-transform,
  text-align, margin, padding, border, line-height, text-indent.
- Table caption support, heading auto-anchors for internal hyperlinks.

### Layout
- Knuth–Plass box–glue–penalty line breaker with adaptive penalty for
  ragged-right output.
- Hyphenation hooks and soft-hyphen handling.
- Multi-column layout (`ColumnSet`) with column-first flow.
- Tables with rowspan/colspan, border collapse, double borders, border
  radius, border-spacing, cell padding.
- Headers, footers, watermarks (text and image, with opacity).
- Page setup: paper sizes (A0–A6, B0–B6, Letter, Legal, Executive,
  Tabloid, plus custom), portrait/landscape, margins, gutter.
- Section breaks with per-section page setup.
- Footnotes with page-bottom positioning.

### Text and fonts
- 14 Adobe base-14 standard fonts (WinAnsi encoding).
- TTF embedding with on-demand subsetting (CFF and TrueType outlines).
- Kerning, basic GSUB ligatures and single substitutions.
- ToUnicode CMap for searchable / copy-pasteable Cyrillic, Greek, CJK.
- Variable font instances (fvar, gvar, MVAR, HVAR, avar).
- Font fallback chain via `ChainedFontProvider`.
- Bidi text (UAX#9) with Arabic shaping and basic Indic shaping
  (Devanagari, Bengali, Gujarati).
- Vertical text writing mode.

### Barcodes
- Linear: Code 128 (A/B/C auto-switch + GS1-128), Code 39, Code 93,
  Code 11, Codabar, ITF/ITF-14, MSI Plessey, Pharmacode (Laetus),
  EAN-13/EAN-8 (with 2/5-digit add-ons), UPC-A, UPC-E.
- 2D: QR Code V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI,
  Structured Append, FNC1 GS1/AIM), Data Matrix ECC 200 (all
  standard sizes incl. 144×144, rectangular variants, 6 modes,
  Macro 05/06, GS1, ECI), PDF417 (Byte/Text/Numeric, Macro, GS1, ECI),
  Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- QR convenience factories: vCard 3.0, WiFi Joinware, RFC 6068 mailto.

### Charts
- BarChart, LineChart, PieChart, AreaChart (stacked or independent),
  DonutChart, GroupedBarChart, StackedBarChart, MultiLineChart,
  ScatterChart.
- Configurable axis titles, label rotation, grid lines, legends,
  smoothing.

### Forms and interactivity
- AcroForm widgets: text (single/multiline/password), checkbox, radio
  group, combo box, list box, push/submit/reset buttons, signature.
- AcroForm appearance streams (NeedAppearances + per-widget /AP).
- Per-field JavaScript actions: keystroke, validate, calculate, format,
  click. Document-level WillClose/WillSave/DidSave/WillPrint/DidPrint
  actions; page open/close actions.
- Markup annotations: Text, Highlight, Underline, StrikeOut, FreeText,
  Square, Circle, Line, Stamp, Ink, Polygon, PolyLine.
- Hyperlink kinds: URI, named destination, JavaScript, Launch, Dest.

### Security
- Encryption: RC4-128 (V2 R3), AES-128 (V4 R4 / CFM AESV2),
  AES-256 (V5 R5 Adobe Supplement / CFM AESV3), AES-256 R6 (ISO
  32000-2 / PDF 2.0 Algorithm 2.B iterative hash).
- Permission bits (printing, copying, modifying, annotating,
  filling forms, accessibility, assembly, high-quality printing).
- Encrypted strings + encrypted streams + encrypted Catalog.

### Digital signing
- PKCS#7 detached signing with `openssl_pkcs7_sign`.
- Signed-at timestamp, signer name, reason, location, contact info.
- /ByteRange auto-patching with placeholder /Contents (16384 hex zeros).
- SigFlags 3 (SignaturesExist | AppendOnly).

### Print and accessibility
- PDF/A-1b, PDF/A-1a, PDF/A-2u conformance with embedded sRGB ICC.
- PDF/X-1a, PDF/X-3, PDF/X-4 with /OutputIntent /S /GTS_PDFX.
- Tagged PDF with StructTreeRoot, MCID marking, custom RoleMap,
  /StructParent annotation linking, /ParentTree number tree.
- /Lang attribute, /MarkInfo, /ViewerPreferences (HideToolbar,
  HideMenubar, FitWindow, CenterWindow, DisplayDocTitle, Direction,
  PrintScaling, Duplex).
- Page labels (decimal, Roman upper/lower, alpha upper/lower) with
  per-range prefixes and starting numbers.

### Image and graphics
- JPEG, PNG (8-bit truecolor + 8-bit palette + alpha via SMask).
- Identity-dedup by content hash (same image used N times = one XObject).
- Color spaces: DeviceRGB, DeviceCMYK, DeviceGray.
- ExtGState: opacity, blend mode, line styles.
- Patterns: tiling (Type 1) and shading (Type 2 axial / Type 3 radial,
  stitching functions for multi-stop gradients).
- Form XObjects (reusable content streams referenced by `/Do`).
- Clipping paths, transforms (q…Q with cm), text rendering modes.

### Math and SVG
- LaTeX subset rendering: fractions, sqrt, super/subscript, big
  operators (sum, product, integral), matrices (pmatrix, bmatrix,
  vmatrix), multi-line environments (align, gather, cases).
- Inline SVG embedding via `<svg>` in HTML or `SvgElement`. Paths,
  shapes, gradients, transforms, `<use>`/`<defs>`, CSS class styling.

### Embedded files
- File attachments via `Document::attachFile()` — visible in the
  reader's attachments panel.

### Quality
- 1977 tests, ~119k assertions, all passing.
- Pure PHP — no shell-outs, no native extensions beyond `ext-mbstring`,
  `ext-zlib`, `ext-dom`, plus `ext-openssl` when AES or PKCS#7 is used.
- PHP 8.2+, strict types throughout.

[1.1.1]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.1.1
[1.1.0]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.1.0
[1.0.0]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.0.0
