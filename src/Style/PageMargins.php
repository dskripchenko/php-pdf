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
}
