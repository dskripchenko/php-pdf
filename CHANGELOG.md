# Changelog

All notable changes to `dskripchenko/php-pdf` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.0.0
