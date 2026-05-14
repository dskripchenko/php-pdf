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
        // Phase 39: multi-column state. columnCount == 1 → single-column
        // (legacy behavior, no column flow). columnCount > 1 → forcePageBreak
        // overflow → next column; last column → real page break.
        public int $columnCount = 1,
        public int $currentColumn = 0,
        public float $columnGapPt = 0,
        public float $columnOriginLeftX = 0,
        public float $columnOriginContentWidth = 0,
        // Phase 40: collected footnotes/endnotes per section (rendered at
        // end of section as endnotes block).
        /** @var list<string> */
        public array $footnotes = [],
        // Phase 61: suppress paragraph BDC/EMC wrapping (used когда heading
        // sets own H1-H6 tagging вокруг paragraph render).
        public bool $skipParagraphTag = false,
        // Phase 155: re-entrance guard для header/footer rendering. forcePageBreak
        // вызывает renderHeaderFooter; если внутри header rendering сам block
        // не fits и пытается forcePageBreak — infinite loop. Этот флаг
        // суппрессирует header/footer на новой page если мы уже в header/footer
        // render path; также суппрессирует sам forcePageBreak (overflow truncates).
        public bool $inHeaderFooterRender = false,
    ) {}
}
