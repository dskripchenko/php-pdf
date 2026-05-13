# dskripchenko/php-pdf — Roadmap

Pure-PHP, MIT-licensed PDF renderer. Цель — drop-in замена `mpdf/mpdf`
(GPL-2.0) в production-стеке printable-приложения с feature parity на
типичных бизнес-документах (договоры, акты, счета, отчёты).

**Текущий статус:** v0.13 — 12 фаз закрыты (392 теста, 888 assertions).
mpdf остаётся production-default; php-pdf opt-in через `?engine=php-pdf`.

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

**Phase 13: Custom font registration**
- Сейчас PhpPdfEmitter загружает только Liberation Sans (4 variant'а)
  напрямую из `vendor/dskripchenko/php-pdf/.cache/fonts/...`. Это
  dev/test path, в production не работает.
- Нужно: `FontProvider` interface в php-pdf + бандлинг TTF файлов в
  `dskripchenko/php-pdf-fonts-liberation` (сейчас stub).
- Дополнительно: resolver по `font-family` CSS property (Arial →
  LiberationSans alias уже есть; нужна интеграция в Engine).

**Phase 14: PDF compression**
- Content streams сейчас raw (uncompressed). Файлы в 2-4× больше mpdf
  на equivalent контенте (e.g. 195KB vs 47KB на одном договоре).
- Нужно: `/Filter /FlateDecode` для content streams + image streams
  (где не DCTDecode); опциональный flag `compress` в Engine.
- Цель: php-pdf output в пределах 1.5× от mpdf size.

**Phase 15: Justify alignment**
- `text-align: justify` сейчас деградирует к `Start` (см. Engine
  emitLine `Both/Distribute → default Start`). Юридические документы
  часто требуют justify.
- Нужно: greedy line-breaker + per-line space distribution (Word-spacing
  Tj adjustments или `Tw` operator).

**Phase 16: Inline images (text wrap)**
- Image AST элемент сейчас всегда block-level. `<img>` внутри
  параграфа становится отдельным блоком (Image при mapAsBlock).
- Нужно: inline image flow с baseline alignment + размерами в pt/px.
- Сложность средняя: line-break algorithm должен учитывать image как
  inline atom с width/height; baseline mapping.

**Phase 17: CSS `<style>` блоки + классы**
- Сейчас `HtmlParser` парсит только inline `style="..."`. `<style>`
  блоки и `class=".cls { ... }"` игнорируются.
- В printable у `DocxEmitter` уже есть `CssInliner` — может быть
  переиспользован: применяется к HTML до парсинга, конвертирует CSS
  rules в inline styles. PhpPdfEmitter сможет работать с inline.
- Нужно: либо runtime CssInliner до parse, либо native cascade в
  HtmlParser.

### Important (feature parity, не строгий блокер)

**Phase 18: border-radius (rounded corners)**
- Сейчас `border-radius` в Whitelist'е есть, но игнорируется.
- Нужно: corners → Bezier curves в content stream (`re` → `m + c + l`
  с control points для четвертного arc'а).

**Phase 19: border-spacing + border priority**
- В collapse-mode сейчас "first-drawn wins" (left cell.right убирается).
  CSS spec требует "thicker wins" (или "more prominent style").
- В separate-mode `border-spacing: Xpt` сейчас игнорируется (cells
  всегда rendered впритык).
- Нужно: priority resolution algorithm + spacing adjustment.

**Phase 20: PDF metadata (/Info dict)**
- Сейчас Document не пишет `/Info` объект в PDF (Title/Author/Subject/
  Producer/CreationDate отсутствуют).
- Нужно: `Document.metadata(...)` API + emission в trailer reference.
- Это влияет на SEO, archival, library catalogues.

**Phase 21: line-height + letter-spacing**
- `line-height` сейчас только из ParagraphStyle.lineHeightMult (DSL).
- Нужно: CSS-derived `line-height: 1.5` или `line-height: 18pt` →
  Engine применяет.
- `letter-spacing` (CSS tracking) → PDF `Tc` operator (character spacing).

**Phase 22: Hyphenation + word-break**
- Длинные слова без spaces сейчас overflow за right margin (greedy
  line-break не разбивает один word).
- Нужно: либо `&shy;` soft-hyphen support, либо word-break-all для
  не-Latin (CJK), либо basic dictionary-based hyphenation.

**Phase 23: Margin/padding precision**
- ParagraphStyle сейчас имеет spaceBefore/After (~margin-top/bottom),
  indentLeft/Right (~margin-left/right), indentFirstLine. Нет precise
  padding (внутри Paragraph до текста).
- Нужно: ParagraphStyle.paddingPt × 4 sides + background-color на
  Paragraph (сейчас только на TableCell).

**Phase 24: MERGEFIELD value resolution**
- Field::mergeField сейчас рендерит format-параметр (field name) как
  placeholder. Реальная замена значений — задача printable's render
  pipeline (`buildRenderData` уже это делает на pre-parse этапе через
  `{{ var }}` substitution в Blade).
- Decision: либо оставить placeholder behavior как есть (production
  print уже отрабатывает на Blade-уровне), либо добавить runtime
  values map в Engine. Открытый вопрос.

---

## v1.1 — Nice-to-have / extension

(Заполняется по мере discovery во время работы над v1.0.)

### Layout

- Multi-section docs (разная orientation/margins per section).
- Section breaks (явная смена pageSetup mid-document).
- Footnotes / endnotes.
- Multi-column layout (CSS `column-count`).
- Complex script shaping (Arabic ligatures, Indic combining marks).

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
- Barcode / QR code primitives.
- Watermark images (сейчас только text).

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

**Итого:** 392 теста в php-pdf, 189 теста в printable (PhpPdfEmitter +
borders + CSS + smoke).

---

## Контрибьюция / новые gaps

Когда находится новый gap во время работы над v1.0:
1. Если **критичен** для production switch (визуально ломает корпус
   шаблонов) — добавляем в v1.0 как новую Phase N.
2. Если **не критичен** (corner case, nice-to-have, extension) —
   добавляем в v1.1.
3. Каждая Phase в roadmap'е соответствует одному feat-коммиту в репо.
