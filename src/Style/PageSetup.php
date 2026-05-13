<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Полный page setup — paper size + orientation + margins.
 *
 * Используется в Section'е для определения layout границ страницы.
 */
final readonly class PageSetup
{
    public function __construct(
        public PaperSize $paperSize = PaperSize::A4,
        public Orientation $orientation = Orientation::Portrait,
        public PageMargins $margins = new PageMargins,
    ) {}

    /**
     * @return array{0: float, 1: float}  [widthPt, heightPt]
     */
    public function dimensions(): array
    {
        return $this->orientation->applyTo($this->paperSize);
    }

    public function contentWidthPt(): float
    {
        return $this->dimensions()[0] - $this->margins->leftPt - $this->margins->rightPt;
    }

    public function contentHeightPt(): float
    {
        return $this->dimensions()[1] - $this->margins->topPt - $this->margins->bottomPt;
    }
}
