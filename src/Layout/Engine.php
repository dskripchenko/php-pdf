<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Page;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Layout Engine — walks Document AST + emits Pdf\Document с
 * absolute-positioned content.
 *
 * Phase 3 scope:
 *  - Paragraph: greedy line-breaking + line-by-line rendering
 *  - Multi-run paragraphs (mixed styles per line)
 *  - PageBreak (forced new page)
 *  - HorizontalRule (full-width 0.5pt line)
 *  - LineBreak inside paragraphs
 *  - Heading levels 1..6 — auto bold + bigger size
 *  - Page overflow → automatic new page
 *  - Alignment: Start/Center/End на line level
 *
 * Phase 3 НЕ покрывает (deferred):
 *  - Headers/footers/watermarks (Phase 8)
 *  - Tables (Phase 5)
 *  - Lists (Phase 6)
 *  - Hyperlinks active rendering (Phase 7 — пока text-only, links — TODO)
 *  - Bookmarks named destinations (Phase 7)
 *  - Fields PAGE/NUMPAGES resolution (Phase 8)
 *  - Image positioning внутри текста (Phase 4)
 *  - Justified text (Phase L)
 *  - Hyphenation (Phase L)
 *  - Auto-bold/italic font switching (Phase 4 font matcher)
 *
 * Font resolution (Phase 3 simple):
 *  - $defaultFont (PdfFont) если задан — все text rendered им
 *  - Иначе fallback на $fallbackStandard (PDF base-14)
 *  - Run.style.fontFamily игнорируется (Phase 4 это исправит через
 *    FontProvider matching)
 *  - Run.style.bold/italic визуально не меняют шрифт — нужны bold/
 *    italic варианты font (Phase 4)
 */
final class Engine
{
    public function __construct(
        public readonly ?PdfFont $defaultFont = null,
        public readonly StandardFont $fallbackStandard = StandardFont::Helvetica,
        public readonly float $defaultFontSizePt = 11,
        public readonly float $defaultLineHeightMult = 1.2,
    ) {}

    public function render(AstDocument $document): PdfDocument
    {
        $section = $document->section;
        $pageSetup = $section->pageSetup;

        $pdf = new PdfDocument($pageSetup->paperSize, $pageSetup->orientation);
        $page = $pdf->addPage();

        $context = new LayoutContext(
            pdf: $pdf,
            currentPage: $page,
            cursorY: $pageSetup->dimensions()[1] - $pageSetup->margins->topPt,
            leftX: $pageSetup->margins->leftPt,
            contentWidth: $pageSetup->contentWidthPt(),
            bottomY: $pageSetup->margins->bottomPt,
            topY: $pageSetup->dimensions()[1] - $pageSetup->margins->topPt,
            pageSetup: $pageSetup,
        );

        foreach ($section->body as $block) {
            $this->renderBlock($block, $context);
        }

        return $pdf;
    }

    private function renderBlock(BlockElement $block, LayoutContext $ctx): void
    {
        match (true) {
            $block instanceof Paragraph => $this->renderParagraph($block, $ctx),
            $block instanceof PageBreak => $this->forcePageBreak($ctx),
            $block instanceof HorizontalRule => $this->renderHorizontalRule($ctx),
            $block instanceof Image => $this->renderImage($block, $ctx),
            $block instanceof Table => $this->renderTable($block, $ctx),
            default => null,
        };
    }

    private function forcePageBreak(LayoutContext $ctx): void
    {
        $ctx->currentPage = $ctx->pdf->addPage();
        $ctx->cursorY = $ctx->topY;
    }

    private function renderHorizontalRule(LayoutContext $ctx): void
    {
        // Spacing вокруг hr ~6pt сверху и снизу.
        $ctx->cursorY -= 6;
        $this->ensureRoomFor($ctx, 1);
        $ctx->currentPage->strokeRect(
            $ctx->leftX, $ctx->cursorY, $ctx->contentWidth, 0,
            lineWidthPt: 0.5,
            r: 0.6, g: 0.6, b: 0.6,
        );
        $ctx->cursorY -= 6;
    }

    /**
     * Block-level image rendering — applying alignment, sizing с aspect
     * ratio, page-overflow detection. Image-as-inline (text wrap) — Phase L.
     *
     * Если image слишком high для current page → forcePageBreak'аем,
     * затем рендерим вверху новой page. Если image больше contentHeight'а —
     * скейлим down пропорционально (TODO Phase L; пока ассертируем).
     */
    private function renderImage(Image $img, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $img->spaceBeforePt;

        [$widthPt, $heightPt] = $img->effectiveSizePt();

        // Scale down если image больше content area по любой dimension.
        $maxWidth = $ctx->contentWidth;
        if ($widthPt > $maxWidth) {
            $ratio = $maxWidth / $widthPt;
            $widthPt *= $ratio;
            $heightPt *= $ratio;
        }
        $maxHeight = $ctx->topY - $ctx->bottomY;
        if ($heightPt > $maxHeight) {
            $ratio = $maxHeight / $heightPt;
            $widthPt *= $ratio;
            $heightPt *= $ratio;
        }

        // Если не хватает места на текущей page → page break.
        $this->ensureRoomFor($ctx, $heightPt);

        // X-position по alignment'у.
        $x = match ($img->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $widthPt) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $widthPt,
            default => $ctx->leftX,
        };

        // Pdf coords: drawImage принимает (x, y, w, h), где y — bottom-left
        // угол image'а (PDF Y-axis растёт вверх).
        $y = $ctx->cursorY - $heightPt;
        $ctx->currentPage->drawImage($img->source, $x, $y, $widthPt, $heightPt);

        $ctx->cursorY -= $heightPt;
        $ctx->cursorY -= $img->spaceAfterPt;
    }

    private function renderParagraph(Paragraph $p, LayoutContext $ctx): void
    {
        if ($p->style->pageBreakBefore) {
            $this->forcePageBreak($ctx);
        }

        $ctx->cursorY -= $p->style->spaceBeforePt;

        $headingStyle = $this->headingStyle($p->headingLevel);
        $effectiveDefault = $p->defaultRunStyle->inheritFrom($headingStyle);

        // Build list of «items» — atomic units для line breaking.
        // Word | LineBreak | (другие inline elements — Hyperlink etc.
        // в Phase 3 пока пропускаются с warning'ом).
        $items = [];
        foreach ($p->children as $child) {
            if ($child instanceof Run) {
                $childStyle = $child->style->inheritFrom($effectiveDefault);
                $words = $this->splitWords($child->text);
                foreach ($words as $word) {
                    $items[] = ['type' => 'word', 'text' => $word, 'style' => $childStyle];
                }
            } elseif ($child instanceof LineBreak) {
                $items[] = ['type' => 'br'];
            } elseif ($child instanceof PageBreak) {
                $items[] = ['type' => 'pagebreak'];
            }
            // Hyperlink/Bookmark/Field/Image — Phase 7 (для Phase 3
            // ограничиваемся text content'ом).
        }

        // Layout indents для first line (firstLineIndent применяется
        // только к первой line, остальные используют indentLeft).
        $isFirstLine = true;
        $availableWidth = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $firstLineExtraIndent = $p->style->indentFirstLinePt;

        // Greedy line breaking.
        /** @var list<array{type: string, text?: string, style?: RunStyle}> $currentLine */
        $currentLine = [];
        $currentWidth = 0;
        $effectiveAvail = $availableWidth - ($isFirstLine ? $firstLineExtraIndent : 0);

        foreach ($items as $item) {
            if ($item['type'] === 'br') {
                $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent);
                $currentLine = [];
                $currentWidth = 0;
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;

                continue;
            }
            if ($item['type'] === 'pagebreak') {
                $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent);
                $currentLine = [];
                $currentWidth = 0;
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;
                $this->forcePageBreak($ctx);

                continue;
            }

            $word = $item['text'] ?? '';
            $style = $item['style'] ?? $effectiveDefault;
            $wordWidth = $this->measureWidth($word, $style);
            $sepWidth = $currentLine === [] ? 0 : $this->measureWidth(' ', $style);

            if ($currentLine !== [] && $currentWidth + $sepWidth + $wordWidth > $effectiveAvail) {
                // Linebreak — emit current line + начать новую.
                $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent);
                $currentLine = [$item];
                $currentWidth = $wordWidth;
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;
            } else {
                $currentLine[] = $item;
                $currentWidth += $sepWidth + $wordWidth;
            }
        }
        // Flush last line.
        if ($currentLine !== []) {
            $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent);
        }

        $ctx->cursorY -= $p->style->spaceAfterPt;
    }

    /**
     * Эмитит line — позиционирует слова, обновляет cursorY.
     *
     * @param  list<array{type: string, text?: string, style?: RunStyle}>  $items
     */
    private function emitLine(
        array $items,
        Paragraph $p,
        LayoutContext $ctx,
        RunStyle $defaultStyle,
        bool $isFirstLine,
        float $firstLineIndent,
    ): void {
        if ($items === []) {
            // Пустая line — все равно advance cursorY на дефолтную high.
            $sizePt = $defaultStyle->sizePt ?? $this->defaultFontSizePt;
            $lineHeight = $sizePt * $this->effectiveLineHeightMult($p);
            $this->ensureRoomFor($ctx, $lineHeight);
            $ctx->cursorY -= $lineHeight;

            return;
        }

        // Max font size в этой line determines line height.
        $maxSizePt = 0;
        foreach ($items as $item) {
            $size = ($item['style'] ?? $defaultStyle)->sizePt ?? $this->defaultFontSizePt;
            if ($size > $maxSizePt) {
                $maxSizePt = $size;
            }
        }
        $lineHeight = $maxSizePt * $this->effectiveLineHeightMult($p);

        $this->ensureRoomFor($ctx, $lineHeight);

        // Вычислить total content width этой line.
        $totalContentWidth = 0;
        for ($i = 0; $i < count($items); $i++) {
            $item = $items[$i];
            $word = $item['text'] ?? '';
            $style = $item['style'] ?? $defaultStyle;
            $totalContentWidth += $this->measureWidth($word, $style);
            if ($i + 1 < count($items)) {
                $totalContentWidth += $this->measureWidth(' ', $style);
            }
        }

        // Start X based on alignment.
        $availableWidth = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $effectiveAvail = $availableWidth - ($isFirstLine ? $firstLineIndent : 0);
        $startX = $ctx->leftX + $p->style->indentLeftPt + ($isFirstLine ? $firstLineIndent : 0);

        switch ($p->style->alignment) {
            case Alignment::End:
                $startX += $effectiveAvail - $totalContentWidth;
                break;
            case Alignment::Center:
                $startX += ($effectiveAvail - $totalContentWidth) / 2;
                break;
            // Both/Distribute — justify (Phase L). Сейчас деградируем к Start.
            default:
                break;
        }

        // Baseline Y (PDF text-rendering — Y reference это baseline).
        // cursorY — top of line. Baseline ≈ top - sizePt * 0.8 (approx).
        $baselineY = $ctx->cursorY - $maxSizePt * 0.8;

        // Render items.
        $x = $startX;
        for ($i = 0; $i < count($items); $i++) {
            $item = $items[$i];
            $word = $item['text'] ?? '';
            $style = $item['style'] ?? $defaultStyle;
            $sizePt = $style->sizePt ?? $this->defaultFontSizePt;
            $this->showText($ctx->currentPage, $word, $x, $baselineY, $sizePt, $style);
            $x += $this->measureWidth($word, $style);
            if ($i + 1 < count($items)) {
                $x += $this->measureWidth(' ', $style);
                // Render space separator (для visible word separation).
                $this->showText($ctx->currentPage, ' ', $x - $this->measureWidth(' ', $style), $baselineY, $sizePt, $style);
            }
        }

        $ctx->cursorY -= $lineHeight;
    }

    /**
     * Renders text using engine's resolved font.
     */
    private function showText(Page $page, string $text, float $x, float $baselineY, float $sizePt, RunStyle $style): void
    {
        if ($this->defaultFont !== null) {
            $page->showEmbeddedText($text, $x, $baselineY, $this->defaultFont, $sizePt);
        } else {
            $page->showText($text, $x, $baselineY, $this->fallbackStandard, $sizePt);
        }
    }

    /**
     * Measures width в pt (using engine's resolved font).
     */
    private function measureWidth(string $text, RunStyle $style): float
    {
        if ($this->defaultFont !== null) {
            $m = new TextMeasurer($this->defaultFont, $style->sizePt ?? $this->defaultFontSizePt);

            return $m->widthPt($text);
        }
        // Fallback: estimate widths from standard font metrics. Для
        // Phase 3 минимума — простая monospace-like estimation
        // (sizePt × 0.5 на character).
        $sizePt = $style->sizePt ?? $this->defaultFontSizePt;

        return mb_strlen($text, 'UTF-8') * $sizePt * 0.5;
    }

    private function ensureRoomFor(LayoutContext $ctx, float $heightPt): void
    {
        if ($ctx->cursorY - $heightPt < $ctx->bottomY) {
            $this->forcePageBreak($ctx);
        }
    }

    private function effectiveLineHeightMult(Paragraph $p): float
    {
        return $p->style->lineHeightMult ?? $this->defaultLineHeightMult;
    }

    /**
     * Heading style cascade defaults: H1 = 24pt bold, H2 = 20pt bold, ...
     * Это override-able caller'ом через явные RunStyle/ParagraphStyle.
     */
    private function headingStyle(?int $level): RunStyle
    {
        if ($level === null) {
            return new RunStyle(sizePt: $this->defaultFontSizePt);
        }
        $headingSizes = [
            1 => 24,
            2 => 20,
            3 => 16,
            4 => 14,
            5 => 12,
            6 => 11,
        ];
        $size = $headingSizes[$level] ?? $this->defaultFontSizePt;

        return new RunStyle(sizePt: $size, bold: true);
    }

    /**
     * Renders Table: column-width distribution, row-by-row layout с
     * pre-measurement каждого row (для row-height), drawing background
     * + borders, vertical alignment of cell content.
     *
     * Phase 5b ограничения (исправит Phase 5c):
     *  - columnSpan/rowSpan игнорируются (assumed 1)
     *  - isHeader rows НЕ повторяются на следующих страницах
     *  - row-split при page overflow — full row уходит на новую страницу
     *
     * Алгоритм:
     *  1. Compute total table width + per-column widths
     *  2. Position table inside content area по table.style.alignment
     *  3. Для каждого row:
     *     a. Pre-measure высоту каждой cell → row height = max
     *     b. ensureRoomFor(row height) → page break если не помещается
     *     c. Draw cell backgrounds, then content, then borders
     *  4. spaceBefore/After
     */
    private function renderTable(Table $t, LayoutContext $ctx): void
    {
        if ($t->isEmpty()) {
            return;
        }

        $ctx->cursorY -= $t->style->spaceBeforePt;

        $columnCount = $t->columnCount();
        $tableWidth = $this->computeTableWidth($t->style, $ctx->contentWidth);
        $colWidths = $this->computeColumnWidths($t, $tableWidth, $columnCount);
        $tableLeftX = $this->computeTableLeftX($t->style->alignment, $ctx, $tableWidth);

        foreach ($t->rows as $row) {
            $rowHeight = $this->measureRowHeight($t, $row, $colWidths);
            $this->ensureRoomFor($ctx, $rowHeight);
            $this->renderRow($t, $row, $colWidths, $tableLeftX, $rowHeight, $ctx);
            $ctx->cursorY -= $rowHeight;
        }

        $ctx->cursorY -= $t->style->spaceAfterPt;
    }

    private function computeTableWidth(TableStyle $style, float $contentWidth): float
    {
        if ($style->widthPt !== null) {
            return min($style->widthPt, $contentWidth);
        }
        if ($style->widthPercent !== null) {
            return $contentWidth * $style->widthPercent / 100;
        }

        return $contentWidth;
    }

    /**
     * @return list<float>
     */
    private function computeColumnWidths(Table $t, float $tableWidth, int $columnCount): array
    {
        if ($t->columnWidthsPt !== null && count($t->columnWidthsPt) === $columnCount) {
            // Scale to fit tableWidth если sum differs.
            $sum = array_sum($t->columnWidthsPt);
            if ($sum > 0) {
                $scale = $tableWidth / $sum;

                return array_map(fn (float $w): float => $w * $scale, array_map(floatval(...), $t->columnWidthsPt));
            }
        }

        // Equal distribution.
        $perCol = $columnCount > 0 ? $tableWidth / $columnCount : 0;

        return array_fill(0, $columnCount, $perCol);
    }

    private function computeTableLeftX(Alignment $align, LayoutContext $ctx, float $tableWidth): float
    {
        return match ($align) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $tableWidth) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $tableWidth,
            default => $ctx->leftX,
        };
    }

    /**
     * @param  list<float>  $colWidths
     */
    private function measureRowHeight(Table $t, Row $row, array $colWidths): float
    {
        if ($row->heightPt !== null) {
            return $row->heightPt;
        }

        $maxHeight = 0;
        $colIdx = 0;
        foreach ($row->cells as $cell) {
            $cellWidth = $colWidths[$colIdx] ?? $colWidths[0];
            $colIdx += $cell->columnSpan;
            $cs = $this->effectiveCellStyle($t, $cell);
            $contentWidth = $cellWidth - $cs->paddingLeftPt - $cs->paddingRightPt;
            $contentHeight = $this->measureBlockListHeight($cell->children, $contentWidth);
            $cellHeight = $contentHeight + $cs->paddingTopPt + $cs->paddingBottomPt;
            if ($cellHeight > $maxHeight) {
                $maxHeight = $cellHeight;
            }
        }

        // Minimum row height: 1 line of default text + paddings.
        return max($maxHeight, $this->defaultFontSizePt * $this->defaultLineHeightMult);
    }

    /**
     * @param  list<float>  $colWidths
     */
    private function renderRow(Table $t, Row $row, array $colWidths, float $tableLeftX, float $rowHeight, LayoutContext $ctx): void
    {
        $rowTopY = $ctx->cursorY;
        $rowBottomY = $rowTopY - $rowHeight;

        // 1. Backgrounds + content + borders в три pass'а (background ниже,
        //    borders сверху, content между).
        $cellX = $tableLeftX;
        $colIdx = 0;
        foreach ($row->cells as $cell) {
            $cellWidth = 0;
            for ($i = 0; $i < $cell->columnSpan && ($colIdx + $i) < count($colWidths); $i++) {
                $cellWidth += $colWidths[$colIdx + $i];
            }
            $cs = $this->effectiveCellStyle($t, $cell);

            // Background fill.
            if ($cs->backgroundColor !== null) {
                [$r, $g, $b] = $this->hexToRgb($cs->backgroundColor);
                $ctx->currentPage->fillRect($cellX, $rowBottomY, $cellWidth, $rowHeight, $r, $g, $b);
            }

            $cellX += $cellWidth;
            $colIdx += $cell->columnSpan;
        }

        // Content.
        $cellX = $tableLeftX;
        $colIdx = 0;
        foreach ($row->cells as $cell) {
            $cellWidth = 0;
            for ($i = 0; $i < $cell->columnSpan && ($colIdx + $i) < count($colWidths); $i++) {
                $cellWidth += $colWidths[$colIdx + $i];
            }
            $cs = $this->effectiveCellStyle($t, $cell);

            $this->renderCellContent($cell, $cs, $cellX, $rowTopY, $cellWidth, $rowHeight, $ctx);

            $cellX += $cellWidth;
            $colIdx += $cell->columnSpan;
        }

        // Borders на top.
        $cellX = $tableLeftX;
        $colIdx = 0;
        foreach ($row->cells as $cell) {
            $cellWidth = 0;
            for ($i = 0; $i < $cell->columnSpan && ($colIdx + $i) < count($colWidths); $i++) {
                $cellWidth += $colWidths[$colIdx + $i];
            }
            $cs = $this->effectiveCellStyle($t, $cell);
            $borders = $cs->borders ?? $this->defaultBorderSet($t->style);

            if ($borders !== null) {
                $this->drawCellBorders($ctx->currentPage, $cellX, $rowBottomY, $cellWidth, $rowHeight, $borders);
            }

            $cellX += $cellWidth;
            $colIdx += $cell->columnSpan;
        }
    }

    private function renderCellContent(
        Cell $cell,
        CellStyle $cs,
        float $cellX,
        float $cellTopY,
        float $cellWidth,
        float $rowHeight,
        LayoutContext $ctx,
    ): void {
        $contentWidth = $cellWidth - $cs->paddingLeftPt - $cs->paddingRightPt;
        $contentHeight = $this->measureBlockListHeight($cell->children, $contentWidth);

        // Vertical alignment.
        $availableHeight = $rowHeight - $cs->paddingTopPt - $cs->paddingBottomPt;
        $vOffset = match ($cs->verticalAlign) {
            VerticalAlignment::Center => max(0, ($availableHeight - $contentHeight) / 2),
            VerticalAlignment::Bottom => max(0, $availableHeight - $contentHeight),
            default => 0,
        };

        $sub = new LayoutContext(
            pdf: $ctx->pdf,
            currentPage: $ctx->currentPage,
            cursorY: $cellTopY - $cs->paddingTopPt - $vOffset,
            leftX: $cellX + $cs->paddingLeftPt,
            contentWidth: $contentWidth,
            // Cell content не вызывает auto-page-break (Phase 5b).
            bottomY: $cellTopY - $rowHeight - 10000,
            topY: $cellTopY - $cs->paddingTopPt - $vOffset,
            pageSetup: $ctx->pageSetup,
        );

        foreach ($cell->children as $block) {
            $this->renderBlock($block, $sub);
        }
    }

    private function effectiveCellStyle(Table $t, Cell $cell): CellStyle
    {
        // Cell-style имеет priority. defaultCellStyle применяется только
        // если cell.style identical-equal к bare-default CellStyle (т.е.
        // user не задал свой стиль). Это эвристика — Phase 5c сделает
        // полноценный cascade через explicit merge.
        if ($cell->style == new CellStyle) {
            return $t->style->defaultCellStyle;
        }

        return $cell->style;
    }

    private function defaultBorderSet(TableStyle $ts): ?BorderSet
    {
        if ($ts->defaultCellBorder !== null) {
            return BorderSet::all($ts->defaultCellBorder);
        }

        return null;
    }

    private function drawCellBorders(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $w, float $h, BorderSet $borders): void
    {
        if ($borders->top !== null) {
            $this->drawHorizontalBorder($page, $x, $y + $h, $w, $borders->top);
        }
        if ($borders->bottom !== null) {
            $this->drawHorizontalBorder($page, $x, $y, $w, $borders->bottom);
        }
        if ($borders->left !== null) {
            $this->drawVerticalBorder($page, $x, $y, $h, $borders->left);
        }
        if ($borders->right !== null) {
            $this->drawVerticalBorder($page, $x + $w, $y, $h, $borders->right);
        }
    }

    private function drawHorizontalBorder(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $w, Border $b): void
    {
        [$r, $g, $bb] = $this->hexToRgb($b->color);
        $page->strokeRect($x, $y, $w, 0, $b->widthPt(), $r, $g, $bb);
    }

    private function drawVerticalBorder(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $h, Border $b): void
    {
        [$r, $g, $bb] = $this->hexToRgb($b->color);
        $page->strokeRect($x, $y, 0, $h, $b->widthPt(), $r, $g, $bb);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = ((int) hexdec(substr($hex, 0, 2))) / 255;
        $g = ((int) hexdec(substr($hex, 2, 2))) / 255;
        $b = ((int) hexdec(substr($hex, 4, 2))) / 255;

        return [$r, $g, $b];
    }

    /**
     * @param  list<BlockElement>  $blocks
     */
    private function measureBlockListHeight(array $blocks, float $contentWidth): float
    {
        $total = 0;
        foreach ($blocks as $block) {
            $total += $this->measureBlockHeight($block, $contentWidth);
        }

        return $total;
    }

    /**
     * Pure measurement — no side effects на pdf/page.
     */
    private function measureBlockHeight(BlockElement $block, float $contentWidth): float
    {
        return match (true) {
            $block instanceof Paragraph => $this->measureParagraphHeight($block, $contentWidth),
            $block instanceof Image => $this->measureImageHeight($block, $contentWidth),
            $block instanceof HorizontalRule => 12.0, // 6 + 0 + 6
            $block instanceof Table => $this->measureTableHeight($block, $contentWidth),
            default => 0,
        };
    }

    private function measureParagraphHeight(Paragraph $p, float $contentWidth): float
    {
        $headingStyle = $this->headingStyle($p->headingLevel);
        $effectiveDefault = $p->defaultRunStyle->inheritFrom($headingStyle);

        // Build items list (тот же подход что в renderParagraph).
        /** @var list<array{type: string, text?: string, style?: RunStyle}> $items */
        $items = [];
        foreach ($p->children as $child) {
            if ($child instanceof Run) {
                $childStyle = $child->style->inheritFrom($effectiveDefault);
                foreach ($this->splitWords($child->text) as $word) {
                    $items[] = ['type' => 'word', 'text' => $word, 'style' => $childStyle];
                }
            } elseif ($child instanceof LineBreak) {
                $items[] = ['type' => 'br'];
            }
        }

        $availableWidth = $contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $firstLineExtraIndent = $p->style->indentFirstLinePt;
        $isFirstLine = true;
        $effectiveAvail = $availableWidth - $firstLineExtraIndent;
        $currentWidth = 0;
        $hasContent = false;
        $maxSizeInLine = 0;

        $lineMaxSizes = [];
        $flushLine = function () use (&$lineMaxSizes, &$maxSizeInLine, &$currentWidth, &$isFirstLine, &$hasContent, &$effectiveAvail, $availableWidth, $effectiveDefault) {
            $lineMaxSizes[] = $maxSizeInLine > 0
                ? $maxSizeInLine
                : ($effectiveDefault->sizePt ?? $this->defaultFontSizePt);
            $maxSizeInLine = 0;
            $currentWidth = 0;
            $isFirstLine = false;
            $hasContent = false;
            $effectiveAvail = $availableWidth;
        };

        foreach ($items as $item) {
            if ($item['type'] === 'br') {
                $flushLine();

                continue;
            }
            $word = $item['text'] ?? '';
            $style = $item['style'] ?? $effectiveDefault;
            $wordWidth = $this->measureWidth($word, $style);
            $sepWidth = $hasContent ? $this->measureWidth(' ', $style) : 0;
            $size = $style->sizePt ?? $this->defaultFontSizePt;

            if ($hasContent && $currentWidth + $sepWidth + $wordWidth > $effectiveAvail) {
                $flushLine();
                $currentWidth = $wordWidth;
                $maxSizeInLine = $size;
                $hasContent = true;
            } else {
                $currentWidth += $sepWidth + $wordWidth;
                if ($size > $maxSizeInLine) {
                    $maxSizeInLine = $size;
                }
                $hasContent = true;
            }
        }
        if ($hasContent || $lineMaxSizes === []) {
            $flushLine();
        }

        $mult = $this->effectiveLineHeightMult($p);
        $total = $p->style->spaceBeforePt;
        foreach ($lineMaxSizes as $s) {
            $total += $s * $mult;
        }
        $total += $p->style->spaceAfterPt;

        return $total;
    }

    private function measureImageHeight(Image $img, float $contentWidth): float
    {
        [$w, $h] = $img->effectiveSizePt();
        if ($w > $contentWidth) {
            $h *= $contentWidth / $w;
        }

        return $h + $img->spaceBeforePt + $img->spaceAfterPt;
    }

    private function measureTableHeight(Table $t, float $contentWidth): float
    {
        if ($t->isEmpty()) {
            return 0;
        }
        $columnCount = $t->columnCount();
        $tableWidth = $this->computeTableWidth($t->style, $contentWidth);
        $colWidths = $this->computeColumnWidths($t, $tableWidth, $columnCount);

        $total = $t->style->spaceBeforePt;
        foreach ($t->rows as $row) {
            $total += $this->measureRowHeight($t, $row, $colWidths);
        }
        $total += $t->style->spaceAfterPt;

        return $total;
    }

    /**
     * Splits text на «words» по whitespace. Preserves multiple spaces
     * NOT (single split). Empty strings filtered out.
     *
     * @return list<string>
     */
    private function splitWords(string $text): array
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_filter($parts, fn (string $p): bool => $p !== ''));
    }
}
