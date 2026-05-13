<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Style\PageSetup;

/**
 * Section — body content + page setup.
 *
 * Header/footer/watermark — Phase 8 (для Phase 3 минимума только body).
 *
 * Multi-section документы (разные orient/margin на разных страницах)
 * не поддерживаются в v0.1.
 */
final readonly class Section
{
    /**
     * @param  list<BlockElement>  $body
     */
    public function __construct(
        public array $body = [],
        public PageSetup $pageSetup = new PageSetup,
    ) {}
}
