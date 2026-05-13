# dskripchenko/php-pdf — Roadmap

Pure-PHP, MIT-licensed PDF renderer. Цель — drop-in замена `mpdf/mpdf`
(GPL-2.0) в production-стеке printable-приложения с feature parity на
типичных бизнес-документах (договоры, акты, счета, отчёты).

**Текущий статус:** v1.1-dev — 34 фаз закрыто (521 + 194 printable = 715 тестов).
v1.0 production-ready closed (Phase 1-21 + 24 by-design + 22/23 deferred).
v1.1 в активной разработке.

**v1.0 final:** Production-ready для типичных бизнес-документов. Critical
блокеры (13-17) закрыты, Important (18-21) закрыты.
mpdf остаётся production-default; php-pdf opt-in через `?engine=php-pdf`.

**v1.1 progress:** 25 (paragraph padding+bg), 26 (sup/sub sizing),
27 (inline letter-spacing), 28 (border priority), 29 (image content
dedup), 30 (image watermark), 31 (watermark opacity через ExtGState),
32 (Code 128 barcode), 33 (soft hyphen &shy;), 34 (multi-section docs),
35 (EAN-13 / UPC-A barcode), 36 (QR Code byte-mode ECC L V1-10) closed.

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

- Multi-section docs (разная orientation/margins per section).
- Section breaks (явная смена pageSetup mid-document).
- Footnotes / endnotes.
- Multi-column layout (CSS `column-count`).
- Complex script shaping (Arabic ligatures, Indic combining marks).
- Border priority resolution в collapse mode ("thicker wins" CSS spec
  правило vs current first-drawn-wins). Перенесено из v1.0 Phase 19.
- **Hyphenation** (Phase 22): TeX-pattern или dictionary-based;
  soft-hyphen `&shy;` support. Перенесено из v1.0.
- **Paragraph padding + background** (Phase 23): сейчас только
  TableCell имеет; Paragraph только margin (spaceBefore/After+indent).
  Перенесено из v1.0.
- Inline letter-spacing через `<span style="letter-spacing">` —
  требует Mark::letterSpacing constant.
- Line-height absolute (`line-height: 18pt`) точно вместо approx /11
  multiplier conversion.

### Typography

- Subscript/superscript visual sizing (сейчас style сохраняется но
  glyph не масштабируется).
- Font fallback chain (если main font не содержит glyph — fallback
  на другой).
- Variable fonts (OpenType variations).
- Vertical text (Asian scripts).

### PDF features

- PDF/A-1b / PDF/A-2u compliance (для архивных требований).
- Encryption (password-protected PDFs).
- Form fields (interactive AcroForm).
- JavaScript actions.
- Embedded files / attachments.
- Digital signatures.
- Tagged PDF (accessibility).

### Content

- SVG support (parse + rasterize или native PDF paths).
- Math equations (LaTeX-like rendering).
- Charts / graphs (line, bar, pie native).
- ~~Barcode primitives — Code 128~~ ✅ **Phase 32 closed** (8aa8f6c).
- ~~EAN-13 / UPC-A~~ ✅ **Phase 35 closed** (f26dcd3).
- ~~QR Code (Reed-Solomon ECC L V1-10 byte mode)~~ ✅ **Phase 36 closed** (b6521aa).
- Barcode formats: Code 128 Set A/C, DataMatrix, PDF417, Aztec.
- QR extensions: ECC M/Q/H, Numeric/Alphanumeric/Kanji modes, V11-40, auto best-mask.
- ~~Watermark images~~ ✅ **Phase 30 closed** (197cc0b).
- ~~Watermark opacity через ExtGState `/ca`~~ ✅ **Phase 31 closed** (5d588b9).

### Performance

- Streaming output (избежать full-document в memory).
- Lazy font subset (currently every used glyph embedded).
- Image deduplication across pages (already есть для same instance,
  но не для same bytes).

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
