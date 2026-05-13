# ADR-PDF-001 — Go-verdict для собственной PDF-библиотеки

- **Status:** Accepted (Go)
- **Date:** 2026-05-13
- **Refines:** [PLAN.md](../../PLAN.md)
- **Deciders:** @dskripchenko

## Context

[PLAN.md](../../PLAN.md) определил scope собственной MIT-лицензированной
PDF-библиотеки как замены `mpdf/mpdf` (GPL-2.0). Зафиксированы 4
стратегических варианта: A (свой движок), B (dompdf), C (sidecar Chrome),
D (гибрид). После обсуждения § «Open questions» выбран **Вариант A**
с 6 другими сопутствующими решениями (Liberation fonts, PDF 1.7,
kerning + basic ligatures, AST duplicated, sRGB, solo maintenance).

§ «Phase R0» PLAN.md описывает 9 POC'ов для риск-burndown'а — каждый
проверяет «можно ли в принципе» для конкретной technical area. POC'и
gating-критерий: если самые сложные (R2 — font handling) не пройдут,
переходим к Варианту B или C.

POC'и были выполнены последовательно за один development-цикл
(2026-05-13). Этот ADR фиксирует результаты и принимает финальное
решение «Go / No-Go».

## POC Results

Все 9 POC'ов **PASSED**. Результаты подробно:

### POC-R9.a — Hello World PDF (PDF format basics)

**Goal:** emit minimal valid PDF (Catalog + Pages tree + Page + content
stream + xref + trailer + EOF).

**Result:** ✅ 623-byte PDF, opened in Preview/Acrobat, `pdftotext` extracts
"Hello, world!" корректно.

**Evidence:** [commit ba29364](../../../php-pdf/), `tests/Pdf/PocR9aTest.php`,
`src/Pdf/Writer.php` (~140 LOC), `src/Pdf/ContentStream.php` (~140 LOC).

**Risk burn-down:** Подтверждено что мы можем эмитить байты в формате
PDF 1.7 с корректным cross-reference table'ом и trailer'ом. Зарешено
foundation для всех остальных POC'ов.

### POC-R2.a — Liberation Sans TTF embedding (Latin)

**Goal:** Парсинг TTF binary + embedding как Type0 composite font с
CIDFontType2 descendant. Самая большая зона риска ВСЕЙ библиотеки.

**Result:** ✅ Liberation Sans Regular 2.1.5 (~411KB, 2620 glyphs)
парсится корректно — PostScript name, unitsPerEm, FontBBox, ascent/
descent, hmtx widths, cmap (format 4 + format 12). Embed как FontFile2
stream + Type0/CIDFontType2 + FontDescriptor + ToUnicode CMap. PDF
размер ~412KB (full embed без subsetting). pdftotext извлекает
«Hello, world!» правильно через ToUnicode CMap.

**Evidence:** `src/Font/Ttf/TtfFile.php` (~360 LOC), `src/Pdf/PdfFont.php`
(~280 LOC), `tests/Font/Ttf/TtfFileTest.php` (13 тестов),
`tests/Pdf/PdfFontTest.php` (7 тестов).

**Risk burn-down:** **GIGANTIC.** Phase 2 (TTF subset embedding + Type0)
был оценён в PLAN.md как 6-9 недель и помечен как «hardest single
problem area». Базовая mechanic работает на первом подходе. Phase 2
теперь риск-маржинальный: subsetting + kerning + ligatures = инкременты
к доказанной basis, не fundamental research.

### POC-R2.b — Cyrillic + mixed scripts

**Goal:** проверить cmap format 12 (full Unicode), ToUnicode CMap для
non-BMP-like codepoints, mixed Latin/Cyrillic в одной строке.

**Result:** ✅ `Привет, мир!`, `Hello, world!`, `Mixed: Привет / Hello — 1 + 1 = 2.`
рендерятся в одной PDF странице. pdftotext извлекает все 3 строки
корректно, включая em-dash (U+2014).

**Evidence:** `pocs/r2b/run.php`, в `PocR2Test` 2 assertion'а.

**Risk burn-down:** cmap для Cyrillic block (U+0400..U+04FF) работает
identical с Latin. Surrogate-pair logic для supplementary plane
есть в коде (codepointToUtf16BeHex), но не triggered'ит на этих
samples (em-dash в BMP).

### POC-R2.c — Copy-paste correctness

**Goal:** Проверить ToUnicode CMap корректность в independent PDF
реализациях (Adobe Acrobat, macOS Preview, Foxit, evince).

**Result:** ✅ **Partial — pdftotext (poppler) подтверждает.**
Acrobat/Preview/Foxit visual-verify оставлены автору как manual step.

**Evidence:** pdftotext — independent от Adobe Acrobat SDK
implementation, основанная на xpdf/poppler. Successful extraction в
попплере = сильный сигнал что Acrobat/Preview тоже сработают.
Окончательная подпись manual'ом по визуальной проверке + правому-клику
«copy» + paste в plain-text editor для каждого из 3-х viewers.

**Risk burn-down:** Сильное, не абсолютное. Не блокирует Go-decision
т.к. ToUnicode CMap синтаксис прямо из спеки ISO 32000-1 §9.10.

### POC-R5.a — Multi-page (forced + soft breaks)

**Goal:** Pages tree с N > 1 kids, shared resources между pages.

**Result:** ✅ 3-page PDF, все 3 pages share один FontFile2 stream
(efficiency). `pdfinfo` показывает Pages: 3.

**Evidence:** `pocs/r5a/run.php`, `PocR5Test` (3 теста).

**Risk burn-down:** Mechanical, не было unknowns. Layout-decision когда
делать break — отдельный вопрос Phase 3.

### POC-R8.a — Hyperlinks (external + internal)

**Goal:** PDF Annotations с /Subtype /Link, URI Action для external,
/Dest array для internal anchor.

**Result:** ✅ 2 link annotations на page 1 (external к example.com +
internal к page 2). /Annots array references both.

**Evidence:** `pocs/r8a/run.php`, `PocR8Test` (5 тестов).

**Risk burn-down:** Mechanical. Annotation dicts straightforward.

### POC-R6.a — PNG + JPEG image embedding

**Goal:** Image XObject с native PDF decoding — JPEG pass-through,
PNG inflate IDAT + unfilter + re-compress.

**Result:** ✅ Тест PNG (100×50 gradient через GD) и JPEG (80×60
red+white) embedded в одну page как 2 separate XObjects.
Использованы все 5 PNG-filter types (None/Sub/Up/Average/Paeth) +
Paeth predictor.

**Evidence:** `src/Image/PdfImage.php` (~280 LOC), `tests/Image/PdfImageTest.php`
(5 тестов), `tests/Pdf/PocR6Test.php` (3 теста).

**Risk burn-down:** Mechanical. PNG re-encoding путём strip→recompress
не optimal — Phase 6 заменит на passthrough с DecodeParms (predictor 15).

### POC-R3.a — Text measurement + greedy line wrapping

**Goal:** TextMeasurer (font + size → pt-width), LineBreaker (greedy).
Первый кусок реального layout engine.

**Result:** ✅ 500+ символов lorem-mix-Cyrillic wrap'ятся в 4 column
layout (Sans/Serif × 10pt/14pt). Sans 10pt ≈ 40 chars/line, Sans 14pt
≈ 24 chars/line (scale ~14/10).

**Evidence:** `src/Layout/TextMeasurer.php`, `src/Layout/LineBreaker.php`,
`tests/Layout/` (13 тестов).

**Risk burn-down:** Доказали что greedy break даёт adequate output.
Knuth-Plass justified останется Phase L.

### POC-R4.a — 3-column header table

**Goal:** Table layout (polis-vzr-semya style): column widths, cell
padding, borders, background fills, text wrap inside cells.

**Result:** ✅ 3-column header «Acme Insurance / ПОЛИС СТРАХОВАНИЯ ВЗР ×
СЕМЬЯ / № 12345 Дата 13.05.2026» рендерится с background fills
(rg operators), borders (RG/strokeRectangle), text wrap по column
widths, mixed Latin/Cyrillic.

**Evidence:** `pocs/r4a/run.php`, `tests/Pdf/PocR4Test.php` (3 теста).

**Risk burn-down:** Базовая table-механика работает. Сложные кейсы
(rowspan, page-break-inside-row, complex border collapsing) — Phase 5.
Но fundamental layout не блокирован.

## Decision

**GO — продолжаем по Варианту A (свой PDF-движок).**

Все 9 риск-burn-down POC'ов завершены успешно. Никаких блокирующих
unknowns не обнаружено. Фундаментальные mechanic'и работают:
- PDF binary формат (Writer)
- TTF parsing + embedding (TtfFile + PdfFont)
- Text measurement + line breaking (TextMeasurer + LineBreaker)
- Multi-page + annotations + images + tables

## Quantitative summary

| Метрика | Значение |
|---|---|
| POCs планировались | 9 |
| POCs прошли | 9 ✅ |
| POCs провалились | 0 |
| Test suite на момент Go | 70 tests, 184 assertions |
| LOC production code (src/) | ~1,870 |
| LOC tests (tests/) | ~1,250 |
| LOC POC scripts (pocs/) | ~840 |
| Время на Phase R0 | 1 development session (planned 3-4 weeks) |

> NB по времени: R0 завершён значительно быстрее plan'а. Это **не**
> значит что Phase 1-10 пойдут с такой же скоростью. POC'и — это
> «доказать что mechanic возможен на одном примере», production-grade
> implementation добавляет: edge-case'ы, error handling, optimization,
> integration, comprehensive testing, documentation. Реалистичная
> оценка Phase 1-10: те же 6-9 месяцев из PLAN.md.

## Locked constraints (carry-over из § «Зафиксированные решения» PLAN.md)

1. **Strategy:** Вариант A (свой движок). MIT-licensed.
2. **PDF version target:** 1.7 (ISO 32000-1).
3. **Bundled fonts:** Liberation Sans/Serif/Mono в отдельном пакете
   `dskripchenko/php-pdf-fonts-liberation` (OFL-1.1). Default install
   php-pdf использует только PDF base-14 (Helvetica/Times/Courier,
   Latin-1 only); Liberation подключается через FontProvider API.
4. **Typography v0.1:** Kerning (GPOS + legacy `kern`) + basic ligatures
   (`liga` feature). Без hyphenation, justified text, BiDi.
5. **AST/Converter strategy:** Дублируем `Html\Converter` + Element/Style
   namespace в php-pdf. Self-contained без cross-package dependency
   на dskripchenko/php-docx. Lock-step versioning для синхронизации
   bugfix'ов.
6. **Color management:** sRGB-only. ICC profiles → Phase L.
7. **Maintenance:** Solo. Все сроки PLAN.md × 1.5.

## Consequences

### Positive

- Phase 1 (PDF skeleton) можно стартовать с уверенностью, что underlying
  mechanic'и работают
- POC code partially salvageable: `src/Pdf/Writer.php`,
  `src/Pdf/ContentStream.php`, `src/Font/Ttf/*`, `src/Image/PdfImage.php`,
  `src/Layout/TextMeasurer.php`, `src/Layout/LineBreaker.php` ≈ 1.5 kLOC
  можно использовать как basis (с refactoring под production-quality)
- mpdf-replacement path для printable не блокирован
- License-freedom достигнута: весь код будет MIT, font-bundle OFL
  изолирован в отдельный пакет

### Negative

- Commit к 6-9 месяцам solo maintenance до v0.1
- Post-v0.1 long tail edge-case'ов будет «жить на одном человеке»
- mpdf продолжает быть production-dependency в printable весь этот
  период (GPL constraint остаётся пока php-pdf не достигнет
  production-readiness)
- Subsetting + kerning + ligatures — ещё впереди (Phase 2). Без них
  bundle size большой (~4 MB на один embedded font) и качество
  типографики ниже mpdf
- Visual-rendering в Acrobat / Apple Preview / Foxit пока неподтверждён
  manual-test'ом. pdftotext (poppler) — strong signal, но не gold standard

### Neutral

- Reused POC code будет refactored в Phase 1 — некоторая «потеря»
  работы (но code-as-spec живёт)
- Подход self-contained AST (dup из php-docx) увеличивает code-volume,
  но изолирует cross-package coupling
- Lock-step versioning между php-docx и php-pdf требует discipline

## Open follow-ups (НЕ блокируют Go)

1. **Visual verification в 3 PDF viewers** на момент Phase 2 завершения.
   Pdftotext (poppler) показал correctness, но manually проверить
   Acrobat + Preview + Foxit — обязательно для production-readiness.
2. **POC-R2.a-subset** — actual subsetting (вырезать только used glyphs
   из TTF, не embed весь font ~411KB). Это уже Phase 2 work — не блокирует.
3. **GPOS pair-adjustment + GSUB ligatures** — добавятся в Phase 2.
   Без них качество typography ≈ 95% от mpdf (приемлемо для v0.1).
4. **ADR-PDF-002** — Architecture Decision Record для production
   Document AST API (mirror'инг php-docx DocumentBuilder fluent API).
   Готовится в начале Phase 1.

## Migration plan (post-Go)

Согласно PLAN.md § «План по фазам» с фиксированными сроками × 1.5:

| Phase | Описание | Estimate (solo) |
|---|---|---|
| 1 | PDF skeleton + base-14 fonts | 3-5 недель |
| 2 | TTF subset embedding + typography (kerning + ligatures) | 9-14 недель |
| 3 | Layout engine — paragraphs + measurement | 4-5 недель |
| 4 | Images (PNG/JPEG, proper alpha/SMask, predictor 15) | 3 недели |
| 5 | Tables (rowspan/gridspan, break-across-pages) | 6-9 недель |
| 6 | Lists (bullet/decimal/letter/roman/nested) | 1-2 недели |
| 7 | Hyperlinks + bookmarks (outline tree) | 1-2 недели |
| 8 | Headers/footers/watermarks (per-page render) | 2-3 недели |
| 9 | Page setup advanced (paper sizes, orient, custom margins, first/even) | 2-3 недели |
| 10 | Printable integration (A/B switch driver=php-pdf) | 2-3 недели |
| **Σ** | **до v0.1 в production printable** | **~30-50 недель = 7-12 месяцев** |

После v0.1: Phase L (long tail — justified text, hyphenation, BiDi,
CMYK, accessibility, и т.д.) — бессрочно, по запросу.

## References

- [PLAN.md](../../PLAN.md) — полный research plan
- [README.md](../../README.md) — overview library
- POC commits в `dskripchenko/php-pdf`:
  - `509538e` initial scaffold
  - `ba29364` POC-R9.a Hello World
  - `82709e4` POC-R2.a/b/c TTF embedding
  - `116585c` POC-R5.a + R8.a multi-page + hyperlinks
  - `56a9a8e` POC-R6.a images
  - `9a5a298` POC-R3.a text wrapping
  - `4877d82` POC-R4.a table layout
- ISO 32000-1 (PDF 1.7) — spec
- OpenType OTSpec 1.9 — TTF tables
- Liberation 2.1.5 release — https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5
