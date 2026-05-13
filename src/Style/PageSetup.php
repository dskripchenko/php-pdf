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
        /**
         * Custom dimensions [widthPt, heightPt]. Если задано — overrides
         * paperSize. Используется для non-standard форматов (визитки, баннеры).
         *
         * @var array{0: float, 1: float}|null
         */
        public ?array $customDimensionsPt = null,
        /**
         * Стартовый page number для PAGE field. Default = 1.
         * Если документ — глава 2 большого manual'а, можно установить 47
         * чтобы первая страница render'ила "47".
         */
        public int $firstPageNumber = 1,
    ) {}

    /**
     * @return array{0: float, 1: float}  [widthPt, heightPt]
     */
    public function dimensions(): array
    {
        if ($this->customDimensionsPt !== null) {
            return $this->orientation === Orientation::Portrait
                ? $this->customDimensionsPt
                : [$this->customDimensionsPt[1], $this->customDimensionsPt[0]];
        }

        return $this->orientation->applyTo($this->paperSize);
    }

    /**
     * Default content width — для convenience и legacy code. Mirror'инг
     * margin'ы и gutter применяются на per-page level через
     * contentWidthPtForPage().
     */
    public function contentWidthPt(): float
    {
        return $this->dimensions()[0] - $this->margins->leftPt - $this->margins->rightPt - $this->margins->gutterPt;
    }

    public function contentHeightPt(): float
    {
        return $this->dimensions()[1] - $this->margins->topPt - $this->margins->bottomPt;
    }

    /**
     * Effective left X для given 1-based page (учитывает mirrored/gutter).
     */
    public function leftXForPage(int $pageNumber): float
    {
        [$left, $_] = $this->margins->effectiveLeftRightFor($pageNumber);

        return $left;
    }

    /**
     * Effective content width для given 1-based page.
     */
    public function contentWidthPtForPage(int $pageNumber): float
    {
        [$left, $right] = $this->margins->effectiveLeftRightFor($pageNumber);

        return $this->dimensions()[0] - $left - $right;
    }
}
