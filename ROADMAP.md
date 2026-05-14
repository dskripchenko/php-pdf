# dskripchenko/php-pdf — Roadmap

Pure-PHP, MIT-licensed PDF renderer. Цель — drop-in замена `mpdf/mpdf`
(GPL-2.0) в production-стеке printable-приложения с feature parity на
типичных бизнес-документах (договоры, акты, счета, отчёты).

**Текущий статус:** v1.1-dev — 135 фаз закрыты (1217 + 194 printable = 1411 тестов).
v1.0 production-ready closed (Phase 1-21 + 24 by-design + 22/23 deferred).
v1.1 в активной разработке.

**v1.0 final:** Production-ready для типичных бизнес-документов. Critical
блокеры (13-17) закрыты, Important (18-21) закрыты.
mpdf остаётся production-default; php-pdf opt-in через `?engine=php-pdf`.

**v1.1 progress:** 25-137 closed (113 фаз):
 - 25 paragraph padding+bg, 26 sup/sub sizing, 27 inline letter-spacing,
 - 28 border priority, 29 image content dedup, 30 image watermark,
 - 31 watermark opacity (ExtGState), 32 Code 128 barcode,
 - 33 soft hyphen, 34 multi-section docs,
 - 35 EAN-13/UPC-A barcode, 36 QR Code byte-mode V1-10,
 - 37 QR ECC M/Q/H levels, 38 QR Numeric/Alphanumeric encoding modes,
 - 39 multi-column layout, 40 footnotes/endnotes,
 - 41 PDF encryption V2 R3 RC4-128, 42 AES-128 encryption V4 R4,
 - 43 AcroForm (text + checkbox), 44 BarChart, 45 LineChart + PieChart,
 - 46 AcroForm extensions (multiline, password, combo, list, radio),
 - 47 PDF/A-1b compliance, 48 Tagged PDF (accessibility minimum),
 - 49 embedded files / attachments,
 - 50 AES-256 encryption V5 R5, 51 multi-series charts (grouped bar + multi-line),
 - 52 SVG support (basic shapes + simple path),
 - 53 SVG path curves (C/S/Q/T + H/V + relative),
 - 54 stacked bar chart, 55 donut + scatter charts,
 - 56 AcroForm signature field placeholder, 57 Code 128 Set C,
 - 58 SVG <text> element, 59 SVG transforms (translate/scale/rotate/matrix),
 - 60 Area chart (single + stacked),
 - 61 Heading element + PDF/UA H1-H6 tagging,
 - 62 Image alt-text для PDF/UA accessibility,
 - 63 SVG path arcs (A command), 64 chart grid lines,
 - 65 Tagged PDF /Table /TR /TD, 66 Tagged PDF /L /LI,
 - 67 AcroForm JavaScript actions, 68 chart custom axis ranges (yMax),
 - 69 MathExpression (LaTeX-like sup/sub/frac/sqrt + Greek),
 - 70 chart axis titles, 71 chart grid lines (5 more charts),
 - 72 Tagged PDF /Link, 73 SVG <style> CSS, 74 SVG <defs>/<use>,
 - 75 Math matrices (matrix/pmatrix/bmatrix/vmatrix),
 - 76 font fallback chain, 77 string encryption,
 - 78 Code 128 Set A, 79 line-height absolute precision,
 - 80 Math big operators с limits, 81 SVG opacity attributes,
 - 82 SVG linearGradient (PDF Pattern/Shading),
 - 83 AcroForm submit/reset/push buttons,
 - 84 /OpenAction + /PageMode + /PageLayout,
 - 85 page transitions + auto-advance,
 - 86 PDF/UA /Artifact для headers/footers/watermarks,
 - 87 page labels (Roman/decimal/alpha numbering),
 - 88 /ViewerPreferences dictionary,
 - 89 Document /Lang language hint (PDF/UA requirement),
 - 90 SVG multi-stop gradients (Type 3 stitching),
 - 91 SVG radialGradient,
 - 92 PDF/UA /StructParents tree (reading order),
 - 93 PDF/UA /RoleMap custom role aliases,
 - 94 Page rotation /Rotate,
 - 95 SVG gradientTransform attribute,
 - 96 Math multi-line equations,
 - 97 AcroForm /CO calculation order,
 - 98 Smoothed line chart (Catmull-Rom splines),
 - 99 AcroForm /DA default appearance,
 - 100 bookmark colors + bold/italic,
 - 101 QR Kanji encoding mode,
 - 102 drawImage с rotation,
 - 103 PDF/A-2u / PDF/A-3 support,
 - 104 DataMatrix barcode (ECC 200, sizes 10×10..26×26),
 - 105 QR auto best-mask selection (8 patterns + penalty scoring),
 - 106 AES-256 V5 R6 (PDF 2.0) iterative hash 2.B,
 - 107 Form XObject reusable content streams,
 - 108 PKCS#7 detached AcroForm signing,
 - 109 markup annotations (Text/Highlight/Underline/StrikeOut/FreeText),
 - 110 page boxes (CropBox/BleedBox/TrimBox/ArtBox),
 - 111 Tiling Pattern Type 1 (repeating fills),
 - 112 Optional Content Groups (layers + /OCProperties),
 - 113 named/JavaScript/launch link actions,
 - 114 line dash pattern + caps + joins + miter limit,
 - 115 page /AA Open/Close JavaScript actions,
 - 116 rectangular + polygon clipping paths,
 - 117 DeviceCMYK color operators (k / K),
 - 118 text rendering modes (Tr 0..7),
 - 119 document-level /AA actions (WC/WS/DS/WP/DP),
 - 120 Square/Circle/Line annotation shapes,
 - 121 Stamp + Polygon + PolyLine annotations,
 - 122 Ink annotation (freehand drawing),
 - 123 AcroForm AppearanceStream /AP /N rendering,
 - 124 PDF417 stacked linear 2D barcode (byte+multi-byte compaction,
   RS GF(929) ECC 0..8, all 929×3=2787 ISO codeword patterns),
 - 125 Aztec Compact 2D barcode (1-4 layers, 15×15..27×27,
   ZXing-verified),
 - 126 Aztec Full mode (5-32 layers, up to 151×151, alignment grid,
   7-ring bullseye, GF(1024)/(4096), ZXing-verified L9/L13/L26),
 - 127 DataMatrix full ECC 200 sizes (29 sizes incl. multi-region 32×32+,
   rectangular 8×18..16×48, interleaved RS blocks, ZXing-verified),
 - 128 vertical text (CJK character stacking, showTextVertical +
   showEmbeddedTextVertical APIs),
 - 129 streaming PDF output (Writer::toStream + Document::toStream
   + Document::toFile рефакторен к streaming),
 - 130 lazy font subset (per-Writer registration cache + reset() API,
   fixes multi-Document PdfFont reuse bug),
 - 131 variable fonts: fvar discovery (axes + named instances),
 - 132 variable fonts: avar + HVAR + MVAR metric interpolation
   (Item Variation Store, per-glyph advance, font-level metrics),
 - 133 variable fonts: gvar glyph shape delta computation
   (tuple variation headers, packed point numbers, packed deltas),
 - 134 variable fonts: PDF integration — frozen subset
   (SimpleGlyph parser/serializer + IUP + axes param на PdfFont),
 - 135 Arabic basic shaping (joining classes + Pres Forms B mapping +
   RTL reversal + lam-alef ligature, integrated в PdfFont),
 - 136 Unicode Bidi Algorithm UAX 9 (implicit levels — W/N/I rules +
   L2 reordering, integrated в PdfFont для mixed LTR/RTL text),
 - 137 Indic basic shaping (pre-base matra reorder для Devanagari,
   Bengali, Tamil, Telugu, Kannada, Malayalam, Gujarati, Gurmukhi,
   Oriya, Sinhala — handles conjuncts через halant traversal),
 - 138 Reph reorder (RA + virama at syllable start moved к end of
   syllable per OpenType USE — codepoint-level reordering для 11
   Indic scripts; GSUB rphf application defers к font),
 - 139 Two-part Indic matras (Unicode NFD decomposition: Bengali ো,
   Tamil ொ/ோ/ௌ, Malayalam ൊ/ോ/ൌ, Oriya ୋ/ୌ/ୈ, Kannada ೊ/ೋ three-part,
   Sinhala ො/ෞ — 18 entries across 6 scripts),
 - 140 X-axis label rotation для BarChart (matplotlib-style angle
   parameter; end-anchor positioning via shared chartTextRotated helper
   reusable для других charts в будущих фазах),
 - 141 X-axis label rotation на 5 charts (LineChart/AreaChart/MultiLine/
   GroupedBar/StackedBar),
 - 142 Axis titles на 4 charts (GroupedBar/StackedBar/MultiLine/Scatter),
 - 143 GSUB Type 1 Single Substitution support (SingleSubstitutions data
   class + GsubReader::readByFeature(tag) API + Format 1 delta-based +
   Format 2 explicit-array; foundation для rphf/half/init/medi/fina),
 - 144 rphf substitution application в PdfFont (post-Indic-shaping
   reph detection + TtfFile::singleSubstitutionsForFeature('rphf') +
   per-position GSUB Type 1 apply; finalizes Indic reph visual support
   for fonts shipping 'rphf' as single-sub lookup),
 - 148 Bidi X9 filter (drop LRE/RLE/LRO/RLO/PDF/LRI/RLI/FSI/PDI) +
   L3 mirroring (22 ASCII/Unicode bracket pairs swapped в RTL spans),
 - 149 Composite glyph variation behavior clarified (transitive
   inheritance через transformed simple components; per-component
   anchor deltas deferred — rare в variable fonts),
 - 151 Tagged PDF /Link /StructParent wiring (Link annotations теперь
   resolve back к /Link StructElem через ParentTree number tree —
   completes PDF/UA Link-role roundtrip),
 - 153 Cross-row border "thicker wins" priority в table collapse mode
   (renderRow tracks prevRowBottomByCol ref-param; top edge of current
   row compares с stored bottom from prior row through moreProminent;
   per-cell columnSpan propagation. Resets на page-break header repeat),
 - 154 LRU cache в PdfFont::decodeUtf8 (Phase 144 reph + Phase 137 Indic
   shaping pipeline переизмерялись на каждом widthPt call → OOM),
 - 155 forcePageBreak guard для header/footer rendering (re-entrance
   detection через LayoutContext::inHeaderFooterRender flag),
 - 156 Adaptive header/footer zones — push body topY вниз если header
   overflowит default margins.topPt (mpdf-style auto-margin),
 - 157 Watermark post-pass — рендер ПОВЕРХ body content через
   sectionPageRanges tracking + per-page late draw (mpdf-style stamp),
 - 158 TJ-array grouping — batch consecutive runs в один showText
   (-5.4% output, -3.2% wall time на bench template 13),
 - 160 ContentStream gstate dedup — drop q/Q wrap + skip duplicate `rg`
   ops (-2.9% uncompressed; ~0% compressed, Flate covers),
 - 164 Code 128 auto-mode switching A/B/C — runs split + CODE_X
   transitions (mixed-content compress: -16..25% modules),
 - 165 GS1-128 Code128Encoder::gs1() factory + FNC1 + AI parsing,
 - 166 PieChart cubic Bezier arcs вместо polygon (sub-arcs ≤90°, k=4/3·tan(θ/4)·r),
 - 167 PieChart exploded slices — radial offset per slice через
   slices[].explode = bool|float,
 - 168 PieChart perimeter labels с leader lines (showPerimeterLabels +
   minLabelAngleDeg).

---

## Конвенция

- **v1.0** — формальная цель: php-pdf можно поставить production-default
  в printable вместо mpdf без визуальной деградации на корпусе
  существующих шаблонов. Список ниже — все известные на момент создания
  roadmap'а gaps, обнаруженные во время phases 1-12.
- **v1.1** — bucket для gaps, обнаруженных уже во время работы над v1.0
  (или после неё). Сейчас содержит nice-to-have / extension features,
  которые не блокируют production switch, но полезны.
- **Phase L** упоминалось в коде как "Phase Layout edge cases" — generic
  bucket для дорогих layout improvements. Здесь распакован в конкретные
  пункты.

Каждый новый gap, обнаруженный по ходу разработки v1.0, фиксируется
сюда (в v1.1 если он не критичен для production switch, в v1.0 если
критичен и обнаружен поздно).

---

## v1.0 — Production-ready blockers

### Critical (блокирует mpdf → php-pdf default switch)

**Phase 13: Custom font registration** ✅ DONE
- ~~PhpPdfEmitter loaded Liberation Sans (4 variant'а) напрямую из
  `vendor/dskripchenko/php-pdf/.cache/fonts/...` — dev/test path.~~
- ~~FontProvider interface + бандлинг TTF + integrated resolver.~~
- Done (4dc641c + af2efea + 4bc1cd9): PdfFontResolver wraps existing
  FontProvider с naming-convention variant chain (BoldItalic → Bold →
  Italic → Regular → bare). Engine accepts optional FontProvider, routes
  RunStyle.fontFamily через resolver. Liberation package теперь
  реально bundle'ит 12 TTF (Sans/Serif/Mono × 4 variants) и implements
  FontProvider с Microsoft metric aliases (Arial → LiberationSans).
- Tests: 10 в php-pdf, 8 в liberation package, 2 в printable.

**Phase 14: PDF compression** ✅ DONE
- ~~Content streams raw → файлы 2-4× больше mpdf.~~
- ~~FlateDecode для content + font streams; opt-out flag.~~
- Done (ba25389): Pdf\Document + Engine + PdfFont все принимают
  `compressStreams: bool = true`. Content streams и font subset
  FontFile2 streams сжимаются gzcompress(level 6) с `/Filter /FlateDecode`.
- Real-world: 199KB → 93KB (47% reduction) на тестовом договоре;
  vs mpdf 51KB → ratio 1.82× (target ≤1.5× близко).
- Tests: 5 в CompressionTest; bulk-update 17 test files под opt-out.

**Phase 15: Justify alignment** ✅ DONE
- ~~text-align: justify деградировал к Start.~~
- Done (8e45e75): emitLine принимает $isLastLine. Только overflow-driven
  line emits с isLastLine=false → justify-stretch применяется. Br/
  pagebreak/paragraph-end остаются flush-left (CSS spec).
- 60% fill-ratio threshold — короткие lines не stretch'аются нелепо.
- Tests: 6 в JustifyAlignmentTest.

**Phase 16: Inline images (text wrap)** ✅ DONE
- ~~Image AST всегда block-level.~~
- Done (a084140): Image implements BlockElement AND InlineElement.
  Engine routing: top-level → block (Phase 4 behavior), внутри paragraph
  → inline atom. tokenizeChildren создаёт 'image' atom; emitLine рендерит
  drawImage(x, baselineY, w, h) с baseline-aligned image bottom; line-
  height = max(text, image+2pt buffer). Hyperlink-wrapped image → clickable.
- Tests: 6 в InlineImageTest.

**Phase 17: CSS `<style>` блоки + классы** ✅ DONE
- ~~HtmlParser парсил только inline style; <style> и class игнорировались.~~
- Done (printable 6921211): PhpPdfEmitter.maybeInlineCss проверяет
  bodyHtml/headerHtml/footerHtml на наличие <style>; применяет CssInliner
  (TijsVerkoyen, уже used by DocxEmitter) + re-parse через HtmlParser.
  Fast-path skip если <style> отсутствует.
- PhpPdfEmitter теперь принимает compressStreams ctor flag (default true,
  tests opt-out для raw-stream inspection).
- Tests: 1 в PhpPdfEmitterCssTest (`Phase 17: <style> блоки применяются
  через CssInliner`).

### Important (feature parity, не строгий блокер)

**Phase 18: border-radius (rounded corners)** ✅ DONE
- Done (4f32049 + dcf41a2): CellStyle.cornerRadiusPt; ContentStream/Page
  имеют fill/strokeRoundedRectangle через cubic Bezier (kappa 0.5523).
  Engine uses rounded path для uniform borders + non-zero radius;
  non-uniform borders или collapse mode → square fallback.
- Tests: 4 в BorderRadiusTest, 1 в printable.

**Phase 19: border-spacing** ✅ DONE (priority deferred к v1.1)
- Done (9c76b42 + d5fd4cc): TableStyle.borderSpacingPt; Engine в
  separate mode shrink'ет каждый cell на spacing/2 с каждой стороны.
  CSS border-spacing parses через extractBorderSpacing (first value).
- **Border priority resolution** ("thicker wins" CSS spec) deferred к
  v1.1: текущий first-drawn-wins работает для типичных случаев.
- Tests: 3 в BorderSpacingTest.

**Phase 20: PDF metadata (/Info dict)** ✅ DONE
- Done (c6efcb7): Writer.setInfo + Trailer reference. Pdf\Document.
  metadata(title, author, subject, keywords, creator, producer,
  creationDate?) — все optional, chainable, auto Producer + CreationDate.
  AST Document.metadata array→propagates. PDF date format D:YYYYMMDDHHmmSS+TZ.
- Tests: 5 в MetadataTest.

**Phase 21: line-height + letter-spacing** ✅ DONE
- Done (067c17c + 280aaba): RunStyle.letterSpacingPt → Tc operator
  через ContentStream/Page/Engine. CSS line-height: multiplier (1.5),
  percent (150%), absolute (18pt → /11 approximation) → ParagraphStyle.
  lineHeightMult. CSS letter-spacing → RunStyle.letterSpacingPt.
- Inline `<span style="letter-spacing">` deferred к v1.1.
- Tests: 4 в LetterSpacingTest.

**Phase 22: Hyphenation + word-break** ⏸ DEFERRED → v1.1
- Длинные слова без spaces overflow за right margin; greedy line-break
  не разбивает один word.
- Реализация требует dictionary-based hyphenation (TeX patterns или
  PHP-NLP-Toolkit), что добавляет heavy dependency и сложность.
- Для production текущий path рабочий: word-break-all для не-Latin (CJK)
  не нужен в типичных бизнес-документах. Soft-hyphen `&shy;` support —
  ~30 минут работы, но без dictionary-driven auto-hyphenation impact
  ограничен.
- Решение: переносим в v1.1. Workaround в шаблонах: `<wbr>` или ручные
  `&shy;`.

**Phase 23: Margin/padding precision** ⏸ DEFERRED → v1.1
- ParagraphStyle уже имеет: spaceBefore/After (margin-top/bottom),
  indentLeft/Right (margin-left/right), indentFirstLine. Это покрывает
  margin полностью.
- НЕ покрыто: padding × 4 sides на Paragraph + background-color на
  Paragraph (только TableCell сейчас имеет padding+bg).
- Решение: текущие margin уже хватают для типичных бизнес-документов;
  paragraph background — крайне редко используется (highlight через
  Mark::background работает для inline). Переносим в v1.1.

**Phase 24: MERGEFIELD value resolution** ✅ BY DESIGN
- Field::mergeField рендерит format-параметр (field name) как placeholder.
- Реальная замена значений делается printable's render pipeline
  (`buildRenderData` substitutions `{{ var }}` через Blade до парсинга).
- Decision: оставлено как есть — production-pipeline уже работает
  корректно. Если custom emitter хочет runtime mail-merge — можно
  pre-substitute в Run.text до построения AST.

---

## v1.1 — Nice-to-have / extension

(Заполняется по мере discovery во время работы над v1.0.)

### Layout

- ~~Multi-section docs (разная orientation/margins per section)~~ ✅ **Phase 34 closed** (b57ddb4).
- ~~Section breaks (явная смена pageSetup mid-document)~~ ✅ **Phase 34 closed** (b57ddb4).
- ~~Footnotes / endnotes~~ ✅ **Phase 40 closed** (a18a70c).
- ~~Multi-column layout (CSS `column-count`)~~ ✅ **Phase 39 closed** (5180a42).
- ~~Border priority resolution в collapse mode (within-row)~~ ✅ **Phase 28 closed** (f680192).
- ~~Border priority cross-row (thicker bottom/top wins on shared edge)~~ ✅ **Phase 153 closed**.
  renderRow теперь принимает `&$prevRowBottomByCol` ref-param с per-column
  bottom borders предыдущей row; top edge для current row сравнивает их
  с current top через moreProminent(). Reset при page-break (header repeat).
- ~~Hyphenation (soft-hyphen `&shy;`)~~ ✅ **Phase 33 closed** (309f5d8).
- ~~Paragraph padding + background~~ ✅ **Phase 25 closed** (ee907af + fb8580e).
- ~~Inline letter-spacing через `<span>`~~ ✅ **Phase 27 closed** (6209791).
- Complex script shaping:
  - ~~Arabic basic shaping~~ ✅ **Phase 135 closed** (189cd78).
    Joining classes + Pres Forms B + lam-alef ligature.
  - ~~Unicode Bidi Algorithm~~ ✅ **Phase 136 closed** (c02b27b).
    UAX 9 implicit levels (W/N/I rules + L2). Mixed LTR/RTL paragraphs.
  - ~~Bidi X9 filter + L3 mirroring~~ ✅ **Phase 148 closed**.
    Drop LRE/RLE/LRO/RLO/PDF + LRI/RLI/FSI/PDI formatting chars (X9).
    L3 mirroring 22 ASCII+Unicode bracket pairs в RTL runs.
    X1-X8 stack-based explicit embeddings deferred (rare в plain text).
  - ~~Indic combining marks (pre-base matra reorder)~~ ✅ **Phase 137 closed** (f4778b5).
    Devanagari/Bengali/Tamil/Telugu/Kannada/Malayalam/Gujarati/Gurmukhi/
    Oriya/Sinhala. Two-part matras + conjunct GSUB substitution deferred.
  - ~~Reph reorder (RA + virama at syllable start)~~ ✅ **Phase 138 closed**.
    Codepoint-level reorder per OpenType USE intermediate state: trailing
    RA + virama moved к end of syllable (after base + matras + conjuncts).
  - ~~GSUB 'rphf' application~~ ✅ **Phase 144 closed** (depends on Phase 143).
    После reph reorder, RA glyph substituted с reph glyph via GSUB Type 1
    Single Substitution. GPOS mark positioning (reph как attached mark above
    base) deferred — fonts с simple Type 1 rphf place reph visually корректно
    через width=0 glyph; fonts с Type 6 chained context defer полностью.
  - ~~Two-part matras decomposition~~ ✅ **Phase 139 closed**.
    Unicode NFD-style decomposition для Bengali ো/ৌ, Tamil ொ/ோ/ௌ,
    Malayalam ൊ/ോ/ൌ, Oriya ୋ/ୌ/ୈ, Kannada ೊ/ೋ (three-part), Sinhala ො/ෞ.
    18 entries across 6 scripts. Sinhala matras containing virama (U+0DDA,
    U+0DDD) deferred — virama в middle of decomp confuses syllable-end
    detection. Bonus: corrected Sinhala consonant range к 0x0D9A-0x0DC6
    (was 0x0DA0-0x0DC6, missing first 6 consonants incl. ක).
- ~~Line-height absolute (`line-height: 18pt`)~~ ✅ **Phase 79 closed** (9a33ed7).

### Typography

- ~~Subscript/superscript visual sizing~~ ✅ **Phase 26 closed** (b3426a5).
- ~~Font fallback chain~~ ✅ **Phase 76 closed** (0834e38).
- ~~Variable fonts (OpenType variations)~~ ✅ **Phase 131-134 closed** (4 phases).
  fvar discovery + avar/HVAR/MVAR metric interp + gvar glyph deltas + IUP
  + frozen subset embedding via `new PdfFont(\$ttf, axes: ['wght' => 700])`.
- ~~Composite glyph behavior clarification~~ ✅ **Phase 149 closed** (no code).
  Composites inherit transformed component outlines transitively (because
  referenced simple glyphs ARE individually transformed). Only per-component
  anchor offset (dx/dy) gvar deltas are unhandled — rare в variable fonts.
  Full composite re-serialization deferred — documented limitation.
- CFF2 variable fonts — moved к **v1.3 backlog**.
- ~~Vertical text (Asian scripts)~~ ✅ **Phase 128 closed** (6a5e605).
  Char stacking API (font-agnostic). Spec-compliant CIDFont vertical
  writing — moved к **v1.3 backlog**.

### PDF features

- ~~PDF/A-1b / PDF/A-2u compliance~~ ✅ **Phase 47/103 closed**.
  PdfAConfig с configurable `part` (1/2/3) и `conformance` (A/B/U).
  XMP metadata stream + sRGB ICC profile в /OutputIntents + /Lang +
  encryption disable enforcement. PDF/A-1a (accessible, requires correct
  semantic Tagged PDF) deferred — passes Tagged PDF requirements but не
  enforces semantic conformance automatically.
- ~~Encryption (password-protected PDFs) — RC4-128~~ ✅ **Phase 41 closed** (c3d7743).
- ~~Encryption — AES-128 (V4 R4)~~ ✅ **Phase 42 closed** (2d18542).
- ~~AES-256 (V5 R5, Adobe Supplement)~~ ✅ **Phase 50 closed** (2040bfd).
- ~~AES-256 V5 R6 (PDF 2.0 — iterative hash 2.B)~~ ✅ **Phase 106 closed** (61b8230).
- ~~String encryption (literal strings → encrypted hex)~~ ✅ **Phase 77 closed** (e6c8d2f).
- ~~Form fields (interactive AcroForm) — text + checkbox~~ ✅ **Phase 43 closed** (aac0c20).
- ~~AcroForm extensions: multi-line, password, combo, list, radio~~ ✅ **Phase 46 closed** (7862b41).
- ~~Embedded files / attachments~~ ✅ **Phase 49 closed** (229bcd9).
- ~~Tagged PDF (accessibility minimum)~~ ✅ **Phase 48 closed** (a9ddacf).
- ~~Heading element + PDF/UA H1-H6 tagging~~ ✅ **Phase 61 closed** (38b3c3b).
- ~~Image alt-text для PDF/UA~~ ✅ **Phase 62 closed** (38ccc36).
- ~~Tagged PDF /Table /TR /TD~~ ✅ **Phase 65 closed** (ae22478).
- ~~Tagged PDF /L /LI~~ ✅ **Phase 66 closed** (fdf8117).
- ~~Tagged PDF /Link annotation /StructParent linking~~ ✅ **Phase 151 closed**.
  Link annotations теперь emit /StructParent N → ParentTree maps N к Link
  StructElem object reference. Completes PDF/UA roundtrip for /Link role
  (already закрыто как struct element class в Phase 72). Reading-order +
  custom role mapping уже implemented в earlier phases.
- ~~PDF/A-1b compliance mode~~ ✅ **Phase 47 closed** (25884b1).
- ~~AcroForm signature field placeholder~~ ✅ **Phase 56 closed** (b93c6c8).
- ~~AcroForm signature actual signing (PKCS#7 two-pass byte range)~~ ✅ **Phase 108 closed** (757e1e7).
- ~~JavaScript validation / calculation / format / keystroke actions~~ ✅ **Phase 67 closed** (5c8e1b4).
- ~~AcroForm submit / reset / push buttons~~ ✅ **Phase 83 closed** (ba8e13d).
- ~~AcroForm /CO calculation order~~ ✅ **Phase 97 closed** (ef445eb).
- ~~AcroForm /DA default appearance + /DR resources~~ ✅ **Phase 99 closed** (bd704dc).
- ~~Digital signatures (PKCS#7 signing of arbitrary fields)~~ ✅ **Phase 108 closed** (757e1e7).
- ~~PDF/UA heading hierarchy (/H1-/H6)~~ ✅ **Phase 61 closed** (38b3c3b).
- ~~PDF/UA alt-text для figures~~ ✅ **Phase 62 closed** (38ccc36).
- ~~PDF/UA /Link struct elements~~ ✅ **Phase 72 closed** (bfa0246).
- ~~PDF/UA /Artifact (header/footer/watermark)~~ ✅ **Phase 86 closed** (d35f7de).
- ~~PDF/UA /Lang Catalog hint~~ ✅ **Phase 89 closed** (5d361af).
- ~~PDF/UA /StructParents tree (reading order)~~ ✅ **Phase 92 closed** (0e46faa).
- ~~PDF/UA /RoleMap (custom role aliases)~~ ✅ **Phase 93 closed** (7bc4776).
- PDF/UA complete для structural elements (P/H1-H6/Figure/Table/L/Link/Artifact +
  /StructParents/Lang/RoleMap).

### Content

- ~~SVG support (basic shapes + simple path)~~ ✅ **Phase 52 closed** (60d362d).
- ~~SVG path curves (C/S/Q/T)~~ ✅ **Phase 53 closed** (b20a979).
- ~~SVG <text> element~~ ✅ **Phase 58 closed** (df9cd69).
- ~~SVG transforms (translate/scale/rotate/matrix)~~ ✅ **Phase 59 closed** (2ca5e04).
- ~~SVG path arcs (A command)~~ ✅ **Phase 63 closed** (bccf3b8). SVG path support теперь complete.
- ~~SVG CSS <style> blocks (tag/class/id selectors)~~ ✅ **Phase 73 closed** (e030802).
- ~~SVG <defs> + <use> element references~~ ✅ **Phase 74 closed** (2f32cc9).
- ~~SVG opacity attributes~~ ✅ **Phase 81 closed** (21c8883).
- ~~SVG linearGradient через PDF Pattern/Shading~~ ✅ **Phase 82 closed** (096ef07).
- ~~SVG multi-stop gradients (Type 3 stitching)~~ ✅ **Phase 90 closed** (a96d200).
- ~~SVG radialGradient~~ ✅ **Phase 91 closed** (71b851d).
- ~~SVG gradientTransform attribute~~ ✅ **Phase 95 closed** (7f2aec7).
- SVG complete: full path grammar + transforms + opacity + linear/radial gradients
  multi-stop + CSS styles + defs/use + text + gradientTransform.
- ~~Math equations (LaTeX-like sup/sub/frac/sqrt + Greek)~~ ✅ **Phase 69 closed** (f210de4).
- ~~Math matrices (matrix/pmatrix/bmatrix/vmatrix)~~ ✅ **Phase 75 closed** (57676fd).
- ~~Math big operators с limits (\\sum_{i=1}^n)~~ ✅ **Phase 80 closed** (b625b92).
- ~~Math multi-line equations~~ ✅ **Phase 96 closed** (09cf311).
- ~~Bar chart primitive~~ ✅ **Phase 44 closed** (01f22f0).
- ~~Line + Pie charts~~ ✅ **Phase 45 closed** (9251cd0).
- ~~Multi-series charts (grouped bar + multi-line)~~ ✅ **Phase 51 closed** (468ebeb).
- ~~Stacked bar chart~~ ✅ **Phase 54 closed** (edb9046).
- ~~Donut + Scatter charts~~ ✅ **Phase 55 closed** (71312d9).
- ~~Area chart (single + stacked)~~ ✅ **Phase 60 closed** (418dea8).
- ~~Chart grid lines (Bar/Line/Area)~~ ✅ **Phase 64 closed** (8f70f07).
- ~~Custom y-axis range (yMin/yMax)~~ ✅ **Phase 68 closed** (68f632b).
- ~~Chart axis titles (BarChart/LineChart/AreaChart)~~ ✅ **Phase 70 closed** (08cc18c).
- ~~Chart grid lines на 5 more charts~~ ✅ **Phase 71 closed** (0236048).
- ~~Chart smoothed splines (Catmull-Rom)~~ ✅ **Phase 98 closed** (808858f).
- ~~X-axis label rotation (BarChart)~~ ✅ **Phase 140 closed**.
  `xLabelRotationDeg` parameter (matplotlib-style: +45 CCW, -45 CW, 90 vertical).
  End-anchor convention via new `chartTextRotated` helper.
- ~~X-axis label rotation на остальные charts~~ ✅ **Phase 141 closed**.
  LineChart, AreaChart, MultiLineChart, GroupedBarChart, StackedBarChart все
  поддерживают `xLabelRotationDeg`.
- ~~Axis titles на остальных charts~~ ✅ **Phase 142 closed**.
  GroupedBarChart, StackedBarChart, MultiLineChart, ScatterChart все
  поддерживают xAxisTitle/yAxisTitle через общий drawChartAxisTitles helper.
- ~~Barcode primitives — Code 128~~ ✅ **Phase 32 closed** (8aa8f6c).
- ~~EAN-13 / UPC-A~~ ✅ **Phase 35 closed** (f26dcd3).
- ~~QR Code (Reed-Solomon ECC L V1-10 byte mode)~~ ✅ **Phase 36 closed** (b6521aa).
- ~~QR ECC M/Q/H levels (V1-V4 full, V5+ deferred)~~ ✅ **Phase 37 closed** (12802e9).
- ~~QR Numeric/Alphanumeric encoding modes~~ ✅ **Phase 38 closed** (8e5c680).
- ~~QR Kanji encoding mode (Shift_JIS)~~ ✅ **Phase 101 closed** (3a26626).
- ~~QR auto best-mask selection (8 patterns + penalty)~~ ✅ **Phase 105 closed** (c01d77f).
- ~~Code 128 Set C (numeric compression)~~ ✅ **Phase 57 closed** (b93c6c8).
- ~~Code 128 Set A (control chars)~~ ✅ **Phase 78 closed** (5c42a76).
- ~~DataMatrix (ECC 200 square 10×10..26×26)~~ ✅ **Phase 104 closed** (f4d8752).
- ~~PDF417 stacked linear 2D barcode~~ ✅ **Phase 124 closed** (f535bde).
  Pattern table извлечена как ISO standard facts через TCPDF reference;
  RS GF(929), byte/multi-byte compaction, ECC 0..8.
  External verification рекомендуется через реальный PDF417 reader.
- ~~Aztec Compact 1-4 layers~~ ✅ **Phase 125 closed** (5bedadd, c8be44d).
- ~~Aztec Full 5-32 layers~~ ✅ **Phase 126 closed** (0610bad).
  Ported ZXing's `Encoder.java` algorithm (Apache 2.0) для precise matrix
  layout (alignmentMap + 7-ring bullseye + 40-bit mode message + alignment
  grid lines + GF(1024)/GF(4096) для word sizes 10/12). ZXing CLI decoder
  verified across all 4 word sizes (L1/L3/L9/L26).
- ~~DataMatrix rectangular + larger sizes (32×32+)~~ ✅ **Phase 127 closed** (9704738).
  Ported ZXing DefaultPlacement + ErrorCorrection + SymbolInfo. 29 sizes
  (9 small + 6 rect + 14 multi-region). ZXing decoder verified 12×12,
  36×36, 52×52 interleaved RS, 80×80 16-region.
- ~~QR V5-V10 ECC M/Q/H mixed-block~~ ✅ **Phase 146 closed**.
  ECC_PARAMS extended c 6-element form для mixed-group cases (V5-Q = 2×15+2×16,
  V7-Q = 2×14+4×15, V8-M = 2×38+2×39, V9-M/Q/H, V10-L/M/Q/H mixed). Refactored
  splitDataBlocks() + interleaveBlocks() для two-group support. CAPACITY table
  extended для все 4 ECC levels на V5-V10. Kanji mode + V11-40 + auto best-mask
  деferred (separate phases).
- QR V11-V40 — moved к **v1.3 backlog**.
- ~~Watermark images~~ ✅ **Phase 30 closed** (197cc0b).
- ~~Watermark opacity через ExtGState `/ca`~~ ✅ **Phase 31 closed** (5d588b9).

### Performance

- ~~Streaming output (избежать full-document в memory)~~ ✅ **Phase 129 closed** (3fbe51d).
  Writer::toStream / Document::toStream API. Streaming PKCS#7 signing +
  per-object incremental content stream emission — moved к **v1.3 backlog**.
- ~~Lazy font subset~~ ✅ **Phase 130 closed** (347bf8b).
  Per-Writer registration (fixes multi-Document PdfFont reuse bug) +
  reset() API для per-doc minimal subsets.
- ~~Image deduplication by content hash~~ ✅ **Phase 29 closed** (f47c1f9).

---

## v1.2 — Output size optimization (closed)

Motivation: benchmark template 13 (`docs/bench-pdf-vs-mpdf.md` в printable repo)
showed php-pdf output 82KB vs mpdf 57KB (mpdf 1.44× more compact).

### Results after Phase 158-160:

| Metric | Before | After | Delta | mpdf ratio |
|---|---|---|---|---|
| Output size | 82194 B | 77717 B | **-5.4%** | 1.36× → closer к 1.44× |
| Wall time | 24.82 ms | 24.02 ms | **-3.2%** | 0.58× faster |
| Memory peak | 36 MB | 36 MB | 0 | 0.71× less |

### ~~Phase 158: TJ-array grouping~~ ✅ closed (-5.4%, -3.2% wall)

Соседние Run'ы того же font+size+color на одном baseline сливаются
в single `BT/Tj/ET` block. Engine::emitLine accumulator с flush на
style boundary/justify gap/image/link change.

Меньше предполагаемых -15% — обоснование: Type0 CID fonts с hex multi-byte
encoding имеет меньше относительный overhead BT/ET vs content size.

### ~~Phase 159: Page Resources dict slimming~~ ✅ already in place

Проверка показала: `Page::registerEmbeddedFont` накапливает fonts по факту
использования; Document::emitResources iterates только used fonts per page.
Template 13: 2 fonts declared per page (не 4). Нет дополнительных wins.

### ~~Phase 160: gstate dedup в ContentStream~~ ✅ closed (uncompressed -2.9%)

Drop q/Q wrap вокруг text emit, track lastFillR/G/B, skip duplicate `rg` ops.
Page 0 content stream uncompressed: 20257 → 19661 bytes (-2.9%), rg ops
41 → 21 (-50%), q/Q wraps 156/155 → 125/124 (-20%).

Compressed PDF (FlateDecode): no measurable change — Flate already efficient
на repetitive sequences. Phase 160 wins: cleaner streams для inspection,
faster compress, savings без compression.

### Phase 161: Cross-Writer font subset dedup — deferred к v1.3

Batch-only optimization (нет улучшения per-doc). Требует SHA-1 cache subset
bytes + invalidation на font update. Перенесён в v1.3 backlog.

### Conclusion

Combined -27..30% target не достигнут (achieved -5.4%). Основная причина:
Type0 CID font encoding с multi-byte hex glyph IDs имеет inherent compactness
тhat кладёт ceiling на TJ-grouping wins. mpdf uses different font encoding
(Type 1 ASCII subset) что compresses differently.

Дальнейшие возможные оптимизации (не критичные):
- Font subset re-encoding (Type0 → Type1 для Latin) — major refactor
- Custom FlateDecode dictionary preset
- xref stream (PDF 1.5) вместо plain xref table — saves ~1-2KB metadata

---

## v1.3 — Progress (Phase 138-174 closed) + remaining work

### Font / text shaping

- **CIDFont vertical writing** — Type 0 + UniJIS-UTF16-V CMap + /WMode 1 + vmtx.
  *Partial progress (Phase 192):* vmtx/vhea parser в TtfFile добавлен —
  `TtfFile::advanceHeight($glyphId)` + `::hasVerticalMetrics()`.
  Остаётся: bundle Adobe-Japan1 CMap data (~50KB), Type 0 composite font emit
  с CIDFontType2 + /WMode 1.
- **CFF2 variable fonts** — currently only TrueType glyf-based supported.
  Requires full CFF Type 2 interpreter (CharString operators, blend operator,
  Item Variation Store integration, CIDKeyed CFFs). Scope similar к Phase 131-134.
- ~~Bidi X1-X10 explicit embedding/override stack~~ ✅ **Phase 187 closed**.
  Full UAX 9 §3.3: LRE/RLE/LRO/RLO/PDF embeddings + LRI/RLI/FSI/PDI isolates,
  125-level stack, FSI direction scan, override types.
- ~~Composite glyph per-component dx/dy gvar deltas~~ ✅ **Phase 186 closed**.
  New CompositeGlyph parser/serializer + VariableInstance::transformComposite
  applies gvar deltas к component anchor offsets с int8/int16 promotion.
- ~~Sinhala two-part matras с virama component~~ ✅ **Phase 169 closed** (clarif:
  single-codepoint path covers most Sinhala fonts).

### Barcodes

- **QR V11-V40 large versions** — ~120 additional ECC_PARAMS entries, extended
  ALIGN_POSITIONS, version-info BCH(18,6) encoding near BL/TR finders.
  Recommend separate phase с real QR decoder verification per version.
- ~~QR ECI~~ ✅ **Phase 184 closed** (4-bit mode 0111 + 8/16/24-bit designator).
- ~~QR Structured Append~~ ✅ **Phase 183 closed** (20-bit header + parity factory).
- **DataMatrix 144×144** — special interleaved layout (different from
  standard square sizes); needs custom ZXing-spec placement.
- ~~DataMatrix encoding modes~~ ✅ **Phase 176-180 closed** (Base 256, C40,
  Text, X12, EDIFACT + auto-mode heuristic).
- ~~PDF417 Text/Numeric compaction~~ ✅ **Phase 181-182 closed** (3 modes +
  auto: byte/text/numeric с big-int base-900 conversion).
- ~~PDF417 Macro PDF417~~ ✅ **Phase 185 closed** (macroSegment factory с
  CW 928/922/923 control block).
- **Aztec Rune mode** — single-character symbol variant. 11×11 fixed format
  отдельный от regular Aztec — нужен separate placement algorithm.
- **Aztec Structured Append / ECI / FLG(n)** — extended channel interpretation.
  Inserted в encoded data stream через FLG escapes — needs Aztec encoder
  internals refactor.
- ~~Code 128 auto-mode switching~~ ✅ **Phase 164 closed**.
- ~~Code 128 GS1-128~~ ✅ **Phase 165 closed**.

### Layout / typography

- **Footnote true page-bottom positioning** — per-page reserved zone (currently
  inline at end of body). Requires multi-pass layout or content reservation.
- **LineBreaker Knuth-Plass optimal** — backtracking с boxes-glues-penalties.
- **LineBreaker hanging punctuation** — punctuation extending past margins
  for visual alignment.
- **LineBreaker tab-stops** — explicit tab positioning beyond simple horizontal
  advance.
- ~~PieChart true Bezier arc rendering~~ ✅ **Phase 166 closed**.
- ~~PieChart exploded slices~~ ✅ **Phase 167 closed**.
- ~~PieChart perimeter labels~~ ✅ **Phase 168 closed**.
- ~~MathExpression nested fractions в superscripts~~ ✅ **Phase 172 closed**
  (was already working through recursive render).
- ~~MathExpression custom font / styling~~ ✅ **Phase 173 closed** (fontFamily param).
- ~~MathExpression LaTeX environments~~ ✅ **Phase 174 closed** (begin/end stripping
  для align/aligned/gather/eqnarray/cases/matrix variants).
- ~~LineBreaker tab-stops~~ ✅ **Phase 188 closed** (Engine.tabStopPt + 'tab'
  item type + x advancement к next stop в emitLine).
- ~~LineBreaker hanging punctuation~~ ✅ **Phase 189 closed** (Engine.hangingPunctuation
  + trailing-punct width discount в wrap decision).

### PDF features

- **Public-key encryption** — /Filter /PubSec (currently /Standard only).
  X.509 certificate-based access control.
- ~~PDF/A-1a (accessible)~~ ✅ **Phase 190 closed**. PdfAConfig::CONFORMANCE_A
  с part=1 automatically enables Tagged PDF (matches PDF/A-2a, 3a behavior).
- ~~Streaming PKCS#7 signing~~ ✅ **Phase 191 closed**. Seekable streams
  (file handles) emit incrementally + seek back для patch; non-seekable
  fallback к buffer.
- **Per-object content stream incremental emission** — currently each Page
  content stream materializes fully before emission. Deeper API rewrite.

---

## v1.4 — Multi-week scope items (deferred post-v1.3-publication)

Эти items require dedicated multi-day или multi-week development блоки —
не fit'ят в incremental phase work. Scope estimates per item.

### Font systems (1-2 weeks each)

- **CIDFont vertical writing** (Type 0 + UniJIS-UTF16-V CMap + vmtx) —
  Adobe-Japan1 CMap data (~50KB) + Type 0 composite fonts. *(vmtx parser
  + /WMode 1 emitter частично готовы в Phase 192/194.)*
- **CFF2 variable fonts** — full CFF Type 2 interpreter (CharString ops,
  blend operator, Item Variation Store integration, CIDKeyed CFFs).
- ~~Bidi X1-X8 explicit embedding/override stack~~ ✅ **Phase 187 closed**.
- ~~Composite glyph per-component dx/dy gvar deltas~~ ✅ **Phase 186 closed**.

### Barcodes — encoding modes & large versions (1 day to 1 week each)

- **QR V11-V40 large versions** — ~120 ECC_PARAMS entries + extended
  ALIGN_POSITIONS.
- ~~QR version-info BCH(18,6) pattern (V7+)~~ ✅ **Phase 195 closed**.
  18-bit BCH-encoded version info placed в top-right + bottom-left
  (mirror) regions; generator polynomial 0x1F25.
- ~~QR Structured Append / ECI~~ ✅ **Phase 183-184 closed**.
- **DataMatrix 144×144** — special interleaved layout.
- ~~DataMatrix encoding modes~~ ✅ **Phase 176-180 closed** (Base 256, C40,
  Text, X12, EDIFACT + auto-mode heuristic).
- ~~DataMatrix Macro 05/06~~ ✅ **Phase 196 closed** (CW 236/237 prepend).
- ~~DataMatrix GS1 / ECI~~ ✅ **Phase 197 closed** (FNC1 CW 232 + ECI CW 241
  с 1/2/3-byte designator encoding).
- ~~PDF417 Text/Numeric compaction~~ ✅ **Phase 181-182 closed**.
- ~~PDF417 Macro PDF417~~ ✅ **Phase 185 closed**.
- ~~PDF417 GS1 / ECI~~ ✅ **Phase 198 closed** (FNC1 CW 920 + ECI CW 927).
- ~~EAN-13 / UPC-A add-on supplements (EAN-2, EAN-5)~~ ✅ **Phase 199 closed**.
  `addOn: ?string` параметр в Ean13Encoder; 20-module 2-digit (parity LL/LG/
  GL/GG по value%4) и 47-module 5-digit (parity по check digit = (3·sum_odd
  + 9·sum_even)%10) supplements с 9-module gap после END_GUARD.
- ~~EAN-8 short variant~~ ✅ **Phase 200 closed**. Отдельный `Ean8Encoder`
  (7 data + 1 check digit, 67 modules, все 4 left digits L-coded без G-shift)
  + `BarcodeFormat::Ean8` + dispatch в Engine::renderBarcode.
- ~~UPC-E zero-suppressed variant~~ ✅ **Phase 201 closed**. `UpcEEncoder`
  с 4 zero-suppression правилами (D6=0..2/3/4/≥5), L/G parity pattern table
  по check digit для NSD=0, инверсия для NSD=1. 51 модуль (start 101 + 6
  digits 42 + end "010101" 6). `BarcodeFormat::UpcE` + Engine dispatch.
- ~~Code 39 alphanumeric variable-length~~ ✅ **Phase 202 closed**.
  ISO/IEC 16388 — 43 chars (0-9, A-Z, `-`, `.`, ` `, `$`, `/`, `+`, `%`)
  + `*` start/stop. 9 elements/char (5 bars + 4 spaces), 3 wide @ 3:1 ratio
  + 1-narrow inter-char gap = 16 modules per char-with-gap. Optional Mod-43
  self-check digit via `withCheckDigit: true`. `BarcodeFormat::Code39` +
  Engine dispatch.
- ~~ITF (Interleaved 2 of 5) — ITF-14 GTIN profile~~ ✅ **Phase 203 closed**.
  ISO/IEC 16390 — numeric even-length. Pair interleaving: digit A → 5 bars,
  digit B → 5 spaces (3 narrow + 2 wide per digit @ 2:1 ratio). Start
  `1010`, stop wide-bar + narrow-space + narrow-bar. 8 + 7·N modules.
  Optional GTIN-style Mod-10 right-to-left weighted check digit. Поддержка
  GTIN-8/12/13/14 через `computeCheckDigit()`. `BarcodeFormat::Itf` + Engine.
- ~~Codabar (NW-7 / USS Codabar)~~ ✅ **Phase 204 closed**. Numeric + 6
  punctuation (`-$:/.+`) + 4 start/stop chars (A/B/C/D). 7 elements/char
  (4 bars + 3 spaces), 2 wide chars = 9 mod, 3 wide chars = 10 mod @ 2:1
  ratio. Custom start/stop via constructor params. `BarcodeFormat::Codabar`
  + Engine dispatch. Used by libraries, blood banks, FedEx ground.
- ~~Code 93 alphanumeric с dual Mod-47 check~~ ✅ **Phase 205 closed**.
  USS Code 93 — 47-char set (43 user-allowed + 4 shift placeholders для
  check digit overflow), continuous encoding (9 modules/char, no inter-char
  gaps), mandatory dual check digits C (weight cycle 1..20) и K (1..15).
  Module count = 9·(N+4) + 1 (включая termination bar). Denser successor
  Code 39 — больше data density + automatic error detection.
- ~~MSI Plessey (Modified Plessey) — retail shelving~~ ✅ **Phase 206 closed**.
  Numeric only, variable length. Each digit = 4 bits BCD (MSB first),
  each bit = 3 modules (`0`→`100`, `1`→`110`). Start `110` + N×12 +
  stop `1001` = 12·N + 7 modules. Optional Mod-10 check digit (Luhn-style
  variant) via `withCheckDigit: true`. `BarcodeFormat::MsiPlessey` +
  Engine dispatch.
- ~~Pharmacode (Laetus) — pharma blister packs~~ ✅ **Phase 207 closed**.
  Самый простой 1D barcode — value 3..131070 без start/stop/check digit.
  Recursive algorithm: even → wide bar + N=N/2−1; odd → narrow bar + N=(N−1)/2.
  Module widths 1/3/2 (narrow bar / wide bar / inter-bar space). Max 16 wide
  bars для value=131070 = 78 modules. `BarcodeFormat::Pharmacode` + Engine
  dispatch (string→int conversion).
- ~~Code 11 (USS Code 11) — telecom labeling~~ ✅ **Phase 209 closed**.
  11 chars (0-9 + `-`). 5 elements/char (3 bars + 2 spaces) с 1 или 2 wide
  elements @ 2:1 ratio = 6 or 7 modules per char. Implicit `*` start/stop
  + 1-narrow inter-char gap. Optional Mod-11 check digits: single C
  (weight cycle 1..10) via `withCheckDigit: true`, or dual C+K (C weight
  1..10, K weight 1..9) via `doubleCheck: true`. `BarcodeFormat::Code11`
  + Engine dispatch.
- ~~QR FNC1 mode 1 (GS1) + mode 2 (AIM) markers~~ ✅ **Phase 211 closed**.
  Per ISO/IEC 18004 §6.4.7 — Mode 1 (GS1): 4-bit `0101` indicator. Mode 2
  (AIM): 4-bit `1001` + 8-bit Application Indicator. `fnc1Mode` + optional
  `fnc1AimIndicator` constructor params в QrEncoder с full validation
  (mode 1/2 only, AIM indicator required only для mode 2, value 0..255).
- **Aztec Rune mode** — single-character symbol variant. 11×11 fixed format.
- **Aztec Structured Append / ECI / FLG(n)** — needs Aztec encoder
  internals refactor.

### Layout / typography (1-3 days each)

- **Footnote true page-bottom positioning** — per-page reserved zone,
  multi-pass layout architecture.
- **LineBreaker Knuth-Plass optimal** — boxes-glues-penalties с backtrack.
- ~~LineBreaker hanging punctuation~~ ✅ **Phase 189 closed**.
- ~~LineBreaker tab-stops~~ ✅ **Phase 188 closed**.

### PDF features (1-2 weeks each)

- **Public-key encryption** (/Filter /PubSec) — X.509 certificate-based
  access control. Significant Encryption class refactor.
- ~~PDF/A-1a (accessible)~~ ✅ **Phase 190 closed**.
- ~~Streaming PKCS#7 signing~~ ✅ **Phase 191 closed**.
- **Per-object content stream incremental emission** — deep API rewrite.

### Output optimization (bonus)

- **Phase 161 Cross-Writer font subset dedup** — batch scenario only,
  needs LRU cache + invalidation strategy.
- **Type0 → Type1 Latin re-encoding** — biggest potential output-size win.
  Significant font subsetter refactor.
- ~~xref streams (PDF 1.5)~~ ✅ **Phase 208 closed**. `Document::useXrefStream`
  constructor flag + `Pdf\Document::useXrefStream()` setter + `Writer::__construct`
  `$useXrefStream` param. Binary-packed (W=[1 4 2]) FlateDecode XRef stream
  object replaces classic `xref...trailer` keywords. Auto-bumps PDF version
  к 1.5. Disabled for PKCS#7 signing path. Output ~50% smaller metadata.

### Other v1.4 features (closed in current cycle)

- ~~Document::pdfVersion constructor param~~ ✅ **Phase 210 closed**.
  Expose PDF version targeting на top-level API (default null = Engine's
  1.7; subsystems auto-bump for AES/XRef stream/PDF 2.0). Useful для
  '1.4' legacy compat или '2.0' modern features.

### Pragmatic publication strategy

For v1.3 publication, current feature set:
- Latin/Cyrillic typography с full kerning/ligatures
- Indic shaping (Phase 137-139, 144)
- Arabic shaping (Phase 135)
- Bidi UAX 9 implicit + X9 filter + L3 mirroring (Phase 136, 148)
- 11 chart types с rotation + axis titles
- Variable fonts (TrueType glyf, Phase 131-134)
- All major barcode formats (basic encoding modes)
- Tagged PDF + PDF/A-1b/2u
- Encryption (RC4/AES-128/AES-256 R5+R6)
- Signing (PKCS#7 with internal buffer)
- 1315 tests, production-ready

Substantive gaps documented above — typically per-script (CJK), edge cases
(QR V40), или architecture-deep (Knuth-Plass, per-object streams).

---

## Done — Phases 1-12 (v0.13)

| Phase | Описание | Tests added | Commit |
|-------|----------|-------------|--------|
| 1 | PDF skeleton: Document/Page/Writer + StandardFont base-14 | ~30 | — |
| 2a-b | TTF subset embedding (86% size reduction) | — | — |
| 2c | GPOS kerning (pair adjustments через TJ) | — | cec1716 |
| 2d | GSUB ligatures (fi/fl/ffi + multi-cp ToUnicode) | — | afe78a0 |
| 3a | AST elements + style VO mirror'инг php-docx | — | 76be6f8 |
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
| 13 | Custom font registration (FontProvider + Liberation bundle) | 10 + 8 + 2 | 4dc641c (php-pdf) + af2efea (fonts) + 4bc1cd9 (printable) |
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
| 25 | Paragraph padding + background (v1.1) | 4 | ee907af + fb8580e |
| 26 | Sup/Sub visual sizing (v1.1) | 3 | b3426a5 |
| 27 | Inline letter-spacing через span (v1.1) | +1 | 6209791 (printable) |
| 28 | Border priority "thicker wins" (v1.1, Phase 19 deferred) | 3 | f680192 |
| 29 | Image content dedup by hash (v1.1) | 3 | f47c1f9 |
| 30 | Image watermark (v1.1) | 7 | 197cc0b |
| 31 | Watermark opacity через ExtGState (v1.1) | 13 | 5d588b9 |
| 32 | Code 128 barcode primitive (v1.1) | 17 | 8aa8f6c |
| 33 | Soft hyphen (U+00AD / &shy;) wrap hints (v1.1) | 5 | 309f5d8 |
| 34 | Multi-section documents (v1.1) | 8 | b57ddb4 |
| 35 | EAN-13 / UPC-A barcode (v1.1) | 11 | f26dcd3 |
| 36 | QR Code byte-mode ECC L V1-10 (v1.1) | 12 | b6521aa |
| 37 | QR ECC M/Q/H levels (V1-V4) (v1.1) | 9 | 12802e9 |
| 38 | QR Numeric / Alphanumeric encoding modes (v1.1) | 14 | 8e5c680 |
| 39 | Multi-column layout (ColumnSet) (v1.1) | 7 | 5180a42 |
| 40 | Footnotes / endnotes (v1.1) | 6 | a18a70c |
| 41 | PDF encryption V2 R3 RC4-128 (v1.1) | 8 | c3d7743 |
| 42 | AES-128 encryption V4 R4 (v1.1) | 7 | 2d18542 |
| 43 | AcroForm (text + checkbox) (v1.1) | 9 | aac0c20 |
| 44 | BarChart primitive (v1.1) | 8 | 01f22f0 |
| 45 | LineChart + PieChart (v1.1) | 10 | 9251cd0 |
| 46 | AcroForm extensions (multiline/password/combo/list/radio) (v1.1) | 9 | 7862b41 |
| 47 | PDF/A-1b compliance mode (v1.1) | 9 | 25884b1 |
| 48 | Tagged PDF (accessibility minimum) (v1.1) | 5 | a9ddacf |
| 49 | Embedded files / attachments (v1.1) | 8 | 229bcd9 |
| 50 | AES-256 encryption V5 R5 (v1.1) | 9 | 2040bfd |
| 51 | Multi-series charts (GroupedBar + MultiLine) (v1.1) | 9 | 468ebeb |
| 52 | SVG support (basic shapes + simple path) (v1.1) | 13 | 60d362d |
| 53 | SVG path curves (C/S/Q/T + H/V + relative) (v1.1) | 11 | b20a979 |
| 54 | Stacked bar chart (v1.1) | 7 | edb9046 |
| 55 | Donut + Scatter charts (v1.1) | 10 | 71312d9 |
| 56-57 | AcroForm signature placeholder + Code 128 Set C (v1.1) | 7 | b93c6c8 |
| 58 | SVG <text> element (v1.1) | 7 | df9cd69 |
| 59 | SVG transforms (translate/scale/rotate/matrix) (v1.1) | 10 | 2ca5e04 |
| 60 | Area chart (single + stacked) (v1.1) | 8 | 418dea8 |
| 61 | Heading + PDF/UA H1-H6 tagging (v1.1) | 7 | 38b3c3b |
| 62 | Image alt-text для PDF/UA (v1.1) | 5 | 38ccc36 |
| 63 | SVG path arcs (A command) (v1.1) | 7 | bccf3b8 |
| 64 | Chart grid lines (Bar/Line/Area) (v1.1) | 4 | 8f70f07 |
| 65 | Tagged PDF /Table /TR /TD (v1.1) | 6 | ae22478 |
| 66 | Tagged PDF /L /LI (v1.1) | 5 | fdf8117 |
| 67 | AcroForm JavaScript actions (v1.1) | 8 | 5c8e1b4 |
| 68 | Chart custom axis ranges (yMax) (v1.1) | 5 | 68f632b |
| 69 | MathExpression (LaTeX subset) (v1.1) | 11 | f210de4 |

**Итого:** 448 тестов в php-pdf, 194 теста в printable, 8 в
Liberation package.

---

## Контрибьюция / новые gaps

Когда находится новый gap во время работы над v1.0:
1. Если **критичен** для production switch (визуально ломает корпус
   шаблонов) — добавляем в v1.0 как новую Phase N.
2. Если **не критичен** (corner case, nice-to-have, extension) —
   добавляем в v1.1.
3. Каждая Phase в roadmap'е соответствует одному feat-коммиту в репо.
