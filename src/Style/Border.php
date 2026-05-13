<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Один edge'овый border (paragraph/cell/table).
 *
 * Размер — в восьмых пункта (1/8 pt) per OOXML convention. CSS px-style
 * coverter в Layout engine'е.
 *
 * Цвет — RGB hex без `#`, lowercase (`14b8a6`).
 */
final readonly class Border
{
    public function __construct(
        public BorderStyle $style = BorderStyle::Single,
        public int $sizeEighthsOfPoint = 4,  // = 0.5 pt
        public string $color = '000000',
    ) {}

    /**
     * Convenience: convert size в pt (для использования в PDF stroke
     * width).
     */
    public function widthPt(): float
    {
        return $this->sizeEighthsOfPoint / 8.0;
    }
}
