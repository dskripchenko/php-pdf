<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Page margins в pt (top/right/bottom/left).
 *
 * Defaults — 20mm = ~56.7pt (типичный print margin).
 */
final readonly class PageMargins
{
    public function __construct(
        public float $topPt = 56.7,
        public float $rightPt = 56.7,
        public float $bottomPt = 56.7,
        public float $leftPt = 56.7,
        /**
         * Mirrored margins для two-sided printing:
         * на even pages left/right swap'ятся (outer/inner side).
         */
        public bool $mirrored = false,
        /**
         * Gutter — дополнительный margin на binding side. На odd pages
         * добавляется к leftPt, на even pages (если mirrored) — к rightPt.
         */
        public float $gutterPt = 0,
    ) {}

    /**
     * Все 4 margin'а одинаковые.
     */
    public static function all(float $pt): self
    {
        return new self($pt, $pt, $pt, $pt);
    }

    /**
     * Convenience: margins в mm.
     */
    public static function fromMm(
        float $topMm = 20,
        float $rightMm = 20,
        float $bottomMm = 20,
        float $leftMm = 20,
    ): self {
        $toPt = static fn (float $mm): float => $mm * 2.83464567;

        return new self($toPt($topMm), $toPt($rightMm), $toPt($bottomMm), $toPt($leftMm));
    }

    /**
     * Returns effective left/right margins для given 1-based page number.
     * Учитывает mirrored swap и gutter.
     *
     * @return array{0: float, 1: float}  [effectiveLeftPt, effectiveRightPt]
     */
    public function effectiveLeftRightFor(int $pageNumber): array
    {
        $isOdd = ($pageNumber % 2) === 1;
        if (! $this->mirrored) {
            // Single-sided: gutter всегда на left (inside binding).
            return [$this->leftPt + $this->gutterPt, $this->rightPt];
        }
        // Mirrored: odd → binding слева (inside), even → справа.
        if ($isOdd) {
            return [$this->leftPt + $this->gutterPt, $this->rightPt];
        }

        return [$this->rightPt, $this->leftPt + $this->gutterPt];
    }
}
