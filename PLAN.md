# Implementation plan — dskripchenko/php-pdf

> Status: **planning / research**. This document captures scope decisions,
> known problem areas, research questions, and a realistic phasing
> proposal. Nothing here is final until validated by POCs.

## Contents

1. [Why a custom library](#why-a-custom-library)
2. [Why the comparison to php-docx is misleading](#why-the-comparison-to-php-docx-is-misleading)
3. [Honest scope assessment](#honest-scope-assessment)
4. [Strategic options](#strategic-options)
5. [Proposed minimal scope (v0.1)](#proposed-minimal-scope-v01)
6. [Architecture sketch](#architecture-sketch)
7. [Research areas (deep dive)](#research-areas-deep-dive)
8. [Phase plan](#phase-plan)
9. [Open questions to resolve before Phase 1](#open-questions-to-resolve-before-phase-1)
10. [References](#references)

---

## Why a custom library

`mpdf/mpdf` is the de-facto PHP HTML→PDF engine but it is licensed
under **GPL-2.0-only** (strong copyleft). For our deployments the
practical constraints are:

- ✅ Internal SaaS / hosted operation — GPL v2 doesn't trigger the
  distribution clause for hosted services
- ❌ On-premise deployment / customer-installable product
- ❌ Embedding in a closed-source SDK / OEM
- ❌ Proprietary licensing of a bundle that includes mpdf

A clean-room MIT-licensed replacement removes these constraints and
puts maintenance ownership on us (same model as
`dskripchenko/php-docx`).

---

## Why the comparison to php-docx is misleading

| | php-docx | php-pdf |
|---|---|---|
| What we emit | OOXML markup (XML) | Final rendered output (positioned text + embedded fonts + image streams) |
| Who does layout & rendering | Word / Pages / LibreOffice | **Us** (the library) |
| Reference spec size | ECMA-376 OOXML, ~5000 pages (but XML schemas are mostly straightforward) | ISO 32000-2 PDF, ~970 pages (binary format, content streams are a graphics PostScript subset, font tables need separate parsing) |
| Mature open-source size | phpword: ~30 kLOC | mpdf: ~150 kLOC |
| What we ship | ~5 kLOC (php-docx 1.0) | realistic minimum ~15-25 kLOC |

**Key implication.** php-docx delegates the hardest work (typography,
layout, rendering, fonts) to downstream consumers. A PDF library has
no such delegation — we ship the typesetting itself. This is roughly
the difference between writing markdown vs. typesetting a book.

---

## Honest scope assessment

Building a full mpdf-replacement is approximately equivalent to
building a partial typesetting / browser engine. It is a multi-year
effort with a long tail of edge-cases (font corner cases, table
break-inside, browser-vendor-specific CSS behaviours, accessibility
tags, PDF/A compliance).

Realistic horizon for a minimal-but-useful v0.1:

- **3-4 weeks** POC + risk-burn-down
- **4-6 months solo** to a production-usable v0.1 covering ~20% of mpdf
  functionality (the subset relevant to printable's print-form use case)
- **multi-year long tail** for parity with mpdf on arbitrary HTML input

This is honest. Saying anything shorter is wishcasting.

---

## Strategic options

We should pick ONE before investing further.

### Option A: Full custom PDF engine

Greenfield clean-room implementation. License: MIT. Estimated effort:
4-6 months to v0.1, multi-year long tail. **Risk: high** — font
handling and table layout have famously many edge cases.

### Option B: dompdf migration

Replace `mpdf/mpdf` with `dompdf/dompdf` (LGPL-2.1). Effort: 1-2 days.
**LGPL allows linking from proprietary code** without making the whole
work copyleft, so this resolves OEM/on-prem distribution. Tradeoff:
dompdf's CSS3 support is worse, watermark/header/footer API is
clunkier, max-PDF-version is 1.4.

### Option C: Sidecar (headless Chrome or LibreOffice)

Our PHP code is a thin MIT wrapper; the actual rendering is done by
`chromium --print-to-pdf` or `soffice --convert-to pdf`. Effort: 1-2
weeks. License-clean (separate-process delegation, no linking).
Quality: Chrome ≈ browser-grade (the best on the market); LO ≈ high.
Cost: ~500 MB binary on the server (Chrome) or ~250 MB (LO).

### Option D: Custom v0.1 of MINIMAL scope, sidecar fallback for everything else

Implement a small custom emitter (~3 kLOC) that handles the most common
print-form patterns; fall back to sidecar Chrome/LO for cases outside
its scope. Effort: 2 months to MVP. Pragmatic but adds operational
complexity.

**Working assumption for this document: Option A.** This plan is built
around the case where we commit to building it ourselves. If we
reconsider during POC and switch to B/C, the work isn't wasted —
sections 6-7 are still relevant for evaluating any PDF library.

---

## Proposed minimal scope (v0.1)

What v0.1 MUST handle (to be a useful mpdf replacement for printable):

| Feature | Notes |
|---|---|
| Paragraphs, headings, line breaks, page breaks | inline styles only |
| Inline runs: bold / italic / underline / strikethrough / sup / sub / color / size / fontFamily | from 14 standard fonts + a small set of bundled TTF |
| Hyperlinks (external + internal anchors) | + bookmarks for TOC |
| Images: PNG, JPEG | data: URLs from caller; ext rasterized upstream |
| Tables: rowSpan/colSpan, borders, padding, cell background, percentage widths | break-across-pages OK; complex break-inside-row in long tail |
| Lists: bullet, decimal, letter, roman, nested ≥ 3 levels | |
| Headers, footers, watermarks | per-page; first-page / even-page later |
| Page numbers (PAGE / NUMPAGES fields) | |
| A4 / A3 / A5 / Letter / Legal, portrait + landscape, custom margins | |
| Embedded Unicode TTF fonts | subset embedding via cmap; ascii-only fast path |

What is explicitly **OUT of v0.1**:

- Complex CSS (flexbox, grid, transforms, gradients, multi-column flow)
- Floats and absolute positioning (limited support only)
- SVG (rasterize upstream — same approach as printable already uses)
- Forms / acroforms
- Encryption / digital signing / PDF/A compliance
- RTL / BiDi (Arabic, Hebrew) — Phase L (long tail)
- Hyphenation
- Justified text (left/right/center only initially)
- Page-break-inside avoidance, orphan/widow control
- Annotations, multimedia, JS actions
- Color management (CMYK / ICC profiles) — sRGB only
- Linearization / streaming write

This subset is informed by what printable actually generates (typed
print forms, mostly tables + paragraphs + images + headers/footers,
all sRGB, Latin/Cyrillic).

---

## Architecture sketch

```
HTML (inline styles)
       │
       ▼  Html\Converter  (REUSE from php-docx — already produces our Document AST)
   Document (AST)
       │
       ▼  Layout\Engine   (NEW — paginate AST into rendered page list)
   list<RenderedPage>     ← each page = list<DrawCommand>
       │                     (text-at-xy, image-at-xy, line, rect, etc.)
       │
       ▼  Pdf\DocumentWriter  (NEW — emit PDF binary)
   PDF bytes
```

Layers:

1. **HTML → AST (reuse from php-docx).** We already have a well-tested
   `Html\Converter` that produces `Document/Section/Paragraph/Run/Table/
   ListNode/Image/Hyperlink/Bookmark/Field`. Same AST powers DOCX
   writer; we add a PDF renderer.

2. **Layout engine (new — the hard part).** Takes the AST + page setup,
   produces a list of `RenderedPage` objects, each containing a flat
   list of low-level draw commands at absolute coordinates. Handles:
   - Text shaping & measurement (per font: glyph widths, kerning)
   - Line breaking & wrapping
   - Paragraph spacing & indentation
   - Table layout: column-width resolution, row-height computation,
     spans, page breaks
   - List numbering
   - Image scaling
   - Headers/footers/watermarks: rendered once per page

3. **PDF emitter (new — mechanical but specific).** Takes draw commands
   and emits valid PDF bytes. Handles:
   - PDF object tree (catalog, page tree, content streams)
   - Cross-reference table & trailer
   - Font dictionary + embedded TTF subset
   - Image XObject streams (flate-encoded for PNG, DCT for JPEG)
   - Hyperlinks (annotation dictionaries)
   - Bookmarks (outline dictionary)

The AST reuse is the single biggest scope win — the whole HTML parsing
and inline-styles cascade is "free" from php-docx.

---

## Research areas (deep dive)

The following are areas where we have **unknown unknowns** until we
do POCs. Each item below needs research before phase-locking.

### R1. PDF format choice & target version

- PDF 1.4 (Adobe Reader 5+, 2001) — minimum baseline, no transparency
  groups, no embedded color profiles
- PDF 1.7 / ISO 32000-1 (2008) — current de-facto standard, full
  transparency, encryption, AES
- PDF 2.0 / ISO 32000-2 (2017+) — improved compression, accessibility
  tags

**Question:** Target 1.7 (most balanced)? Or 1.4 for max compatibility?
mpdf defaults to 1.4.

**Research output:** ADR-PDF-002.

### R2. Font handling (THE HARDEST PROBLEM)

This is the single largest risk area. Sub-questions:

#### R2a. Font sourcing
- 14 standard PDF base-14 fonts (Helvetica, Times, Courier, Symbol,
  ZapfDingbats × variants) — guaranteed present in viewers but ONLY
  cover Latin-1 (no Cyrillic!)
- For Cyrillic / Greek / accented Latin we MUST embed TTF
- License of bundled TTF — needs to be OFL or Apache (DejaVu? Liberation?
  Noto?). Critical for MIT distribution.

#### R2b. TTF parsing
- Tables to read: `head`, `hhea`, `hmtx`, `cmap`, `name`, `OS/2`, `post`,
  `glyf`, `loca`, `maxp`, optional `kern`/`GPOS`/`GSUB`
- Subset generation: identify glyphs used, write minimal `glyf`/`loca`
  with renumbered glyph IDs
- CMap construction: cid-to-gid (PDF needs character-code → glyph
  mapping) + ToUnicode (for copy-paste from PDF reader)

#### R2c. Embedding strategies
- Fully-embedded (~500 KB per font, fast)
- Subset-embedded (~5-50 KB depending on content, our target)
- Reference-only (font assumed installed on viewer — DON'T do this,
  unreliable)

#### R2d. Out-of-scope text features (for v0.1)
- Ligatures (fi, fl)
- Kerning pairs (mostly via GPOS, we'd lose this in v0.1)
- BiDi / RTL
- Complex shaping (Arabic, Indic, CJK with sub-positioning)

**Estimated effort:** 4-6 weeks just for fonts. Hardest single area.

**Research outputs:**
- POC-R2.a: emit a 1-page PDF with embedded DejaVu Sans Regular subset
  (5 glyphs)
- POC-R2.b: same with Cyrillic glyphs (а-я + accents)
- POC-R2.c: measure copy-paste correctness in Acrobat / preview /
  evince / Foxit

### R3. Layout engine — text wrapping & line breaking

- Greedy algorithm: O(n), produces ragged-right (good for v0.1)
- Knuth-Plass: O(n²), produces beautiful justified text (out of v0.1)
- Hyphenation: pyphen / TeX-style — out of v0.1
- Soft-hyphens (U+00AD) — straightforward to honor

**Research output:** POC-R3.a — wrap a 500-char paragraph in 4
different fonts; compare visual output to mpdf reference.

### R4. Layout engine — tables

This is the second-hardest area after fonts. Sub-questions:

- Column-width resolution: explicit → percentage → auto (content-based)
- Row-height: line-count × line-height, plus padding, plus border
- vMerge/rowSpan with page breaks: if a merged cell crosses a page
  boundary, how do we render the continuation row? mpdf and Word
  disagree here. CSS spec (CSS-tables) is ambiguous.
- Cell padding + border: collapsing vs separate border models?
- Header repetition on subsequent pages (mpdf `repeat` feature)

**Research output:** POC-R4.a — render the polis-vzr-semya template's
3-column header table (the one we know breaks in nested tables in
Pages) — does our layout work?

### R5. Page-break handling

- Forced page break (`<page-break/>`, `<hr class="page-break">`)
- Soft page break (content overflow)
- Page-break-inside avoidance — out of v0.1
- Orphans / widows — out of v0.1
- Keep-with-next (heading should stay with following paragraph) —
  out of v0.1

**Research output:** ADR-PDF-003 — page-break policy.

### R6. Image handling

- PNG decoding: read width/height from IHDR, decompress IDAT via zlib,
  re-encode as Flate-compressed PDF image XObject
- JPEG: pass through as DCT-encoded XObject (no re-encoding!)
- Transparency: PNG alpha → PDF SMask (separate grayscale image)
- ICC profile: strip or pass through?

**Research output:** POC-R6.a — embed a PNG with alpha and a JPEG;
verify rendering in Acrobat + preview.

### R7. Headers, footers, watermarks

- mpdf model: HTML fragment set via `SetHTMLHeader()`, rendered once
  per page at top
- Our approach: render header/footer as a separate `RenderedPage`
  fragment, blit onto each page
- Watermark: VML-rotated text shape (DOCX style) or simple text with
  rotation matrix in content stream? Latter is more PDF-native.

**Research output:** ADR-PDF-004 — header/footer/watermark rendering
strategy.

### R8. Hyperlinks & bookmarks

- External link: Annotation Dictionary with URI Action
- Internal link: Annotation Dictionary with GoTo Action pointing to
  named destination
- Bookmark (outline): top-level dict referencing destinations
- Tab order, accessibility tags — out of v0.1

**Estimated effort:** 1 week.

### R9. PDF content streams

- PDF content streams are a graphics PostScript subset:
  - `BT/ET` — begin/end text object
  - `Tf` — set font + size
  - `Td/TD/Tm` — text position
  - `Tj/TJ` — show text
  - `m/l/c/h/S/f/B` — path operators (for lines, rectangles, etc.)
  - `q/Q` — push/pop graphics state
  - `cm` — set CTM (transform)
  - `Do` — invoke XObject (image)
- Stream content is **compressed** (Flate) and **indirect**-referenced
  from page object

**Research output:** POC-R9.a — emit a 1-page PDF with formatted text +
1 rectangle + 1 line. ~200 LOC target.

### R10. Performance / memory

- Large documents: 100+ pages with images — mpdf can OOM
- Streaming write — emit pages as they're rendered, don't keep all in
  memory
- Cross-reference table needs to be at end (we can write it after
  streaming pages once we know the offsets)

**Estimated effort:** Phase L (long tail).

---

## Phase plan

### Phase R0: Risk burn-down POCs (3-4 weeks)

Build minimal PoCs to validate the hardest unknowns BEFORE committing
to the full implementation. Pass-criteria for each is binary —
"can we, in principle, do this?" If R2 (fonts) doesn't pass, we
reconsider strategic option.

- [ ] POC-R9.a — emit "Hello world" PDF, 1 page, Times-Roman from
      Adobe core 14
- [ ] POC-R2.a — embed a subsetted DejaVu Sans (5 glyphs); open in
      Acrobat
- [ ] POC-R2.b — embed Cyrillic subset (а-я)
- [ ] POC-R2.c — verify copy-paste correctness in 3 viewers (preview,
      Acrobat, evince/Foxit)
- [ ] POC-R6.a — embed a PNG + JPEG; verify rendering
- [ ] POC-R3.a — wrap a 500-char paragraph in 2 fonts at 2 sizes
- [ ] POC-R8.a — emit hyperlinks (external + internal anchor)
- [ ] POC-R5.a — single forced page break + soft page break
      (content overflow)
- [ ] POC-R4.a — render polis-vzr-semya 3-column header table

**Acceptance:** all POCs pass and we write ADR-PDF-001 with go/no-go
decision.

### Phase 1: PDF skeleton + standard fonts (2-3 weeks)

After R0 success. Output: minimum-viable text-only PDFs.

- `Pdf\Document` / `Pdf\Page` / `Pdf\Stream` value-objects
- Catalog / page tree / cross-reference table emission
- Adobe core 14 fonts as referenced (no embedding)
- Plain text rendering with absolute positioning
- One paragraph per page, fixed layout

**Acceptance:** can emit a 1-page "lorem ipsum" PDF in Times-Roman 12pt.

### Phase 2: TTF subset embedding (4-6 weeks)

The single hardest phase. Output: arbitrary Unicode characters
rendered correctly.

- TTF table parser
- Subset generation
- CMap (cid-to-gid) + ToUnicode CMap emission
- Font dictionary with Type0 composite font + CIDFontType2 descendant
- Bundled fonts: DejaVu Sans + Serif + Mono (OFL license)

**Acceptance:** can render arbitrary Cyrillic and accented Latin text
with copy-paste-correctness in 3 viewers.

### Phase 3: Layout engine — paragraphs (3 weeks)

Output: HTML→PDF for the simplest case (paragraphs + headings + line
breaks).

- AST walker → layout engine
- Text shaping & measurement
- Line breaking (greedy)
- Paragraph spacing + alignment (left/right/center; no justify yet)
- Page overflow

**Acceptance:** render a 5-page-long Wikipedia article from HTML.

### Phase 4: Images (2 weeks)

- PNG decoding & re-encoding as Flate XObject
- PNG with alpha → SMask
- JPEG pass-through
- Image positioning & scaling

**Acceptance:** render a doc with 5 mixed-format images.

### Phase 5: Tables (4-6 weeks)

- Column width resolution
- Row height computation
- gridSpan / rowSpan
- Cell padding, borders, background
- Table break across pages (no break-inside-row in v0.1)

**Acceptance:** render the polis-vzr-semya template equivalently to
mpdf (visual diff ≤ 5%).

### Phase 6: Lists (1 week)

- Bullet / numbered with arbitrary nesting
- Numbering formats (decimal / letter / roman)
- ListFormat enum from php-docx already

**Acceptance:** render nested mixed lists, 3 levels deep.

### Phase 7: Hyperlinks + bookmarks (1 week)

- External link annotations
- Internal anchor + destination
- Outline tree (bookmark side panel in PDF readers)

### Phase 8: Headers / footers / watermarks (2 weeks)

- Per-page rendering
- Field codes (PAGE / NUMPAGES) substitution
- Watermark with rotation + opacity

### Phase 9: Page setup & advanced (2 weeks)

- A4 / A3 / A5 / Letter / Legal
- Portrait + Landscape
- Custom margins
- First-page / even-page header variants (if not done in Phase 8)

### Phase 10: Integration with printable (2 weeks)

- Replace `App\Render\Emitter\PdfEmitter` to use `dskripchenko/php-pdf`
- A/B switch via `config/admin.php` `pdf.driver` (mpdf | php-pdf)
- Reference corpus: render all printable templates with both engines,
  visual-diff threshold ≤ 5% pixel
- Behind feature flag until parity confirmed

### Phase L: Long tail (indefinite)

- Justified text
- Hyphenation
- Page-break-inside avoidance + orphan/widow control
- More complex tables (break-inside-row, etc.)
- CMYK & ICC profile support
- RTL / BiDi
- Acroforms (only if real demand)
- Digital signing (only if real demand)
- PDF/A compliance (only if real demand)

---

## Open questions to resolve before Phase 1

1. **Strategic option confirmation.** Confirm Option A (build it) vs
   B (dompdf) vs C (sidecar). If A, this plan applies.
2. **Bundled font selection.** DejaVu? Liberation? Noto Sans? License
   must allow MIT redistribution (OFL ≥ 1.1 OK, Liberation OK, Noto OK).
3. **PDF target version.** 1.4 or 1.7?
4. **Glyph layout fidelity floor.** Are we OK without kerning for v0.1?
   Without ligatures? mpdf has these for free via FPDF lineage.
5. **Reuse Html\Converter from php-docx?** Yes-likely. Means we depend
   on dskripchenko/php-docx OR we extract Converter into a third
   shared package (`dskripchenko/php-doc-ast`?).
6. **Color management.** sRGB-only for v0.1 (skip ICC)?
7. **Maintenance budget.** Solo or with help? Solo means everything in
   this plan stretches 1.5-2×.

---

## References

Specification & reverse-engineering material to read:

- **ISO 32000-2** (PDF 2.0) — the spec. 970 pages. Available free from
  ISO.
- **Adobe PDF Reference 1.7** — older but widely-available PDF of PDF
  1.7 spec.
- **TrueType spec** (Apple TT 1.66, OpenType OTSpec 1.9) — for font
  tables.
- **mpdf source code** — for reading-only, never copying. License
  blocks code reuse.
- **PDFBox** (Apache 2.0) — Java PDF library; license-compatible for
  reading reference behavior.
- **TCPDF** (LGPL-3) — read-only reference; can't copy code.
- **iText 7 community** (AGPL) — DO NOT READ to avoid license
  contamination.

Clean-room implementation note: developers reading mpdf or iText for
**reference** should NOT then write code that resembles those projects.
Implement from specs only. If we want to be conservative, restrict to
spec-reading and PDFBox (Apache).
