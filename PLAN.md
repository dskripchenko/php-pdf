# План реализации — dskripchenko/php-pdf

> Статус: **планирование / research**. Документ фиксирует scope-решения,
> известные проблемные области, research-вопросы и реалистичный
> phasing. Ничего здесь не является финальным до валидации через POC'и.

## Содержание

1. [Зафиксированные решения](#зафиксированные-решения)
2. [Зачем своя библиотека](#зачем-своя-библиотека)
3. [Почему сравнение с php-docx обманчиво](#почему-сравнение-с-php-docx-обманчиво)
4. [Честная оценка scope'а](#честная-оценка-scopeа)
5. [Стратегические варианты](#стратегические-варианты)
6. [Предлагаемый минимальный scope (v0.1)](#предлагаемый-минимальный-scope-v01)
7. [Эскиз архитектуры](#эскиз-архитектуры)
8. [Research-области (deep dive)](#research-области-deep-dive)
9. [План по фазам](#план-по-фазам)
10. [Resolved open questions](#resolved-open-questions)
11. [Источники](#источники)

---

## Зафиксированные решения

(2026-05-13, после прохождения § «Open questions».)

| # | Решение | Последствия |
|---|---|---|
| **1. Стратегия** | **Вариант A — собственный PDF-движок** | 6-9 месяцев solo до v0.1, multi-year long tail, MIT, полный контроль |
| **2. Bundled-шрифты** | **Liberation трио вынесен в отдельный composer-пакет `dskripchenko/php-pdf-fonts-liberation`** | OFL-зона изолирована от MIT-зоны host-библиотеки; основной php-pdf по умолчанию использует только PDF base-14 (Helvetica/Times/Courier, Latin-1 only); caller через FontProvider API подключает Liberation или свои TTF/OTF. Метрики Liberation совпадают с Arial/Times/Courier — важно для DOCX-input совместимости |
| **3. PDF target version** | **PDF 1.7** | де-факто стандарт с 2008, поддержка во всех viewer'ах |
| **4. Typography floor** | **Kerning + basic ligatures (fi, fl, ffi) в v0.1** | +3-4 недели в Phase 2 (font subset embedding); качество близко к mpdf на латинице |
| **5. AST / Converter** | **Дублируем `Html\Converter` + Element/Style namespace в php-pdf** | Нет cross-package coupling, обе библиотеки self-contained; maintenance — синхронизация багфиксов AST вручную (lock-step versioning рекомендуется) |
| **6. Color management** | **sRGB-only в v0.1**, ICC profiles → Phase L | Print-form use case не требует CMYK; экономит ~2 недели и edge-case'и |
| **7. Maintenance** | **Solo**, без помощи team | Сроки PLAN.md ×1.5; пост-v0.1 long tail на одном человеке |

Эти решения отражены в текстах ниже (scope, архитектура, фазы).

---

## Зачем своя библиотека

`mpdf/mpdf` — де-факто PHP HTML→PDF движок, но лицензирован под
**GPL-2.0-only** (strong copyleft). Практические ограничения для наших
деплоев:

- ✅ Internal SaaS / hosted operation — GPL v2 не триггерит distribution
  clause для hosted-сервисов
- ❌ On-premise deployment / customer-installable product
- ❌ Embedding в closed-source SDK / OEM
- ❌ Proprietary licensing бандла с mpdf внутри

Clean-room MIT-замена снимает эти ограничения и переносит maintenance
ownership на нас (та же модель, что у `dskripchenko/php-docx`).

---

## Почему сравнение с php-docx обманчиво

| | php-docx | php-pdf |
|---|---|---|
| Что мы эмитим | OOXML markup (XML) | Финальный отрендеренный output (positioned text + embedded fonts + image streams) |
| Кто верстает и рендерит | Word / Pages / LibreOffice | **Мы** (библиотека) |
| Размер reference-спеки | ECMA-376 OOXML, ~5000 страниц (но XML-схемы в большинстве прямолинейны) | ISO 32000-2 PDF, ~970 страниц (бинарный формат, content streams — PostScript-подмножество, font-таблицы требуют отдельного парсинга) |
| Размер зрелой open-source реализации | phpword: ~30 kLOC | mpdf: ~150 kLOC |
| Что мы пишем | ~5 kLOC (php-docx 1.0) | реалистичный минимум ~15-25 kLOC |

**Ключевой вывод.** php-docx делегирует самую сложную работу (типографика,
layout, rendering, шрифты) downstream-консьюмерам. У PDF-библиотеки
такой делегации нет — мы сами реализуем typesetting. Это
приблизительно разница между «написать текст в markdown» и
«сверстать книгу для печати».

---

## Честная оценка scope'а

Построить полноценную замену mpdf — задача уровня частичного
typesetting / browser-движка. Это multi-year усилия с длинным хвостом
edge-case'ов (font corner cases, table break-inside, browser-vendor
CSS-поведение, accessibility-теги, PDF/A compliance).

Реалистичный горизонт для minimal-but-useful v0.1:

- **3-4 недели** POC + risk-burn-down
- **4-6 месяцев solo** до production-usable v0.1, покрывающего ~20%
  функционала mpdf (подмножество, релевантное для print-form use case
  printable)
- **multi-year long tail** для паритета с mpdf на произвольном HTML

Это честно. Любая более короткая оценка — это wishcasting.

---

## Стратегические варианты

Прежде чем продолжать инвестировать, нужно выбрать ОДИН.

### Вариант A: Полностью своя PDF-библиотека ✅ ВЫБРАН

Greenfield clean-room реализация. Лицензия: MIT. Оценка: 4-6 месяцев
до v0.1, multi-year long tail. **Риск: высокий** — font handling и
table layout знамениты количеством edge case'ов.

### Вариант B: Миграция на dompdf

Заменить `mpdf/mpdf` на `dompdf/dompdf` (LGPL-2.1). Effort: 1-2 дня.
**LGPL позволяет linking из проприетарного кода** без копилефта на
весь combined work, что решает OEM/on-prem distribution. Tradeoff:
поддержка CSS3 в dompdf хуже, watermark/header/footer API менее
удобный, max-PDF-version 1.4.

### Вариант C: Sidecar (headless Chrome или LibreOffice)

Наш PHP-код — тонкая MIT-обёртка; реальный рендер делает
`chromium --print-to-pdf` или `soffice --convert-to pdf`. Effort:
1-2 недели. License-clean (separate-process делегация, нет linking).
Качество: Chrome ≈ browser-grade (лучший на рынке); LO ≈ высокое.
Cost: ~500 MB binary на сервере (Chrome) или ~250 MB (LO).

### Вариант D: Минимальный custom v0.1 + sidecar-fallback для остального

Реализовать небольшой custom-эмиттер (~3 kLOC) для самых частых
print-form паттернов; для остальных кейсов fallback'ить на sidecar
Chrome/LO. Effort: 2 месяца до MVP. Прагматично, но добавляет
operational complexity.

**Решение зафиксировано: Вариант A.** План строится из расчёта что
мы коммитимся строить сами. Если в ходе R0-POC выяснится что font
handling (R2) непроходим в reasonable timeframe — пересмотрим
к B (dompdf) или C (sidecar Chrome).

---

## Предлагаемый минимальный scope (v0.1)

Что v0.1 ДОЛЖЕН покрыть (чтобы быть useful mpdf-заменой для printable):

| Фича | Заметки |
|---|---|
| Параграфы, заголовки, line-break'и, page-break'и | только inline-styles |
| Inline-runs: bold / italic / underline / strikethrough / sup / sub / color / size / fontFamily | PDF base-14 (Helvetica/Times/Courier/Symbol) baseline; Liberation/custom через FontProvider API из отдельного пакета |
| Hyperlinks (внешние + internal anchors) | + bookmarks для TOC |
| Картинки: PNG, JPEG | data: URLs от caller'а; ext-источники upstream-rasterized |
| Таблицы: rowSpan/colSpan, borders, padding, cell background, percentage widths | break-across-pages OK; complex break-inside-row в long tail |
| Списки: bullet, decimal, letter, roman, nested ≥ 3 уровней | |
| Headers, footers, watermark'и | per-page; first-page / even-page позже |
| Page numbers (PAGE / NUMPAGES fields) | |
| A4 / A3 / A5 / Letter / Legal, portrait + landscape, кастомные margins | |
| Embedded Unicode TTF шрифты | subset-embedding через cmap; ascii-only fast path |
| Kerning (pair-adjustment, GPOS+legacy `kern`) | в v0.1 — даёт качество typography близкое к mpdf |
| Basic ligatures (fi, fl, ffi) через GSUB | в v0.1 для serif-шрифтов |

Что **OUT of v0.1** явно:

- Сложный CSS (flexbox, grid, transforms, gradients, multi-column flow)
- Float'ы и абсолютное позиционирование (только ограниченно)
- SVG (rasterize upstream — printable уже так делает)
- Forms / acroforms
- Encryption / digital signing / PDF/A compliance
- RTL / BiDi (Arabic, Hebrew) — Phase L (long tail)
- Сложный shaping (Indic, Arabic positioning, CJK sub-positioning)
- Hyphenation
- Justified text (только left/right/center на старте)
- Page-break-inside avoidance, orphan/widow control
- Annotations, multimedia, JS actions
- Color management (CMYK / ICC profiles) — только sRGB (Вариант 6 locked)
- Linearization / streaming write

Это подмножество соответствует тому, что printable реально генерит
(типовые печатные формы — в основном таблицы + параграфы + картинки +
header'ы/footer'ы, всё sRGB, Latin/Cyrillic).

---

## Эскиз архитектуры

```
HTML (inline styles)
       │
       ▼  Html\Converter  (DUPLICATED из php-docx — self-contained AST)
   Document (AST)
       │
       ▼  Layout\Engine   (НОВЫЙ — paginate AST в список rendered-страниц)
   list<RenderedPage>     ← каждая page = list<DrawCommand>
       │                    (text-at-xy, image-at-xy, line, rect, ...)
       │
       ▼  Pdf\DocumentWriter  (НОВЫЙ — эмиссия PDF-байтов)
   PDF bytes
```

Слои:

1. **HTML → AST (duplicated из php-docx, namespace `Dskripchenko\PhpPdf\…`).**
   Копируем `Element/`, `Style/`, `Html/Converter` + StyleAppliers из
   php-docx — получаем self-contained AST. Maintenance — синхронизация
   AST-багфиксов вручную между двумя пакетами (lock-step versioning).
   AST-классы — те же по семантике, но distinct PHP-типы. Пакеты можно
   использовать вместе, без cross-coupling.

2. **Layout engine (новый — самая тяжёлая часть).** Принимает AST +
   page setup, выдаёт список `RenderedPage`-объектов, каждый со
   списком low-level draw-команд в абсолютных координатах. Обрабатывает:
   - Text shaping & measurement (per font: glyph widths, kerning)
   - Line breaking & wrapping
   - Paragraph spacing & indentation
   - Table layout: column-width resolution, row-height computation,
     spans, page breaks
   - List numbering
   - Image scaling
   - Headers/footers/watermarks: per-page рендер

3. **PDF emitter (новый — механический, но specific).** Принимает
   draw-команды и эмитит валидный PDF. Обрабатывает:
   - PDF object tree (catalog, page tree, content streams)
   - Cross-reference table & trailer
   - Font dictionary + embedded TTF subset
   - Image XObject streams (Flate-encoded для PNG, DCT для JPEG)
   - Hyperlinks (annotation dictionaries)
   - Bookmarks (outline dictionary)

Reuse AST'а — самый большой scope-выигрыш. Весь HTML-парсинг и
inline-styles cascade «бесплатны» из php-docx.

---

## Research-области (deep dive)

Ниже — области, где у нас **unknown unknowns** до проведения POC'ов.
Каждый пункт требует ресёрча до фиксации в фазе.

### R1. Выбор PDF-формата и target-версии

- PDF 1.4 (Adobe Reader 5+, 2001) — минимальная база, без transparency
  groups, без embedded color profiles
- PDF 1.7 / ISO 32000-1 (2008) — текущий де-факто стандарт, full
  transparency, encryption, AES
- PDF 2.0 / ISO 32000-2 (2017+) — улучшенная компрессия, accessibility
  теги

**Вопрос:** Целиться в 1.7 (самый сбалансированный)? Или 1.4 для max
compatibility? mpdf по умолчанию 1.4.

**Research output:** ADR-PDF-002.

### R2. Font handling (САМАЯ СЛОЖНАЯ ПРОБЛЕМА)

Это единственная самая большая зона риска. Подвопросы:

#### R2a. Откуда брать шрифты
- 14 стандартных PDF base-14 (Helvetica, Times, Courier, Symbol,
  ZapfDingbats × варианты) — гарантированно есть в viewers, но
  покрывают ТОЛЬКО Latin-1 (Cyrillic — нет!)
- Для Cyrillic / Greek / accented Latin мы ОБЯЗАНЫ embed'ить TTF
- Лицензия bundled TTF — должна быть OFL или Apache (DejaVu? Liberation?
  Noto?). Критично для MIT-distribution.

#### R2b. Парсинг TTF
- Таблицы для чтения: `head`, `hhea`, `hmtx`, `cmap`, `name`, `OS/2`,
  `post`, `glyf`, `loca`, `maxp`, опционально `kern`/`GPOS`/`GSUB`
- Subset generation: определить используемые glyph'ы, написать
  минимальный `glyf`/`loca` с перенумерованными glyph ID
- CMap construction: cid-to-gid (PDF'у нужен mapping character-code →
  glyph) + ToUnicode (для copy-paste из PDF-reader'а)

#### R2c. Embedding-стратегии
- Fully-embedded (~500 KB на шрифт, быстро)
- Subset-embedded (~5-50 KB в зависимости от контента, наш target)
- Reference-only (шрифт предполагается установленным у viewer'а —
  НЕ делать, ненадёжно)

#### R2d. Что НЕ покрываем text-features в v0.1
- BiDi / RTL
- Сложный shaping (Arabic, Indic, CJK с sub-positioning)

#### R2e. Что ВКЛЮЧАЕМ в v0.1 (решение #4)
- Kerning через GPOS pair-adjustment + fallback на legacy `kern` table
- Basic ligatures (fi, fl, ffi) через GSUB lookup (только default
  Latin script feature `liga`, не `dlig` и не `hlig`)

**Оценочный effort:** 6-9 недель только на шрифты (раньше было 4-6,
добавили kerning+ligatures). Самая сложная single-область.

**Research outputs:**
- POC-R2.a: emit'ить 1-страничный PDF с embedded DejaVu Sans Regular
  subset (5 glyph'ов)
- POC-R2.b: то же с Cyrillic glyph'ами (а-я + accents)
- POC-R2.c: проверить корректность copy-paste в Acrobat / preview /
  evince / Foxit

### R3. Layout engine — text wrapping и line breaking

- Greedy: O(n), даёт ragged-right (хорошо для v0.1)
- Knuth-Plass: O(n²), даёт красивый justified text (out of v0.1)
- Hyphenation: pyphen / TeX-style — out of v0.1
- Soft-hyphens (U+00AD) — прямолинейно honor'ить

**Research output:** POC-R3.a — wrapнуть 500-символьный параграф в
4 разных шрифтах; сравнить визуально с mpdf-reference.

### R4. Layout engine — таблицы

Вторая по сложности область после шрифтов. Подвопросы:

- Column-width resolution: explicit → percentage → auto (content-based)
- Row-height: line-count × line-height + padding + border
- vMerge/rowSpan с page-break'ами: если merged-cell пересекает границу
  страниц, как рендерить продолжение? mpdf и Word расходятся. CSS-спека
  (CSS-tables) неоднозначна.
- Cell padding + border: collapsing vs separate border models?
- Header repetition на subsequent pages (mpdf `repeat`)

**Research output:** POC-R4.a — рендер polis-vzr-semya шаблона
3-column header (известно что у нас nested-таблицы ломаются в Pages) —
работает ли наш layout?

### R5. Page-break handling

- Forced page break (`<page-break/>`, `<hr class="page-break">`)
- Soft page break (content overflow)
- Page-break-inside avoidance — out of v0.1
- Orphans / widows — out of v0.1
- Keep-with-next (heading should stay with следующим параграфом) —
  out of v0.1

**Research output:** ADR-PDF-003 — политика page-break.

### R6. Image handling

- PNG decoding: читать width/height из IHDR, decompress IDAT через zlib,
  re-encode как Flate-compressed PDF image XObject
- JPEG: pass-through как DCT-encoded XObject (без re-encoding!)
- Transparency: PNG alpha → PDF SMask (отдельный grayscale image)
- ICC profile: strip или pass through?

**Research output:** POC-R6.a — embed PNG с alpha и JPEG; verify
рендер в Acrobat + preview.

### R7. Headers, footers, watermark'и

- mpdf-модель: HTML fragment через `SetHTMLHeader()`, рендер per-page
  сверху
- Наш подход: рендерить header/footer как отдельный `RenderedPage`
  fragment, blit на каждую страницу
- Watermark: VML-rotated text shape (DOCX-style) или просто текст с
  rotation matrix в content stream? Второе — более PDF-native.

**Research output:** ADR-PDF-004 — стратегия header/footer/watermark.

### R8. Hyperlinks & bookmarks

- External link: Annotation Dictionary с URI Action
- Internal link: Annotation Dictionary с GoTo Action, указывающим на
  named destination
- Bookmark (outline): top-level dict с reference на destinations
- Tab order, accessibility tags — out of v0.1

**Оценочный effort:** 1 неделя.

### R9. PDF content streams

- PDF content streams — PostScript-подмножество:
  - `BT/ET` — begin/end text object
  - `Tf` — set font + size
  - `Td/TD/Tm` — text position
  - `Tj/TJ` — show text
  - `m/l/c/h/S/f/B` — path операторы (линии, прямоугольники)
  - `q/Q` — push/pop graphics state
  - `cm` — set CTM (transform)
  - `Do` — invoke XObject (image)
- Содержимое stream'а **сжато** (Flate) и **indirect**-ссылается из
  page object

**Research output:** POC-R9.a — emit 1-страничный PDF с
форматированным текстом + 1 rectangle + 1 line. Target ~200 LOC.

### R10. Производительность / память

- Большие документы: 100+ страниц с картинками — mpdf может OOM'ить
- Streaming write — эмитить страницы по мере рендера, не держать всё
  в памяти
- Cross-reference table нужна в конце (можно писать её после
  streaming-pages, как только знаем offsets)

**Оценочный effort:** Phase L (long tail).

---

## План по фазам

### Phase R0: Risk burn-down POC'и (3-4 недели)

Построить минимальные POC'и чтобы валидировать самые сложные unknowns
ДО коммита на full implementation. Pass-criteria каждого — бинарный:
«можно ли это в принципе?». Если R2 (шрифты) не проходит — пересматриваем
стратегический вариант.

- [ ] POC-R9.a — emit «Hello world» PDF, 1 page, Times-Roman из
      Adobe core 14
- [ ] POC-R2.a — embed subsetted DejaVu Sans (5 glyph'ов); открыть
      в Acrobat
- [ ] POC-R2.b — embed Cyrillic subset (а-я)
- [ ] POC-R2.c — проверить copy-paste в 3 viewer'ах (preview, Acrobat,
      evince/Foxit)
- [ ] POC-R6.a — embed PNG + JPEG; verify рендер
- [ ] POC-R3.a — wrap 500-символьный параграф, 2 шрифта × 2 размера
- [ ] POC-R8.a — emit hyperlinks (external + internal anchor)
- [ ] POC-R5.a — single forced page break + soft page break
      (content overflow)
- [ ] POC-R4.a — рендер polis-vzr-semya 3-column header

**Acceptance:** все POC'и проходят, мы пишем ADR-PDF-001 с go/no-go.

### Phase 1: PDF skeleton + стандартные шрифты (2-3 недели)

После успешного R0. Output: минимальные text-only PDF.

- `Pdf\Document` / `Pdf\Page` / `Pdf\Stream` value-objects
- Эмиссия catalog / page tree / cross-reference table
- Adobe core 14 шрифты as referenced (без embedding)
- Plain text rendering с абсолютным позиционированием
- One paragraph per page, fixed layout

**Acceptance:** можем эмитить 1-страничный «lorem ipsum» PDF в
Times-Roman 12pt.

### Phase 2: TTF subset embedding + typography (6-9 недель)

Самая сложная фаза. Output: произвольные Unicode-символы рендерятся
корректно, типография — kerning + basic ligatures.

- TTF table parser (head/hhea/hmtx/cmap/name/OS-2/post/glyf/loca/maxp)
- Парсинг GPOS (для kerning pair-adjustment), fallback на legacy `kern`
- Парсинг GSUB lookup type 4 (single-/multi-substitution для ligatures —
  `liga` feature, latin script, dflt language)
- Subset generation
- CMap (cid-to-gid) + ToUnicode CMap эмиссия
- Font dictionary с Type0 composite font + CIDFontType2 descendant
- **FontProvider interface** (контракт для bundled и caller-provided)
  + `BuiltinFontProvider` (только PDF base-14, Latin-1 only) +
  `ChainedFontProvider` (compose нескольких providers)
- **Опциональный пакет** `dskripchenko/php-pdf-fonts-liberation` —
  через composer suggests, не required. 12 TTF + `LiberationFontProvider`
  implementing the interface + Microsoft-имена alias
  (Arial → LiberationSans и т.д.)

**Acceptance:**
- Произвольный Cyrillic + accented Latin текст с copy-paste-
  correctness в 3 viewer'ах
- Kerning pair AV (visually closer) применяется
- «fi» в Liberation Serif рендерится как одна glyph-ligature

### Phase 3: Layout engine — параграфы (3 недели)

Output: HTML→PDF для простейшего случая (параграфы + заголовки +
line-break'и).

- AST walker → layout engine
- Text shaping & measurement
- Line breaking (greedy)
- Paragraph spacing + alignment (left/right/center; no justify yet)
- Page overflow

**Acceptance:** рендер 5-страничной Wikipedia-статьи из HTML.

### Phase 4: Картинки (2 недели)

- PNG decoding & re-encoding как Flate XObject
- PNG с alpha → SMask
- JPEG pass-through
- Image позиционирование + scaling

**Acceptance:** рендер документа с 5 mixed-format картинками.

### Phase 5: Таблицы (4-6 недель)

- Column width resolution
- Row height computation
- gridSpan / rowSpan
- Cell padding, borders, background
- Table break-across-pages (без break-inside-row в v0.1)

**Acceptance:** рендер polis-vzr-semya шаблона эквивалентно mpdf
(visual diff ≤ 5%).

### Phase 6: Списки (1 неделя)

- Bullet / numbered с произвольным nesting
- Numbering формы (decimal / letter / roman)
- ListFormat enum уже есть из php-docx

**Acceptance:** рендер вложенных mixed-списков, 3 уровня глубины.

### Phase 7: Hyperlinks + bookmarks (1 неделя)

- External link annotations
- Internal anchor + destination
- Outline tree (bookmark side-panel в PDF reader'ах)

### Phase 8: Header'ы / footer'ы / watermark'и (2 недели)

- Per-page рендер
- Field codes (PAGE / NUMPAGES) substitution
- Watermark с rotation + opacity

### Phase 9: Page setup + advanced (2 недели)

- A4 / A3 / A5 / Letter / Legal
- Portrait + Landscape
- Custom margins
- First-page / even-page header варианты (если не сделаны в Phase 8)

### Phase 10: Интеграция в printable (2 недели)

- Заменить `App\Render\Emitter\PdfEmitter` на использование
  `dskripchenko/php-pdf`
- A/B switch через `config/admin.php` `pdf.driver` (mpdf | php-pdf)
- Reference corpus: рендер всех printable-шаблонов обоими движками,
  visual-diff threshold ≤ 5% pixel
- За feature-flag'ом до подтверждения parity

### Phase L: Long tail (бессрочно)

- Justified text
- Hyphenation
- Page-break-inside avoidance + orphan/widow control
- Более сложные таблицы (break-inside-row и т.д.)
- CMYK + ICC profile support
- RTL / BiDi
- Acroforms (только если будет реальный спрос)
- Digital signing (только если будет реальный спрос)
- PDF/A compliance (только если будет реальный спрос)

---

## Resolved open questions

Все 7 вопросов закрыты — см. § «Зафиксированные решения» в начале
документа. Решения здесь продублированы для исторического трейлинга
(чтобы видно было кто и когда что обсудил):

1. ✅ **Strategy** → Вариант A (own engine). 2026-05-13.
2. ✅ **Fonts** → Liberation Sans/Serif/Mono bundled + FontProvider API. 2026-05-13.
3. ✅ **PDF version** → 1.7. 2026-05-13.
4. ✅ **Typography** → Kerning + basic ligatures в v0.1 (fi/fl/ffi). 2026-05-13.
5. ✅ **AST/Converter** → Duplicate в php-pdf, lock-step versioning с php-docx. 2026-05-13.
6. ✅ **Color management** → sRGB-only v0.1, ICC в Phase L. 2026-05-13.
7. ✅ **Maintenance** → Solo, сроки ×1.5. 2026-05-13.

---

## Источники

Спецификации и reverse-engineering материалы для чтения:

- **ISO 32000-2** (PDF 2.0) — спека. 970 страниц. Доступна бесплатно
  от ISO.
- **Adobe PDF Reference 1.7** — старее, но более widely-доступная PDF
  спецификации 1.7.
- **TrueType spec** (Apple TT 1.66, OpenType OTSpec 1.9) — для font
  таблиц.
- **mpdf source code** — для чтения ТОЛЬКО, никогда не копировать.
  Лицензия блокирует code-reuse.
- **PDFBox** (Apache 2.0) — Java PDF библиотека; license-compatible
  для чтения reference-поведения.
- **TCPDF** (LGPL-3) — read-only reference; нельзя копировать код.
- **iText 7 community** (AGPL) — НЕ ЧИТАТЬ во избежание license-
  контаминации.

Замечание про clean-room: разработчики, читающие mpdf или iText для
**reference**, НЕ должны затем писать код, похожий на эти проекты.
Реализовывать только из спеки. Если хотим перестраховаться —
ограничиться spec-чтением и PDFBox (Apache).
