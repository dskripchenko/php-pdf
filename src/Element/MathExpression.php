<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 69: Math expression — minimal LaTeX-like subset.
 *
 * Supported syntax:
 *  - `a^{b}` или `a^b` — superscript.
 *  - `a_{b}` или `a_b` — subscript.
 *  - `\frac{n}{d}` — fraction (numerator over denominator).
 *  - `\sqrt{x}` — square root.
 *  - Greek letters: `\alpha`, `\beta`, `\gamma`, `\delta`, `\pi`, `\theta`,
 *    `\Sigma`, `\Omega` и т.д. → Unicode chars.
 *  - Operators: `\cdot`, `\times`, `\div`, `\pm`, `\leq`, `\geq`,
 *    `\neq`, `\approx`, `\infty`, `\sum`, `\int`.
 *
 * Не реализовано:
 *  - Custom font / styling — v1.3 backlog.
 *  - LaTeX environments (begin{} / end{}) — v1.3 backlog.
 *
 * Closed в later phases:
 *  - Multi-line equations → Phase 96
 *  - Matrices / arrays (matrix, pmatrix, bmatrix, vmatrix) → Phase 75
 *  - Big operators с limits → Phase 80
 *  - Nested fractions внутри superscripts → Phase 172 (was already
 *    working through recursive render of frac node within sup arg)
 *
 * Rendered как block с centered alignment по умолчанию. Font derived
 * from default Engine font; sup/sub use 70% size + baseline shift
 * (consistent с Phase 26 inline sup/sub).
 */
final readonly class MathExpression implements BlockElement
{
    public function __construct(
        public string $tex,
        public float $fontSizePt = 12.0,
        public Alignment $alignment = Alignment::Center,
        public float $spaceBeforePt = 4.0,
        public float $spaceAfterPt = 4.0,
        // Phase 173: custom font family. null = use Engine default font.
        // String = font family name resolved через FontProvider.
        public ?string $fontFamily = null,
    ) {
        if ($tex === '') {
            throw new \InvalidArgumentException('MathExpression requires non-empty TeX input');
        }
    }
}
