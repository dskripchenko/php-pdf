# dskripchenko/php-pdf — Roadmap

Pure-PHP, MIT-licensed PDF renderer. Цель — drop-in замена `mpdf/mpdf`
(GPL-2.0) в production-стеке printable-приложения с feature parity на
типичных бизнес-документах (договоры, акты, счета, отчёты).

**Текущий статус:** v1.5.0 — 213+ phases closed, 1683 тестов, all passing.
Production-ready. Active backlog: v1.6+ scope items.

Историю закрытых фаз см. в [CHANGELOG.md](CHANGELOG.md).

---

## v1.6+ — Open backlog

### Bounded items (1-3 дня каждый, требуют verified spec source)

- **QR V11-V40 large versions** — ~120 ECC_PARAMS entries + extended
  ALIGN_POSITIONS data tables. Risk: numerical entry без real QR decoder
  для validation. Нужен ZXing source или ISO 18004 Annex D PDF.
- ~~DataMatrix 144×144~~ ✅ **Phase 237 closed**. Added к SYMBOLS table
  с explicit blockCountOverride (10 blocks) — round-robin interleaving
  naturally handles uneven distribution (8×156 + 2×155 data per block).
  Existing 36-region infrastructure used as-is.
- **Aztec Rune mode** — single-character symbol variant, 11×11 fixed format.
  Attempted Phase 239 but declined: 28-bit mode message bit placement
  в 11×11 matrix is critical unknown without verified spec source.
  Wrong placement = symbol generates но не decodes на real readers.
  Unlike FLG (encoder-internal), Rune bits go directly в visible output.
- ~~Aztec Structured Append~~ ✅ **Phase 221 closed** (ISO 24778 §8.4 —
  header-prefixed " AB<data>" or "fileID AB<data>" format, up to 26 symbols).
- ~~Aztec FLG(n) ECI escape~~ ✅ **Phase 238 closed**. `AztecEncoder::withEci()`
  factory prepends bit-level FLG(n) header per ISO 24778 §6.5: U→M→P latches,
  Punct code 0 (FLG), 3-bit n length, n digit codewords, P→U latch back.
  Marked spec-risky — production users should validate с real decoder.

### Multi-day / multi-week refactors

- **CIDFont vertical writing (full)** — Type 0 + UniJIS-UTF16-V CMap.
  Adobe-Japan1 CMap data bundle (~50KB) + Type 0 composite fonts.
  *Partial progress:* vmtx parser + /WMode 1 emitter готовы (Phase 192/194).
- **CFF2 variable fonts** — full CFF Type 2 interpreter (CharString
  operators, blend operator, Item Variation Store integration, CIDKeyed
  CFFs). Scope similar к Phase 131-134 для glyf-based variable fonts.
- **Public-key encryption** (`/Filter /PubSec`) — X.509 certificate-based
  access control. Significant Encryption class refactor.
- ~~Footnote true page-bottom positioning~~ ✅ **Phase 222 closed** (opt-in
  via `Section::footnoteBottomReservedPt`). Fixed bottom reservation;
  per-page footnote flush at page break. Endnote mode остаётся default.
- ~~LineBreaker Knuth-Plass optimal~~ ✅ **Phase 218 closed** (library class).
  `KnuthPlassLineBreaker` доступен как stand-alone utility с same `wrap()`
  interface как greedy `LineBreaker`. DP-based optimal break-point search
  + graceful fallback к greedy для oversized words.
  *Не интегрирован в Engine emitLine()* — это потребует полной replacement
  Engine's inline line-breaking algorithm (substantial refactor с visual
  regression risk). User instantiates manually if needed.
- **Per-object content stream incremental emission** — currently each Page
  content stream materializes fully before emission. Deeper API rewrite
  для streaming memory efficiency.

### Output optimization (bonus, not critical)

- ~~Cross-Writer font subset dedup~~ ✅ **Phase 215 closed**. Static LRU
  cache в TtfSubsetter, keyed по (spl_object_id, sorted glyphs, variation
  axes). Limit 32 entries. Saves subsetting compute time на batch
  scenarios.
- ~~Object Streams (PDF 1.5+)~~ ✅ **Phase 214 closed**. Pack uncompressed
  dict objects в single FlateDecode-compressed stream. ~15-30% output
  reduction beyond XRef streams. Auto-disabled с encryption/signing.
- ~~Document::toStream() top-level convenience~~ ✅ **Phase 216 closed**.
  Streaming output на top-level Document API; `toFile()` refactored к
  use true streaming.
- ~~Top-level Document encryption/signing/PDF-A integration~~ ✅
  **Phase 217 closed**. Declarative configuration via constructor params
  (`EncryptionParams`, `SignatureConfig`, `PdfAConfig`); больше не нужно
  drop'ить в low-level API.
- ~~HTML/CSS parser + `Document::fromHtml()`~~ ✅ **Phase 219 closed**
  (basic HTML5 + inline CSS) + **Phase 224 closed** (block-level CSS:
  text-align, margin/padding, line-height, background-color).
- ~~Balanced Page Tree (PDF spec §7.7.3.3)~~ ✅ **Phase 220 closed**.
  Auto для documents > 32 pages, FANOUT=16 chunks.
- ~~`Document::concat()` multi-document merge~~ ✅ **Phase 223 closed**.
- **Type0 → Type1 Latin re-encoding** — biggest potential output-size win
  (~20-25% per-doc для Latin-heavy text). Major font subsetter refactor.

---

## Конвенция

- **Phase N** — атомарная feature/fix, соответствует одному feat-коммиту.
  Каждый phase должен включать tests, documentation, и быть verifiable
  через test suite.
- **Closed phases** документируются в CHANGELOG.md (с commit hash + brief
  description).
- **Open backlog** держится lean — только items с конкретным scope estimate
  и identified blocker (spec source / time budget / dependency).
- **Bounded** = single phase work (1-3 days). **Multi-day refactor** =
  scope across multiple phases, обычно с upfront design doc в docs/adr/.

### Когда что-то добавлять сюда:
- Новый gap обнаружен во время работы — фиксировать в ROADMAP если scope ≥
  1 day. Меньшие fixes идут прямо в commits без roadmap entry.
- Critical bugs для production switch — добавлять с пометкой "blocking"
  и приоритизировать.
- Nice-to-have / extension features — в backlog по приоритету (impact ÷
  effort).
