<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Page;
use Dskripchenko\PhpPdf\Style\PageSetup;

/**
 * Mutable state, который Engine mutate'ит при walk'е AST.
 *
 * Содержит:
 *  - currentPage — куда сейчас рендерим
 *  - cursorY — где «вершина» следующей строки (в PDF coords; растёт вверх)
 *  - leftX/contentWidth — content area bounds
 *  - topY/bottomY — vertical bounds (для overflow detection)
 *  - pdf — корневой Pdf\Document (нужен для addPage'а при overflow)
 *
 * Engine — единственный writer; LayoutContext предоставляет доступ
 * к этим полям публично потому что они часто читаются/обновляются
 * во время layout pass'а.
 */
final class LayoutContext
{
    public function __construct(
        public PdfDocument $pdf,
        public Page $currentPage,
        public float $cursorY,
        public float $leftX,
        public float $contentWidth,
        public float $bottomY,
        public float $topY,
        public PageSetup $pageSetup,
    ) {}
}
