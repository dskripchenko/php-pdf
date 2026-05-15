# Changelog — dskripchenko/php-pdf

История phases. Активный backlog: [ROADMAP.md](ROADMAP.md).

## v1.6.0-dev (unreleased) — Phases 214-237

Major dev cycle: HTML input + PDF output optimization + layout enhancements
+ barcode coverage + API ergonomics + print conformance.

### HTML/CSS input (major user-facing improvements)
- Phase 219: `HtmlParser` + `Document::fromHtml()` factory. HTML5 subset:
  12 block tags + 10 inline tags + 8 inline CSS properties.
- Phase 224: Block-level CSS support — text-align, margin/padding shorthand
  (1/2/3/4 values), line-height (mult/percent), background-color.
- Phase 228: Extended semantic inline tags — `<code>`, `<kbd>`, `<samp>`,
  `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`, `<cite>`,
  `<dfn>`, `<q>`, `<abbr>`.
- Phase 229: HTML5 semantic blocks — `<header>`, `<footer>`, `<nav>`,
  `<aside>`, `<main>`, `<article>`, `<section>`, `<figure>`,
  `<figcaption>` (transparent flatten when contain block children) +
  `<dl>`/`<dt>`/`<dd>` definition lists.
- Phase 230: Legacy tags — `<center>` (centered Paragraph), `<font>`
  (color/face/size 1-7 mapping).
- Phase 231: Heading auto-anchor — `<h1 id="...">` becomes named
  destination для internal hyperlinks, plus Heading::autoAnchor() slug
  derivation.
- Phase 232: text-indent + border CSS (shorthand + per-side с styles
  solid/double/dashed/dotted/none и full color parsing).
- Phase 233: `<table><caption>` support — emitted as centered bold
  paragraph preceding the Table block.
- Phase 234: text-transform CSS (uppercase/lowercase/capitalize) — applied
  at parse-time с Unicode-aware mb_* functions.
- Phase 235: `<address>`, `<details>`/`<summary>`, `<wbr>`, `<picture>`
  fallback to first descendant `<img>`.
- Phase 236: inline `<svg>` support — extracts outerHTML via DOM
  serialization, wraps в SvgElement, supports px/unit conversions.

### Barcodes (additional)
- Phase 237: DataMatrix 144×144 — largest standard ECC 200 symbol size.
  ZXing-verified parameters: 1558 data + 620 ECC, 36 regions of 22×22
  modules, 10 RS blocks с uneven 8×156 + 2×155 distribution через
  round-robin interleaving.

### Output optimization
- Phase 214: Object Streams (PDF 1.5+) — pack uncompressed dict objects
  в single FlateDecode stream. ~15-30% additional output reduction.
- Phase 215: Cross-Writer font subset dedup LRU cache.
- Phase 220: Balanced Page Tree (PDF spec §7.7.3.3) — auto-applied для
  documents > 32 pages, FANOUT=16 chunks.

### API ergonomics
- Phase 216: `Document::toStream()` + true-streaming `toFile()` на
  top-level API.
- Phase 217: declarative encryption/signing/PDF-A via constructor params.
  New `EncryptionParams` VO + Engine auto-tagged for PDF/A-1a.
- Phase 223: `Document::concat()` static factory для merging multiple
  Documents (batch generation use case).
- Phase 226: `Page::setTabOrder()` — /Tabs entry ('R'/'C'/'S') для form
  field tab navigation order.

### Layout
- Phase 222: Footnote per-page bottom positioning (opt-in via
  `Section::footnoteBottomReservedPt`). Reserves N pt zone at each page
  bottom; footnotes rendered per-page вместо section endnotes.

### Typography
- Phase 218: `KnuthPlassLineBreaker` — optimal box-glue-penalty
  line-breaking. Stand-alone library utility.

### Barcodes
- Phase 221: Aztec Structured Append (ISO 24778 §8.4) — multi-symbol
  concatenated sets (up to 26 symbols), optional alphanumeric fileID.
- Phase 227: QR convenience factories — `vCard()`, `wifi()`, `url()`,
  `sms()`, `email()`, `geo()` для common use cases.

### PDF conformance
- Phase 225: PDF/X-1a/X-3/X-4 print conformance opt-in. New `PdfXConfig`
  VO + `Pdf\Document::enablePdfX()` + Document constructor `pdfX` param.
  Emits /S /GTS_PDFX OutputIntent + /Trapped key + pdfx: XMP markers.

**Tests:** 1683 → 1966 (+283 new tests across 24-phase batch).

## v1.5.0 — 2026-05-14

Barcode coverage + PDF output improvements. 15 phases (199-213).

### Linear barcodes (11 new formats):
- Phase 199: EAN-13 / UPC-A add-on supplements (EAN-2 + EAN-5)
- Phase 200: EAN-8 short variant
- Phase 201: UPC-E zero-suppressed
- Phase 202: Code 39 alphanumeric с Mod-43 check
- Phase 203: ITF (Interleaved 2 of 5) — ITF-14 GTIN profile
- Phase 204: Codabar (NW-7) — libraries/FedEx/blood banks
- Phase 205: Code 93 — dual Mod-47 check, continuous encoding
- Phase 206: MSI Plessey — retail shelf labeling
- Phase 207: Pharmacode (Laetus) — pharma blister packs
- Phase 209: Code 11 (USS Code 11) — telecom labeling
- Phase 211: QR FNC1 modes 1 (GS1) + 2 (AIM)

### PDF output:
- Phase 208: XRef streams (PDF 1.5) — binary-packed FlateDecode XRef object
  replaces classic xref table. ~50% metadata size reduction.
- Phase 210: `Document::pdfVersion` constructor param — user-facing PDF
  version control (1.4 legacy compat / 2.0 modern features)
- Phase 212: `/ID` trailer entry always emitted — deterministic MD5-derived
  fingerprint для non-encrypted docs
- Phase 213: `/Info` dictionary always emitted с default Producer + CreationDate

**Tests:** 1413 → 1683 (+270). All passing.

## v1.4.1 — Phase 192-198

QR version info BCH(18,6) для V7+, DataMatrix/PDF417 GS1+ECI markers,
DataMatrix Macro 05/06, vmtx vertical metrics parser, PdfFont vertical
writing mode + /W2 array.

## v1.4.0 — Phase 175-198

DataMatrix encoding modes (Base 256 / C40 / Text / X12 / EDIFACT) +
auto-mode heuristic (176-180). PDF417 Text/Numeric compaction (181-182).
QR Structured Append (183) + ECI (184). PDF417 Macro PDF417 (185).
Composite glyph deltas (186). Bidi X1-X10 explicit embedding (187).
LineBreaker tab-stops + hanging punctuation (188-189). PDF/A-1a auto
Tagged enforcement (190). Streaming PKCS#7 signing (191).

## v1.3.0 — Phase 138-174

Indic shaping (137-139, 144), Arabic shaping (135), Bidi L3 mirroring
(148), variable fonts (131-134), 11 chart types с rotation/axis titles,
math expressions (LaTeX subset — fractions/superscripts/radicals/environments).

## v1.0 + v1.1 — Phases 1-69

| Phase | Описание | Tests | Commit |
|-------|----------|-------|--------|
| 1 | PDF skeleton: Document/Page/Writer + StandardFont base-14 | ~30 | — |
| 2a-b | TTF subset embedding (86% size reduction) | — | — |
| 2c | GPOS kerning (pair adjustments через TJ) | — | cec1716 |
| 2d | GSUB ligatures (fi/fl/ffi + multi-cp ToUnicode) | — | afe78a0 |
| 3a | AST elements + style value-objects | — | 76be6f8 |
| 3b | Document AST root + Layout Engine | 13 | 9b991df |
| 3c | DocumentBuilder fluent API | 39 | 55bc204 |
| 4 | Images — AST + Layout integration | 24 | 78484cb |
| 5a | Tables AST + style VO | 14 | 4a89b6b |
| 5b | Layout Engine renderTable | 11 | 89ab80f |
| 5c | TableBuilder + spans + header repeat | 13 | 1901ef1 |
| 6 | Lists — bullet/ordered + nested + 5 formats | 24 | a959f11 |
| 7 | Hyperlinks + bookmarks + named destinations | 13 | 5cb0508 |
| 8a+b | Field resolution + headers/footers | 13 | db2ef73 |
| 8c | Watermarks (rotated text) | 6 | e74a9cf |
| 8d | Outline tree (Bookmarks panel) | 6 | c90f177 |
| 9 | Page setup: mirrored/gutter/custom dims/first-page header | 18 | fc0bdfd |
| 10a | php-pdf path-repo wiring в printable | 4 | 3d8c4c6 |
| 10b | PhpPdfEmitter AST mapper + `?engine` toggle | 11 | b722196 |
| 10c | Font variants (bold/italic) + underline/strike | 14 | 6c37ee6 |
| 10d | CSS inline-style → AST + PhpPdfEmitter | 14 | 53c428c |
| 10e | Text color rendering (`rg`-operator) | 7 + 2 | 2c7d388 |
| 11 | CSS borders в таблицах | 8 | 344ccc4 |
| 12 | border-collapse + double-line render | 5 + 2 | a9efb7d |
| 13 | Custom font registration (FontProvider + Liberation bundle) | 10 + 8 + 2 | 4dc641c + af2efea (fonts) + 4bc1cd9 (printable) |
| 14 | PDF compression (FlateDecode content + font streams) | 5 | ba25389 |
| 15 | Justify alignment (word-spacing distribution) | 6 | 8e45e75 |
| 16 | Inline images (text wrap, baseline alignment) | 6 | a084140 |
| 17 | CSS <style>/classes (CssInliner integration) | +1 | 6921211 (printable) |
| 18 | border-radius (rounded corners) | 4 + 1 | 4f32049 + dcf41a2 (printable) |
| 19 | border-spacing (priority deferred к v1.1) | 3 | 9c76b42 + d5fd4cc (printable) |
| 20 | PDF metadata (/Info dict) | 5 | c6efcb7 |
| 21 | line-height + letter-spacing | 4 | 067c17c + 280aaba (printable) |
| 22 | Hyphenation — DEFERRED к v1.1 | — | — |
| 23 | Paragraph padding/bg — DEFERRED к v1.1 | — | — |
| 24 | MERGEFIELD — BY DESIGN (Blade-level pipeline) | — | — |
| 25 | Paragraph padding + background | 4 | ee907af + fb8580e |
| 26 | Sup/Sub visual sizing | 3 | b3426a5 |
| 27 | Inline letter-spacing через span | +1 | 6209791 (printable) |
| 28 | Border priority "thicker wins" | 3 | f680192 |
| 29 | Image content dedup by hash | 3 | f47c1f9 |
| 30 | Image watermark | 7 | 197cc0b |
| 31 | Watermark opacity через ExtGState | 13 | 5d588b9 |
| 32 | Code 128 barcode primitive | 17 | 8aa8f6c |
| 33 | Soft hyphen (U+00AD / &shy;) wrap hints | 5 | 309f5d8 |
| 34 | Multi-section documents | 8 | b57ddb4 |
| 35 | EAN-13 / UPC-A barcode | 11 | f26dcd3 |
| 36 | QR Code byte-mode ECC L V1-10 | 12 | b6521aa |
| 37 | QR ECC M/Q/H levels (V1-V4) | 9 | 12802e9 |
| 38 | QR Numeric / Alphanumeric encoding modes | 14 | 8e5c680 |
| 39 | Multi-column layout (ColumnSet) | 7 | 5180a42 |
| 40 | Footnotes / endnotes | 6 | a18a70c |
| 41 | PDF encryption V2 R3 RC4-128 | 8 | c3d7743 |
| 42 | AES-128 encryption V4 R4 | 7 | 2d18542 |
| 43 | AcroForm (text + checkbox) | 9 | aac0c20 |
| 44 | BarChart primitive | 8 | 01f22f0 |
| 45 | LineChart + PieChart | 10 | 9251cd0 |
| 46 | AcroForm extensions (multiline/password/combo/list/radio) | 9 | 7862b41 |
| 47 | PDF/A-1b compliance mode | 9 | 25884b1 |
| 48 | Tagged PDF (accessibility minimum) | 5 | a9ddacf |
| 49 | Embedded files / attachments | 8 | 229bcd9 |
| 50 | AES-256 encryption V5 R5 | 9 | 2040bfd |
| 51 | Multi-series charts (GroupedBar + MultiLine) | 9 | 468ebeb |
| 52 | SVG support (basic shapes + simple path) | 13 | 60d362d |
| 53 | SVG path curves (C/S/Q/T + H/V + relative) | 11 | b20a979 |
| 54 | Stacked bar chart | 7 | edb9046 |
| 55 | Donut + Scatter charts | 10 | 71312d9 |
| 56-57 | AcroForm signature placeholder + Code 128 Set C | 7 | b93c6c8 |
| 58 | SVG <text> element | 7 | df9cd69 |
| 59 | SVG transforms (translate/scale/rotate/matrix) | 10 | 2ca5e04 |
| 60 | Area chart (single + stacked) | 8 | 418dea8 |
| 61 | Heading + PDF/UA H1-H6 tagging | 7 | 38b3c3b |
| 62 | Image alt-text для PDF/UA | 5 | 38ccc36 |
| 63 | SVG path arcs (A command) | 7 | bccf3b8 |
| 64 | Chart grid lines (Bar/Line/Area) | 4 | 8f70f07 |
| 65 | Tagged PDF /Table /TR /TD | 6 | ae22478 |
| 66 | Tagged PDF /L /LI | 5 | fdf8117 |
| 67 | AcroForm JavaScript actions | 8 | 5c8e1b4 |
| 68 | Chart custom axis ranges (yMax) | 5 | 68f632b |
| 69 | MathExpression (LaTeX subset) | 11 | f210de4 |

**v1.0 + v1.1 итого:** 448 тестов в php-pdf, 194 в printable, 8 в Liberation package.
