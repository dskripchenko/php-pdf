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
- **DataMatrix 144×144** — special interleaved layout (different placement
  algorithm от standard square sizes).
- **Aztec Rune mode** — single-character symbol variant, 11×11 fixed format.
  Различает orientation marks от regular Aztec symbols.
- **Aztec Structured Append / FLG(n) ECI** — needs Aztec encoder internals
  refactor для multi-symbol concatenation.

### Multi-day / multi-week refactors

- **CIDFont vertical writing (full)** — Type 0 + UniJIS-UTF16-V CMap.
  Adobe-Japan1 CMap data bundle (~50KB) + Type 0 composite fonts.
  *Partial progress:* vmtx parser + /WMode 1 emitter готовы (Phase 192/194).
- **CFF2 variable fonts** — full CFF Type 2 interpreter (CharString
  operators, blend operator, Item Variation Store integration, CIDKeyed
  CFFs). Scope similar к Phase 131-134 для glyf-based variable fonts.
- **Public-key encryption** (`/Filter /PubSec`) — X.509 certificate-based
  access control. Significant Encryption class refactor.
- **Footnote true page-bottom positioning** — per-page reserved zone
  (currently inline at end of body). Multi-pass layout architecture needed.
- **LineBreaker Knuth-Plass optimal** — boxes-glues-penalties с backtracking
  для typographically optimal paragraph breaking.
- **Per-object content stream incremental emission** — currently each Page
  content stream materializes fully before emission. Deeper API rewrite
  для streaming memory efficiency.

### Output optimization (bonus, not critical)

- **Cross-Writer font subset dedup** — batch scenario only. Нужен LRU cache
  + invalidation strategy. ~10-15% saving для multi-doc batches.
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
