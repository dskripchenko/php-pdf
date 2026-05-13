<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Ориентация страницы. PDF MediaBox задаётся всегда как
 * `[0 0 width height]`; landscape означает что width > height
 * (swap происходит при applyTo).
 */
enum Orientation
{
    case Portrait;
    case Landscape;

    /**
     * Возвращает (width, height) для PaperSize в этой orientation.
     *
     * @return array{0: float, 1: float}
     */
    public function applyTo(PaperSize $paper): array
    {
        return $this === self::Portrait
            ? [$paper->widthPt(), $paper->heightPt()]
            : [$paper->heightPt(), $paper->widthPt()];
    }
}
