# dskripchenko/php-pdf

Pure-PHP PDF generator — **work in progress, research stage**.

Long-term goal: MIT-licensed replacement for `mpdf/mpdf` (GPL-2.0) in
the printable rendering stack, mirroring the
[`dskripchenko/php-docx`](https://github.com/dskripchenko/php-docx)
architecture (HTML → AST → renderer).

## Status

**Research / planning.** No production code yet. See [PLAN.md](PLAN.md)
for the architecture proposal, research questions, and realistic phase
breakdown.

## Why a custom library

`mpdf/mpdf` is licensed under GPL-2.0-only. For SaaS / internal-use
deployments this is fine, but distribution scenarios (OEM, on-premise
installer, proprietary product bundle) become legally constrained.
A clean-room MIT implementation removes the friction.

## Why this is hard

Unlike DOCX (where Word/Pages/LibreOffice do the actual rendering and
we just emit valid XML), PDF stores **finalized rendered output**: text
positioned to the typographic glyph, fonts embedded, images streamed.
Building a PDF library is roughly building a partial typesetting
engine — multiple orders of magnitude more involved than php-docx.

See [PLAN.md](PLAN.md) § "Why the comparison to php-docx is misleading".

## License

MIT — see [LICENSE](LICENSE) (when added).
