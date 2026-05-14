# dskripchenko/php-pdf — Roadmap

Pure-PHP, MIT-licensed PDF renderer. Цель — drop-in замена `mpdf/mpdf`
(GPL-2.0) в production-стеке printable-приложения с feature parity на
типичных бизнес-документах (договоры, акты, счета, отчёты).

**Текущий статус:** v1.1-dev — 126 фаз закрыты (1149 + 194 printable = 1343 теста).
v1.0 production-ready closed (Phase 1-21 + 24 by-design + 22/23 deferred).
v1.1 в активной разработке.

**v1.0 final:** Production-ready для типичных бизнес-документов. Critical
блокеры (13-17) закрыты, Important (18-21) закрыты.
mpdf остаётся production-default; php-pdf opt-in через `?engine=php-pdf`.

**v1.1 progress:** 25-128 closed (104 фазы):
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
   showEmbeddedTextVertical APIs).

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
- ~~Border priority resolution в collapse mode~~ ✅ **Phase 28 closed** (f680192).
- ~~Hyphenation (soft-hyphen `&shy;`)~~ ✅ **Phase 33 closed** (309f5d8).
- ~~Paragraph padding + background~~ ✅ **Phase 25 closed** (ee907af + fb8580e).
- ~~Inline letter-spacing через `<span>`~~ ✅ **Phase 27 closed** (6209791).
- Complex script shaping (Arabic ligatures, Indic combining marks).
- ~~Line-height absolute (`line-height: 18pt`)~~ ✅ **Phase 79 closed** (9a33ed7).

### Typography

- ~~Subscript/superscript visual sizing~~ ✅ **Phase 26 closed** (b3426a5).
- ~~Font fallback chain~~ ✅ **Phase 76 closed** (0834e38).
- Variable fonts (OpenType variations).
- ~~Vertical text (Asian scripts)~~ ✅ **Phase 128 closed** (6a5e605).
  Char stacking API (font-agnostic). Spec-compliant Type 0 CIDFont +
  UniJIS-UTF16-V CMap + /WMode 1 + vmtx — deferred.

### PDF features

- PDF/A-1b / PDF/A-2u compliance (для архивных требований).
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
- Tagged PDF /Link, reading order /StructParents tree, role mapping.
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
- Chart extensions: x-axis label rotation, axis titles на остальных charts.
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
- QR extensions: V5+ ECC M/Q/H (mixed-block layout), Kanji mode, V11-40,
  auto best-mask selection.
- ~~Watermark images~~ ✅ **Phase 30 closed** (197cc0b).
- ~~Watermark opacity через ExtGState `/ca`~~ ✅ **Phase 31 closed** (5d588b9).

### Performance

- Streaming output (избежать full-document в memory).
- Lazy font subset (currently every used glyph embedded).
- ~~Image deduplication by content hash~~ ✅ **Phase 29 closed** (f47c1f9).

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
