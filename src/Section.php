<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf;

use Dskripchenko\PhpPdf\Element\BlockElement;
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
    public function __construct(
        public array $body = [],
        public PageSetup $pageSetup = new PageSetup,
        public array $headerBlocks = [],
        public array $footerBlocks = [],
    ) {}

    public function hasHeader(): bool
    {
        return $this->headerBlocks !== [];
    }

    public function hasFooter(): bool
    {
        return $this->footerBlocks !== [];
    }
}
