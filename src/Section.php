<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\PageSetup;

/**
 * Section — body content + page setup + опциональные header/footer.
 *
 * Header/footer — list<BlockElement>, рендерится на каждой странице в
 * top/bottom margin areas. Может содержать Field PAGE/NUMPAGES.
 *
 * Multi-section документы (разные orient/margin на разных страницах)
 * не поддерживаются в v0.1.
 */
final readonly class Section
{
    /**
     * @param  list<BlockElement>  $body
     * @param  list<BlockElement>  $headerBlocks
     * @param  list<BlockElement>  $footerBlocks
     */
    /**
     * @param  list<BlockElement>  $body
     * @param  list<BlockElement>  $headerBlocks
     * @param  list<BlockElement>  $footerBlocks
     * @param  list<BlockElement>|null  $firstPageHeaderBlocks  null = use $headerBlocks
     * @param  list<BlockElement>|null  $firstPageFooterBlocks  null = use $footerBlocks
     */
    public function __construct(
        public array $body = [],
        public PageSetup $pageSetup = new PageSetup,
        public array $headerBlocks = [],
        public array $footerBlocks = [],
        public ?string $watermarkText = null,
        /**
         * Different first-page header (cover page). null = same as headerBlocks.
         * Empty list [] = blank header on first page.
         */
        public ?array $firstPageHeaderBlocks = null,
        public ?array $firstPageFooterBlocks = null,
        // Phase 30: image watermark (mutually-compatible with text — оба
        // можно рисовать одновременно; image первым, text сверху).
        public ?PdfImage $watermarkImage = null,
        public ?float $watermarkImageWidthPt = null,
        // Phase 31: opacity для image/text watermark через PDF ExtGState /ca.
        // null = full opacity. Range (0, 1) применяет alpha; ≥1 → no-op.
        public ?float $watermarkImageOpacity = null,
        public ?float $watermarkTextOpacity = null,
        /**
         * Phase 222: footnotes mode.
         *  - null (default): endnotes-style — все footnotes at section's end body
         *  - float > 0: reserve N points at each page bottom для footnotes,
         *               render them per-page below body content.
         *
         * Use ≈12-15pt per expected footnote line (font size 9-10pt + 2pt
         * leading). E.g., 5 short footnotes на page → footnoteBottomReservedPt: 80.
         */
        public ?float $footnoteBottomReservedPt = null,
    ) {}

    public function hasHeader(): bool
    {
        return $this->headerBlocks !== [];
    }

    public function hasFooter(): bool
    {
        return $this->footerBlocks !== [];
    }

    public function hasWatermark(): bool
    {
        return $this->hasTextWatermark() || $this->hasImageWatermark();
    }

    public function hasTextWatermark(): bool
    {
        return $this->watermarkText !== null && $this->watermarkText !== '';
    }

    public function hasImageWatermark(): bool
    {
        return $this->watermarkImage !== null;
    }

    /**
     * @return list<BlockElement>
     */
    public function effectiveHeaderBlocksFor(int $pageNumber): array
    {
        if ($pageNumber === 1 && $this->firstPageHeaderBlocks !== null) {
            return $this->firstPageHeaderBlocks;
        }

        return $this->headerBlocks;
    }

    /**
     * @return list<BlockElement>
     */
    public function effectiveFooterBlocksFor(int $pageNumber): array
    {
        if ($pageNumber === 1 && $this->firstPageFooterBlocks !== null) {
            return $this->firstPageFooterBlocks;
        }

        return $this->footerBlocks;
    }
}
