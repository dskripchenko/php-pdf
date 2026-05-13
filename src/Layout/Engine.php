<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\PdfFontResolver;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Style\ListFormat;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
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
    /**
     * Total pages для NUMPAGES field resolution. Populated после first
     * pass; null = inside first pass (use placeholder).
     */
    private ?int $totalPagesHint = null;

    /**
     * Текущая Section во время render'а — нужна для header/footer access.
     */
    private ?Section $currentSection = null;

    private ?PdfFontResolver $resolver = null;

    public function __construct(
        public readonly ?PdfFont $defaultFont = null,
        public readonly StandardFont $fallbackStandard = StandardFont::Helvetica,
        public readonly float $defaultFontSizePt = 11,
        public readonly float $defaultLineHeightMult = 1.2,
        /**
         * Optional font-variants. Если заданы, используются для Run.style.
         * bold/italic вместо $defaultFont (Phase 10c font matcher). Если
         * не заданы, defaultFont применяется ко всем стилям.
         */
        public readonly ?PdfFont $boldFont = null,
        public readonly ?PdfFont $italicFont = null,
        public readonly ?PdfFont $boldItalicFont = null,
        /**
         * Optional FontProvider — Engine consultит по Run.style.fontFamily
         * прежде чем падать на bold/italic/default chain. Phase 13.
         */
        public readonly ?FontProvider $fontProvider = null,
        /**
         * FlateDecode content streams в output PDF (~3-5× smaller для
         * text-heavy). Default true. Set false для debug inspection
         * (raw operators видны в bytes).
         */
        public readonly bool $compressStreams = true,
    ) {
        if ($fontProvider !== null) {
            $this->resolver = new PdfFontResolver($fontProvider);
        }
    }

    /**
     * Resolves embedded font для given RunStyle.
     *
     * Priority:
     *  1. Если RunStyle.fontFamily задан И fontProvider есть → resolver
     *     (variant chain bold/italic с fallback)
     *  2. Иначе bold/italic ctor-фолбэк chain → defaultFont
     *  3. Иначе null (caller использует fallbackStandard base-14)
     */
    private function resolveEmbeddedFont(RunStyle $style): ?PdfFont
    {
        if ($style->fontFamily !== null && $this->resolver !== null) {
            $resolved = $this->resolver->resolve(
                $style->fontFamily,
                $style->bold,
                $style->italic,
            );
            if ($resolved !== null) {
                return $resolved;
            }
            // Family known but provider returned null → fall through к defaults.
        }

        if ($style->bold && $style->italic) {
            return $this->boldItalicFont ?? $this->boldFont ?? $this->italicFont ?? $this->defaultFont;
        }
        if ($style->bold) {
            return $this->boldFont ?? $this->defaultFont;
        }
        if ($style->italic) {
            return $this->italicFont ?? $this->defaultFont;
        }

        return $this->defaultFont;
    }

    public function render(AstDocument $document): PdfDocument
    {
        // Two-pass для NUMPAGES resolution:
        //  - Pass 1: рендер с totalPagesHint=null, считаем pages
        //  - Pass 2: рендер с known totalPagesHint, NUMPAGES → actual count
        // Если в документе нет NUMPAGES (или нет вообще Field'ов) — двойной
        // pass всё равно делается, но это дешёво (~2× memory peak короткое
        // время). Optimization для skipping pass 1 — Phase L.
        $this->totalPagesHint = null;
        $firstPass = $this->renderOnce($document);
        $this->totalPagesHint = $firstPass->pageCount();

        $finalPass = $this->renderOnce($document);
        $this->totalPagesHint = null;

        return $finalPass;
    }

    private function renderOnce(AstDocument $document): PdfDocument
    {
        // Phase 34: iterate через все sections. First section initializes
        // PDF document; subsequent sections — force new page с её PageSetup.
        $sections = $document->sections();
        $primary = $sections[0];
        $primarySetup = $primary->pageSetup;

        $pdf = new PdfDocument(
            $primarySetup->paperSize,
            $primarySetup->orientation,
            $primarySetup->customDimensionsPt,
            $this->compressStreams,
        );
        $page = $pdf->addPage();

        $context = new LayoutContext(
            pdf: $pdf,
            currentPage: $page,
            cursorY: $primarySetup->dimensions()[1] - $primarySetup->margins->topPt,
            leftX: $primarySetup->leftXForPage(1),
            contentWidth: $primarySetup->contentWidthPtForPage(1),
            bottomY: $primarySetup->margins->bottomPt,
            topY: $primarySetup->dimensions()[1] - $primarySetup->margins->topPt,
            pageSetup: $primarySetup,
        );

        foreach ($sections as $idx => $section) {
            $this->currentSection = $section;
            if ($idx > 0) {
                // Section break — force new page с new PageSetup.
                $context->pageSetup = $section->pageSetup;
                $newPage = $pdf->addPage(
                    $section->pageSetup->paperSize,
                    $section->pageSetup->orientation,
                    $section->pageSetup->customDimensionsPt,
                );
                $context->currentPage = $newPage;
                $this->applyPerPageMargins($context);
                $context->cursorY = $context->topY;
            }
            // Render header/footer на новой first page section'а.
            $this->renderHeaderFooter($context);

            foreach ($section->body as $block) {
                $this->renderBlock($block, $context);
            }
        }

        $this->currentSection = null;

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
            $block instanceof ListNode => $this->renderListNode($block, $ctx, 0),
            $block instanceof Barcode => $this->renderBarcode($block, $ctx),
            default => null,
        };
    }

    private function forcePageBreak(LayoutContext $ctx): void
    {
        // Phase 34: новая page внутри section должна сохранить её
        // PageSetup (paper, orientation, customDimensions), даже если
        // у document есть другая default orientation.
        $setup = $ctx->pageSetup;
        $ctx->currentPage = $ctx->pdf->addPage(
            $setup->paperSize,
            $setup->orientation,
            $setup->customDimensionsPt,
        );
        $this->applyPerPageMargins($ctx);
        $ctx->cursorY = $ctx->topY;
        $this->renderHeaderFooter($ctx);
    }

    /**
     * Применяет mirrored/gutter margins для current page'а (вызывается
     * после создания new page'а). cursorY/topY/bottomY не меняются —
     * только leftX и contentWidth.
     */
    private function applyPerPageMargins(LayoutContext $ctx): void
    {
        $pageNum = $this->currentPageNumber($ctx);
        $ctx->leftX = $ctx->pageSetup->leftXForPage($pageNum);
        $ctx->contentWidth = $ctx->pageSetup->contentWidthPtForPage($pageNum);
    }

    /**
     * Renders header в top-margin area и footer в bottom-margin area
     * текущей page. Вызывается при каждом создании новой page.
     *
     * Header bounds: leftX..leftX+contentWidth × [pageHeight - topMargin
     * .. pageHeight]. Cursor стартует прямо под top edge.
     * Footer bounds: leftX..leftX+contentWidth × [0 .. bottomMargin].
     * Cursor стартует sufficiently below content area.
     */
    private function renderHeaderFooter(LayoutContext $ctx): void
    {
        if ($this->currentSection === null) {
            return;
        }
        $section = $this->currentSection;

        // Watermark — рисуется первым на странице, чтобы оказаться под
        // content'ом (PDF z-order: позже = выше). Image первым, чтобы
        // text-watermark (если оба заданы) лежал поверх.
        if ($section->hasImageWatermark()) {
            $this->renderWatermarkImage(
                $section->watermarkImage,
                $section->watermarkImageWidthPt,
                $section->watermarkImageOpacity,
                $ctx,
            );
        }
        if ($section->hasTextWatermark()) {
            $this->renderWatermark(
                (string) $section->watermarkText,
                $section->watermarkTextOpacity,
                $ctx,
            );
        }
        $setup = $ctx->pageSetup;
        [$pageWidth, $pageHeight] = $setup->dimensions();

        $pageNum = $this->currentPageNumber($ctx);
        $effectiveLeftX = $setup->leftXForPage($pageNum);
        $effectiveContentWidth = $setup->contentWidthPtForPage($pageNum);

        $headerBlocks = $section->effectiveHeaderBlocksFor($pageNum);
        if ($headerBlocks !== []) {
            $headerArea = new LayoutContext(
                pdf: $ctx->pdf,
                currentPage: $ctx->currentPage,
                cursorY: $pageHeight - 8.0,    // 8pt от top edge
                leftX: $effectiveLeftX,
                contentWidth: $effectiveContentWidth,
                bottomY: $pageHeight - $setup->margins->topPt + 4.0,
                topY: $pageHeight - 8.0,
                pageSetup: $setup,
            );
            foreach ($headerBlocks as $block) {
                $this->renderBlock($block, $headerArea);
            }
        }

        $footerBlocks = $section->effectiveFooterBlocksFor($pageNum);
        if ($footerBlocks !== []) {
            // Estimate footer height up-front (для bottom alignment).
            $footerHeight = 0;
            foreach ($footerBlocks as $block) {
                $footerHeight += $this->measureBlockHeight($block, $effectiveContentWidth);
            }
            $footerArea = new LayoutContext(
                pdf: $ctx->pdf,
                currentPage: $ctx->currentPage,
                cursorY: $setup->margins->bottomPt - 4.0 + $footerHeight,
                leftX: $effectiveLeftX,
                contentWidth: $effectiveContentWidth,
                bottomY: 4.0,
                topY: $setup->margins->bottomPt - 4.0 + $footerHeight,
                pageSetup: $setup,
            );
            foreach ($footerBlocks as $block) {
                $this->renderBlock($block, $footerArea);
            }
        }
    }

    /**
     * Renders diagonal watermark на текущей page. Centered, 72pt size,
     * angle ≈ -45° (down-right), light-gray (0.88 0.88 0.88).
     *
     * Text positioned relative к center page'а; rotation matrix вращает
     * around этой точки.
     */
    private function renderWatermark(string $text, ?float $opacity, LayoutContext $ctx): void
    {
        $setup = $ctx->pageSetup;
        [$pageWidth, $pageHeight] = $setup->dimensions();

        $sizePt = 72;
        // Estimate text width — для positioning'а centre.
        $textWidth = $this->defaultFont !== null
            ? (new TextMeasurer($this->defaultFont, $sizePt))->widthPt($text)
            : mb_strlen($text, 'UTF-8') * $sizePt * 0.5;

        // Хотим чтобы центр rotated text оказался в центре page'а.
        // Tm-матрица применяется к origin'у (0,0) → перемещает к (x,y).
        // Поскольку текст рисуется от baseline left, для центрирования:
        // start position = pageCenter - rotatedHalfWidth × cosθ + ...
        // Для простоты: размещаем baseline left at offset от центра.
        $angleRad = -M_PI / 4; // -45°
        $halfWidth = $textWidth / 2;
        $cx = $pageWidth / 2 - $halfWidth * cos($angleRad);
        $cy = $pageHeight / 2 - $halfWidth * sin($angleRad) - $sizePt * 0.3;

        if ($this->defaultFont !== null) {
            $ctx->currentPage->drawWatermarkEmbedded(
                $text, $cx, $cy, $this->defaultFont, $sizePt, $angleRad,
                opacity: $opacity,
            );
        } else {
            $ctx->currentPage->drawWatermark(
                $text, $cx, $cy, $this->fallbackStandard, $sizePt, $angleRad,
                opacity: $opacity,
            );
        }
    }

    /**
     * Phase 30: Image watermark — centered на странице, scaled to
     * $widthPt с сохранением aspect ratio. null widthPt → 50% page width.
     *
     * Прозрачность не применяется автоматически: рекомендуется передавать
     * заранее подготовленный PNG с alpha-каналом или светлый JPEG, иначе
     * водяной знак закроет контент.
     */
    private function renderWatermarkImage(
        \Dskripchenko\PhpPdf\Image\PdfImage $image,
        ?float $widthPt,
        ?float $opacity,
        LayoutContext $ctx,
    ): void {
        $setup = $ctx->pageSetup;
        [$pageWidth, $pageHeight] = $setup->dimensions();

        $w = $widthPt ?? $pageWidth * 0.5;
        $aspect = $image->heightPx > 0 ? $image->widthPx / $image->heightPx : 1.0;
        $h = $aspect > 0 ? $w / $aspect : $w;

        $x = ($pageWidth - $w) / 2;
        $y = ($pageHeight - $h) / 2;

        if ($opacity !== null && $opacity < 1.0) {
            $ctx->currentPage->drawImageWithOpacity($image, $x, $y, $w, $h, $opacity);
        } else {
            $ctx->currentPage->drawImage($image, $x, $y, $w, $h);
        }
    }

    /**
     * Physical 1-based index текущей page'и (для mirrored margins +
     * first-page logic). НЕ учитывает firstPageNumber offset.
     */
    private function currentPageNumber(LayoutContext $ctx): int
    {
        foreach ($ctx->pdf->pages() as $i => $p) {
            if ($p === $ctx->currentPage) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * Displayed page number для Field PAGE — с учётом pageSetup.
     * firstPageNumber offset.
     */
    private function displayedPageNumber(LayoutContext $ctx): int
    {
        $physical = $this->currentPageNumber($ctx);
        if ($physical === 0) {
            return 0;
        }

        return $physical + ($ctx->pageSetup->firstPageNumber - 1);
    }

    private function displayedTotalPages(LayoutContext $ctx): int
    {
        $count = $this->totalPagesHint ?? 1;

        return $count + ($ctx->pageSetup->firstPageNumber - 1);
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
    /**
     * Phase 32: Code 128 barcode block.
     *
     * Algorithm:
     *  1. Encode value через Code128Encoder → list<bool> modules (с quiet
     *     zone).
     *  2. Module width = barcodeWidth / moduleCount. Default barcode width
     *     = moduleCount × 1pt (rough; обычно нужен tweak в caller'е).
     *  3. Draw bars: каждый contiguous run of black modules → fillRect.
     *  4. Optional caption under bars (human-readable).
     */
    private function renderBarcode(Barcode $bc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $bc->spaceBeforePt;

        if ($bc->format->is2D()) {
            $this->renderQrBarcode($bc, $ctx);

            return;
        }

        // Encode по format'у (linear barcodes only — QR обрабатывается выше).
        [$modules, $captionText] = match ($bc->format) {
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Code128 => [
                (new \Dskripchenko\PhpPdf\Barcode\Code128Encoder($bc->value))->modulesWithQuietZone(10),
                $bc->value,
            ],
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Ean13 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Ean13Encoder($bc->value);

                return [$e->modulesWithQuietZone(9), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::UpcA => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Ean13Encoder($bc->value, upcA: true);

                return [$e->modulesWithQuietZone(9), $e->canonical];
            })(),
            default => throw new \LogicException('Linear barcode dispatch reached unreachable format'),
        };
        $moduleCount = count($modules);

        // Width / height.
        $totalWidth = $bc->widthPt ?? (float) $moduleCount;
        $totalWidth = min($totalWidth, $ctx->contentWidth);
        $moduleWidth = $totalWidth / $moduleCount;
        $barsHeight = $bc->heightPt;

        // Caption math.
        $captionHeight = 0;
        if ($bc->showText) {
            $captionHeight = $bc->textSizePt + 2.0; // text + small gap.
        }

        $totalHeight = $barsHeight + $captionHeight;
        $this->ensureRoomFor($ctx, $totalHeight);

        // X-position по alignment.
        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalWidth) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalWidth,
            default => $ctx->leftX,
        };

        // Draw bars: collapse contiguous black runs в single fillRect.
        $yBottom = $ctx->cursorY - $barsHeight;
        $runStart = null;
        for ($i = 0; $i < $moduleCount; $i++) {
            if ($modules[$i]) {
                if ($runStart === null) {
                    $runStart = $i;
                }
            } elseif ($runStart !== null) {
                $w = ($i - $runStart) * $moduleWidth;
                $ctx->currentPage->fillRect(
                    $blockX + $runStart * $moduleWidth, $yBottom, $w, $barsHeight,
                    0, 0, 0,
                );
                $runStart = null;
            }
        }
        if ($runStart !== null) {
            $w = ($moduleCount - $runStart) * $moduleWidth;
            $ctx->currentPage->fillRect(
                $blockX + $runStart * $moduleWidth, $yBottom, $w, $barsHeight,
                0, 0, 0,
            );
        }

        // Caption (human-readable). Используем base-14 Helvetica либо
        // embedded font если задан. Для EAN-13/UPC-A caption — canonical
        // form с checksum digit.
        if ($bc->showText) {
            $captionY = $yBottom - $bc->textSizePt - 1.0;
            $captionWidth = $this->defaultFont !== null
                ? (new TextMeasurer($this->defaultFont, $bc->textSizePt))->widthPt($captionText)
                : mb_strlen($captionText, 'UTF-8') * $bc->textSizePt * 0.5;
            $captionX = $blockX + ($totalWidth - $captionWidth) / 2;

            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText(
                    $captionText, $captionX, $captionY,
                    $this->defaultFont, $bc->textSizePt,
                );
            } else {
                $ctx->currentPage->showText(
                    $captionText, $captionX, $captionY,
                    $this->fallbackStandard, $bc->textSizePt,
                );
            }
        }

        $ctx->cursorY -= $totalHeight;
        $ctx->cursorY -= $bc->spaceAfterPt;
    }

    /**
     * Phase 36: QR code 2D barcode. Modules — 2D bool matrix; рендерим
     * как grid of black squares. Quiet zone (4 modules) добавляется
     * вокруг матрицы.
     */
    private function renderQrBarcode(Barcode $bc, LayoutContext $ctx): void
    {
        $enc = new \Dskripchenko\PhpPdf\Barcode\QrEncoder($bc->value);
        $matrix = $enc->modules();
        $matrixSize = $enc->size();
        $quietZone = 4; // ISO/IEC 18004 minimum.
        $gridSize = $matrixSize + 2 * $quietZone;

        // 2D — totalWidth = totalHeight. widthPt determines size; heightPt
        // ignored для QR (preserved для caption layout).
        $totalSizePt = $bc->widthPt ?? 80.0;
        $totalSizePt = min($totalSizePt, $ctx->contentWidth);
        $moduleSize = $totalSizePt / $gridSize;

        $captionHeight = $bc->showText ? $bc->textSizePt + 2.0 : 0;
        $totalHeight = $totalSizePt + $captionHeight;
        $this->ensureRoomFor($ctx, $totalHeight);

        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalSizePt) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalSizePt,
            default => $ctx->leftX,
        };

        $yTop = $ctx->cursorY;
        // QR top-left corner в PDF (origin = bottom-left): yTop minus
        // (quietZone modules) for the actual matrix region.
        // Draw matrix row by row; для каждой row collapse contiguous black
        // modules в single horizontal fillRect для efficiency.
        $matrixOffset = $quietZone * $moduleSize;
        for ($row = 0; $row < $matrixSize; $row++) {
            $rowYBottom = $yTop - $matrixOffset - ($row + 1) * $moduleSize;
            $runStart = null;
            for ($col = 0; $col < $matrixSize; $col++) {
                if ($matrix[$row][$col]) {
                    if ($runStart === null) {
                        $runStart = $col;
                    }
                } elseif ($runStart !== null) {
                    $w = ($col - $runStart) * $moduleSize;
                    $ctx->currentPage->fillRect(
                        $blockX + $matrixOffset + $runStart * $moduleSize,
                        $rowYBottom, $w, $moduleSize, 0, 0, 0,
                    );
                    $runStart = null;
                }
            }
            if ($runStart !== null) {
                $w = ($matrixSize - $runStart) * $moduleSize;
                $ctx->currentPage->fillRect(
                    $blockX + $matrixOffset + $runStart * $moduleSize,
                    $rowYBottom, $w, $moduleSize, 0, 0, 0,
                );
            }
        }

        // Optional caption (default false для QR — традиционно нет text).
        if ($bc->showText) {
            $captionY = $yTop - $totalSizePt - $bc->textSizePt - 1.0;
            $captionWidth = $this->defaultFont !== null
                ? (new TextMeasurer($this->defaultFont, $bc->textSizePt))->widthPt($bc->value)
                : mb_strlen($bc->value, 'UTF-8') * $bc->textSizePt * 0.5;
            $captionX = $blockX + ($totalSizePt - $captionWidth) / 2;

            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText(
                    $bc->value, $captionX, $captionY,
                    $this->defaultFont, $bc->textSizePt,
                );
            } else {
                $ctx->currentPage->showText(
                    $bc->value, $captionX, $captionY,
                    $this->fallbackStandard, $bc->textSizePt,
                );
            }
        }

        $ctx->cursorY -= $totalHeight;
        $ctx->cursorY -= $bc->spaceAfterPt;
    }

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

        // Phase 25: paragraph padding + background-color.
        // Pre-measure paragraph height чтобы нарисовать bg ПЕРЕД content'ом.
        $hasPadding = $p->style->paddingTopPt + $p->style->paddingRightPt
            + $p->style->paddingBottomPt + $p->style->paddingLeftPt > 0;
        $hasBackground = $p->style->backgroundColor !== null;
        $savedLeftX = $ctx->leftX;
        $savedContentWidth = $ctx->contentWidth;
        if ($hasPadding || $hasBackground) {
            $contentH = $this->measureParagraphHeight($p, $ctx->contentWidth
                - $p->style->paddingLeftPt - $p->style->paddingRightPt)
                - $p->style->spaceBeforePt - $p->style->spaceAfterPt;
            $totalH = $contentH + $p->style->paddingTopPt + $p->style->paddingBottomPt;
            if ($hasBackground) {
                [$r, $g, $b] = $this->hexToRgb((string) $p->style->backgroundColor);
                $ctx->currentPage->fillRect(
                    $ctx->leftX, $ctx->cursorY - $totalH, $ctx->contentWidth, $totalH,
                    $r, $g, $b,
                );
            }
            $ctx->cursorY -= $p->style->paddingTopPt;
            $ctx->leftX += $p->style->paddingLeftPt;
            $ctx->contentWidth -= $p->style->paddingLeftPt + $p->style->paddingRightPt;
        }

        // Outline entry для heading paragraph'а (только в final pass —
        // first pass знаниями не нужен).
        if ($p->headingLevel !== null && $this->totalPagesHint !== null) {
            $title = $this->extractPlainText($p->children);
            if ($title !== '') {
                $ctx->pdf->registerOutlineEntry(
                    $p->headingLevel,
                    $title,
                    $ctx->currentPage,
                    $ctx->leftX,
                    $ctx->cursorY,
                );
            }
        }

        $headingStyle = $this->headingStyle($p->headingLevel);
        $effectiveDefault = $p->defaultRunStyle->inheritFrom($headingStyle);

        // Build list of «items» — atomic units для line breaking.
        // Word | LineBreak | PageBreak | Bookmark (synthetic marker).
        // Word items могут иметь 'link' tag для Hyperlink wrap'а — нужно
        // для emit'а /Link annotation'а после line render'а.
        // Field инстансы резолвятся в word'ы через resolveField($ctx).
        $items = [];
        $this->tokenizeChildren($p->children, $effectiveDefault, $items, null, $ctx);

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

        // Phase 33: while-loop with explicit index because soft-hyphen
        // overflow handling использует array_splice() для re-enqueue
        // remainder в the same items array.
        $i = -1;
        while (++$i < count($items)) {
            $item = $items[$i];
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
            if ($item['type'] === 'bookmark') {
                // Bookmark — zero-width marker; attaches к current line top-Y
                // во время emitLine(). Если current line пуста, attached к
                // следующей line.
                $currentLine[] = $item;

                continue;
            }

            if ($item['type'] === 'image') {
                // Image atom — width известен, sep как для слова.
                $atomWidth = $item['width'];
                $sepWidth = $currentLine === [] ? 0 : $this->measureWidth(' ', $effectiveDefault);
                if ($currentLine !== [] && $currentWidth + $sepWidth + $atomWidth > $effectiveAvail) {
                    $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent, isLastLine: false);
                    $currentLine = [$item];
                    $currentWidth = $atomWidth;
                    $isFirstLine = false;
                    $effectiveAvail = $availableWidth;
                } else {
                    $currentLine[] = $item;
                    $currentWidth += $sepWidth + $atomWidth;
                }

                continue;
            }

            $word = $item['text'] ?? '';
            $style = $item['style'] ?? $effectiveDefault;
            $wordWidth = $this->measureWidth($word, $style);
            $sepWidth = $currentLine === [] ? 0 : $this->measureWidth(' ', $style);

            if ($currentLine !== [] && $currentWidth + $sepWidth + $wordWidth > $effectiveAvail) {
                // Phase 33: Try soft-hyphen split — if word can be broken at SHY
                // marker such that prefix + '-' fits in remaining space, place
                // prefix here и put remainder as new item на следующую line.
                $remainingSpace = $effectiveAvail - $currentWidth - $sepWidth;
                $shySplit = $this->trySplitOnSoftHyphen($word, $style, $remainingSpace);
                if ($shySplit !== null) {
                    [$firstWithHyphen, $remainder] = $shySplit;
                    $firstItem = $item;
                    $firstItem['text'] = $firstWithHyphen;
                    $currentLine[] = $firstItem;
                    // Emit line с splitted prefix.
                    $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent, isLastLine: false);
                    $currentLine = [];
                    $currentWidth = 0;
                    $isFirstLine = false;
                    $effectiveAvail = $availableWidth;
                    // Re-enqueue remainder для следующей итерации.
                    $remainderItem = $item;
                    $remainderItem['text'] = $remainder;
                    array_splice($items, $i + 1, 0, [$remainderItem]);

                    continue;
                }
                // Overflow-driven line break — line followed by more content,
                // so isLastLine = false (justify candidate).
                $this->emitLine($currentLine, $p, $ctx, $effectiveDefault, $isFirstLine, $firstLineExtraIndent, isLastLine: false);
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

        // Phase 25: restore leftX/contentWidth + apply paddingBottom.
        if ($hasPadding || $hasBackground) {
            $ctx->leftX = $savedLeftX;
            $ctx->contentWidth = $savedContentWidth;
            $ctx->cursorY -= $p->style->paddingBottomPt;
        }

        $ctx->cursorY -= $p->style->spaceAfterPt;
    }

    /**
     * Flatten inline-tree в plain list of items для line-break algorithm.
     *
     * Hyperlink children получают 'link' tag для последующего annotation
     * emission'а. Bookmark вставляется как synthetic 'bookmark' item.
     * Field резолвится в word item с конкретным текстом (PAGE → currentPage,
     * NUMPAGES → totalPagesHint, DATE/TIME → now, MERGEFIELD → field name).
     *
     * @param  list<\Dskripchenko\PhpPdf\Element\InlineElement>  $children
     * @param  array<string, mixed>|null  $currentLink
     * @param  list<array<string, mixed>>  $items
     */
    private function tokenizeChildren(
        array $children,
        RunStyle $effectiveDefault,
        array &$items,
        ?array $currentLink,
        ?LayoutContext $ctx = null,
    ): void {
        foreach ($children as $child) {
            if ($child instanceof Run) {
                $childStyle = $child->style->inheritFrom($effectiveDefault);
                foreach ($this->splitWords($child->text) as $word) {
                    $items[] = ['type' => 'word', 'text' => $word, 'style' => $childStyle, 'link' => $currentLink];
                }
            } elseif ($child instanceof LineBreak) {
                $items[] = ['type' => 'br'];
            } elseif ($child instanceof PageBreak) {
                $items[] = ['type' => 'pagebreak'];
            } elseif ($child instanceof Hyperlink) {
                $link = $child->isInternal()
                    ? ['kind' => 'internal', 'target' => $child->anchor ?? '']
                    : ['kind' => 'uri', 'target' => $child->href ?? ''];
                $this->tokenizeChildren($child->children, $effectiveDefault, $items, $link, $ctx);
            } elseif ($child instanceof Bookmark) {
                $items[] = ['type' => 'bookmark', 'name' => $child->name];
                $this->tokenizeChildren($child->children, $effectiveDefault, $items, $currentLink, $ctx);
            } elseif ($child instanceof Field) {
                $resolved = $this->resolveField($child, $ctx);
                $childStyle = $child->style->inheritFrom($effectiveDefault);
                foreach ($this->splitWords($resolved) as $word) {
                    $items[] = ['type' => 'word', 'text' => $word, 'style' => $childStyle, 'link' => $currentLink];
                }
                if (str_ends_with($resolved, ' ')) {
                    // Сохраняем trailing space (важно для "Page X of Y" layout'а).
                    $items[] = ['type' => 'word', 'text' => '', 'style' => $childStyle, 'link' => $currentLink];
                }
            } elseif ($child instanceof Image) {
                // Phase 16: inline image — atom в line-break algorithm.
                // Width/height из effectiveSizePt(); link tag propagates
                // (image wrapped в Hyperlink → clickable image).
                [$imgW, $imgH] = $child->effectiveSizePt();
                $items[] = [
                    'type' => 'image',
                    'image' => $child->source,
                    'width' => $imgW,
                    'height' => $imgH,
                    'link' => $currentLink,
                ];
            }
        }
    }

    /**
     * Резолвит Field в готовую strлinку для emission.
     *
     * PAGE     → currentPageNumber($ctx) (или 1 при measurement без ctx)
     * NUMPAGES → totalPagesHint (или 99 placeholder при first pass)
     * DATE     → текущая дата в указанном формате (DD.MM.YYYY default)
     * TIME     → текущее время (HH:mm default)
     * MERGEFIELD → format-параметр = name (placeholder text)
     */
    private function resolveField(Field $f, ?LayoutContext $ctx): string
    {
        return match ($f->kind()) {
            Field::PAGE => (string) ($ctx !== null ? $this->displayedPageNumber($ctx) : 1),
            Field::NUMPAGES => (string) ($ctx !== null
                ? $this->displayedTotalPages($ctx)
                : ($this->totalPagesHint ?? 99)),
            Field::DATE => $this->formatDateTime($f->format() ?: 'dd.MM.yyyy'),
            Field::TIME => $this->formatDateTime($f->format() ?: 'HH:mm'),
            Field::MERGEFIELD => $f->format() !== '' ? $f->format() : '?',
            default => '',
        };
    }

    /**
     * Extracts plain text из inline children, рекурсивно проникая в
     * Hyperlink/Bookmark wrappers. Используется для outline titles
     * + аналогичных случаев когда нужно только содержимое без styling'а.
     *
     * @param  list<\Dskripchenko\PhpPdf\Element\InlineElement>  $children
     */
    private function extractPlainText(array $children): string
    {
        $text = '';
        foreach ($children as $child) {
            if ($child instanceof Run) {
                $text .= $child->text;
            } elseif ($child instanceof LineBreak) {
                $text .= ' ';
            } elseif ($child instanceof Hyperlink) {
                $text .= $this->extractPlainText($child->children);
            } elseif ($child instanceof Bookmark) {
                $text .= $this->extractPlainText($child->children);
            } elseif ($child instanceof Field) {
                $text .= $this->resolveField($child, null);
            }
        }

        return trim($text);
    }

    private function formatDateTime(string $format): string
    {
        // Mini parser: dd/MM/yyyy/HH/mm/ss tokens → PHP date format.
        // Order matters — long patterns first.
        $map = [
            'yyyy' => 'Y',
            'MM' => 'm',
            'dd' => 'd',
            'HH' => 'H',
            'mm' => 'i',
            'ss' => 's',
        ];
        $phpFmt = strtr($format, $map);

        return date($phpFmt);
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
        bool $isLastLine = true,
    ): void {
        // Split items на word/marker. Markers (bookmark) — zero-width;
        // attached к line top-Y но не учитываются для line-width.
        $wordItems = [];
        $bookmarks = [];
        foreach ($items as $item) {
            if ($item['type'] === 'bookmark') {
                $bookmarks[] = $item;
            } else {
                $wordItems[] = $item;
            }
        }

        if ($wordItems === []) {
            // Пустая line — advance cursorY на дефолтную height, attach bookmarks
            // к current top-Y.
            $sizePt = $defaultStyle->sizePt ?? $this->defaultFontSizePt;
            $lineHeight = $sizePt * $this->effectiveLineHeightMult($p);
            $this->ensureRoomFor($ctx, $lineHeight);
            $this->registerBookmarksAt($ctx, $bookmarks, $ctx->cursorY);
            $ctx->cursorY -= $lineHeight;

            return;
        }

        // Max font size в этой line determines line height. Images
        // также contribute через высоту (inline image увеличивает
        // line-height если выше чем текст).
        $maxSizePt = 0;
        $maxImageHeight = 0;
        foreach ($wordItems as $item) {
            if (($item['type'] ?? null) === 'image') {
                if ($item['height'] > $maxImageHeight) {
                    $maxImageHeight = $item['height'];
                }

                continue;
            }
            $size = ($item['style'] ?? $defaultStyle)->sizePt ?? $this->defaultFontSizePt;
            if ($size > $maxSizePt) {
                $maxSizePt = $size;
            }
        }
        if ($maxSizePt === 0) {
            // Line содержит только images — line-height = image-height.
            $maxSizePt = $defaultStyle->sizePt ?? $this->defaultFontSizePt;
        }
        $textLineHeight = $maxSizePt * $this->effectiveLineHeightMult($p);
        $lineHeight = max($textLineHeight, $maxImageHeight + 2);

        $this->ensureRoomFor($ctx, $lineHeight);

        $this->registerBookmarksAt($ctx, $bookmarks, $ctx->cursorY);

        // Вычислить total content width этой line.
        $totalContentWidth = 0;
        $countWords = count($wordItems);
        for ($i = 0; $i < $countWords; $i++) {
            $item = $wordItems[$i];
            $style = $item['style'] ?? $defaultStyle;
            if (($item['type'] ?? null) === 'image') {
                $totalContentWidth += $item['width'];
            } else {
                $word = $item['text'] ?? '';
                $totalContentWidth += $this->measureWidth($word, $style);
            }
            if ($i + 1 < $countWords) {
                $totalContentWidth += $this->measureWidth(' ', $style);
            }
        }

        // Start X based on alignment.
        $availableWidth = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $effectiveAvail = $availableWidth - ($isFirstLine ? $firstLineIndent : 0);
        $startX = $ctx->leftX + $p->style->indentLeftPt + ($isFirstLine ? $firstLineIndent : 0);

        // Justify (Both/Distribute) — distribute extra space across gaps
        // между words. Last-line + lines с ≥80% fill skipped (CSS spec'у
        // следующая norm: avoid huge gaps).
        $extraPerGap = 0.0;
        $isJustify = ($p->style->alignment === Alignment::Both
            || $p->style->alignment === Alignment::Distribute);
        if ($isJustify && ! $isLastLine && $countWords > 1) {
            $slack = $effectiveAvail - $totalContentWidth;
            $fillRatio = $effectiveAvail > 0 ? $totalContentWidth / $effectiveAvail : 1.0;
            // Расширяем только если line хотя бы 60% заполнена (избегаем
            // нелепо большие gaps на коротких lines).
            if ($slack > 0 && $fillRatio >= 0.6) {
                $extraPerGap = $slack / ($countWords - 1);
            }
        }

        switch ($p->style->alignment) {
            case Alignment::End:
                $startX += $effectiveAvail - $totalContentWidth;
                break;
            case Alignment::Center:
                $startX += ($effectiveAvail - $totalContentWidth) / 2;
                break;
            default:
                // Start / Both / Distribute → start at startX; justify
                // распределяется через $extraPerGap при rendering.
                break;
        }

        $baselineY = $ctx->cursorY - $maxSizePt * 0.8;

        // Render items + track link segments.
        // Link segment: consecutive items with same 'link' meta — emit single
        // /Link annotation covering bbox от startX до endX.
        $x = $startX;
        $linkStartX = null;
        $linkLastX = null;
        $linkRef = null;
        $linkFlush = function () use (&$linkStartX, &$linkLastX, &$linkRef, $ctx, $baselineY, $maxSizePt): void {
            if ($linkRef !== null && $linkStartX !== null && $linkLastX !== null) {
                $rectY1 = $baselineY - $maxSizePt * 0.2;
                $rectY2 = $baselineY + $maxSizePt * 0.85;
                if ($linkRef['kind'] === 'uri') {
                    $ctx->currentPage->addExternalLink(
                        $linkStartX, $rectY1, $linkLastX - $linkStartX, $rectY2 - $rectY1, $linkRef['target']
                    );
                } else {
                    $ctx->currentPage->addInternalLink(
                        $linkStartX, $rectY1, $linkLastX - $linkStartX, $rectY2 - $rectY1, $linkRef['target']
                    );
                }
            }
            $linkStartX = null;
            $linkLastX = null;
            $linkRef = null;
        };

        for ($i = 0; $i < $countWords; $i++) {
            $item = $wordItems[$i];
            $style = $item['style'] ?? $defaultStyle;

            // Image atom: draw at baseline (image bottom = baseline).
            if (($item['type'] ?? null) === 'image') {
                $imgW = $item['width'];
                $imgH = $item['height'];
                $wordLink = $item['link'] ?? null;
                if ($wordLink !== $linkRef) {
                    $linkFlush();
                    if ($wordLink !== null) {
                        $linkRef = $wordLink;
                        $linkStartX = $x;
                    }
                }
                $ctx->currentPage->drawImage($item['image'], $x, $baselineY, $imgW, $imgH);
                $x += $imgW;
                if ($linkRef !== null) {
                    $linkLastX = $x;
                }
                if ($i + 1 < $countWords) {
                    $spaceWidth = $this->measureWidth(' ', $defaultStyle) + $extraPerGap;
                    $x += $spaceWidth;
                    if ($linkRef !== null && (($wordItems[$i + 1]['link'] ?? null) === $linkRef)) {
                        $linkLastX = $x;
                    }
                }

                continue;
            }

            $word = $item['text'] ?? '';
            $baseSizePt = $style->sizePt ?? $this->defaultFontSizePt;
            // Phase 26: sup/sub render at 70% size with vertical baseline shift.
            $sizePt = $baseSizePt;
            $wordBaselineY = $baselineY;
            if ($style->superscript) {
                $sizePt = $baseSizePt * 0.7;
                $wordBaselineY = $baselineY + $baseSizePt * 0.33;
            } elseif ($style->subscript) {
                $sizePt = $baseSizePt * 0.7;
                $wordBaselineY = $baselineY - $baseSizePt * 0.15;
            }
            $wordWidth = $this->measureWidth($word, $style->withSizePt($sizePt));

            $wordLink = $item['link'] ?? null;
            if ($wordLink !== $linkRef) {
                $linkFlush();
                if ($wordLink !== null) {
                    $linkRef = $wordLink;
                    $linkStartX = $x;
                }
            }

            $this->showText($ctx->currentPage, $word, $x, $wordBaselineY, $sizePt, $style);
            // Underline / strikethrough — draw line below/through glyphs.
            if ($style->underline || $style->strikethrough) {
                $this->drawTextDecorations($ctx->currentPage, $x, $wordBaselineY, $wordWidth, $sizePt, $style);
            }
            $x += $wordWidth;
            if ($linkRef !== null) {
                $linkLastX = $x;
            }

            if ($i + 1 < $countWords) {
                $spaceWidth = $this->measureWidth(' ', $style) + $extraPerGap;
                $x += $spaceWidth;
                $this->showText($ctx->currentPage, ' ', $x - $spaceWidth, $baselineY, $sizePt, $style);
                if ($linkRef !== null && (($wordItems[$i + 1]['link'] ?? null) === $linkRef)) {
                    $linkLastX = $x;
                }
            }
        }
        $linkFlush();

        $ctx->cursorY -= $lineHeight;
    }

    /**
     * Регистрирует bookmark destinations'ы для current line top-Y.
     *
     * @param  list<array<string, mixed>>  $bookmarks
     */
    private function registerBookmarksAt(LayoutContext $ctx, array $bookmarks, float $topY): void
    {
        foreach ($bookmarks as $b) {
            $ctx->pdf->registerDestination(
                (string) $b['name'],
                $ctx->currentPage,
                $ctx->leftX,
                $topY,
            );
        }
    }

    /**
     * Draws underline и/или strikethrough линии под/через rendered word.
     *
     * Position relative to baseline (PDF coordinate system Y растёт вверх):
     *  - Underline: ~ baselineY - sizePt × 0.12 (чуть ниже baseline)
     *  - Strike:    ~ baselineY + sizePt × 0.28 (через x-height)
     * Stroke width: sizePt × 0.055 (тоньше чем глифы).
     * Color: текущий цвет текста (Run.style.color), default чёрный.
     */
    private function drawTextDecorations(Page $page, float $x, float $baselineY, float $width, float $sizePt, RunStyle $style): void
    {
        $lineWidth = max(0.4, $sizePt * 0.055);
        [$r, $g, $b] = $style->color !== null
            ? $this->hexToRgb($style->color)
            : [0.0, 0.0, 0.0];

        if ($style->underline) {
            $y = $baselineY - $sizePt * 0.12;
            $page->strokeRect($x, $y, $width, 0, $lineWidth, $r, $g, $b);
        }
        if ($style->strikethrough) {
            $y = $baselineY + $sizePt * 0.28;
            $page->strokeRect($x, $y, $width, 0, $lineWidth, $r, $g, $b);
        }
    }

    /**
     * Renders text using engine's resolved font (bold/italic variant если
     * registered, иначе defaultFont, иначе fallbackStandard).
     * Применяет style.color через rg-operator если задан.
     */
    private function showText(Page $page, string $text, float $x, float $baselineY, float $sizePt, RunStyle $style): void
    {
        // Phase 33: SHY (U+00AD) — невидимый soft hyphen marker, strip
        // перед drawing'ом (визуально не должен рендериться, кроме как при
        // wrap point — это уже handled через '-' append в split helper).
        $text = self::stripSoftHyphens($text);
        $r = $g = $b = null;
        if ($style->color !== null) {
            [$r, $g, $b] = $this->hexToRgb($style->color);
        }
        $tracking = $style->letterSpacingPt ?? 0;
        $font = $this->resolveEmbeddedFont($style);
        if ($font !== null) {
            $page->showEmbeddedText($text, $x, $baselineY, $font, $sizePt, $r, $g, $b, $tracking);
        } else {
            $page->showText($text, $x, $baselineY, $this->fallbackStandard, $sizePt, $r, $g, $b, $tracking);
        }
    }

    /**
     * Measures width в pt — использует resolveEmbeddedFont для точных
     * metrics bold/italic variant'а.
     */
    private function measureWidth(string $text, RunStyle $style): float
    {
        // Phase 33: SHY invisible → стрипим для width estimation.
        $text = self::stripSoftHyphens($text);
        $font = $this->resolveEmbeddedFont($style);
        if ($font !== null) {
            $m = new TextMeasurer($font, $style->sizePt ?? $this->defaultFontSizePt);

            return $m->widthPt($text);
        }
        // Fallback: estimate widths from standard font metrics.
        $sizePt = $style->sizePt ?? $this->defaultFontSizePt;

        return mb_strlen($text, 'UTF-8') * $sizePt * 0.5;
    }

    /**
     * Phase 33: U+00AD (SOFT HYPHEN, HTML &shy;) — невидимый wrap hint.
     */
    public static function stripSoftHyphens(string $text): string
    {
        return str_replace("\u{00AD}", '', $text);
    }

    /**
     * Phase 33: Tries to split word на (prefix + '-', remainder) at one of
     * the soft hyphen positions так, чтобы prefix + '-' влез в \$maxWidth.
     * Greedy: предпочитаем самый последний SHY, оставляющий больше места
     * для остальной строки (но всё ещё fitting в \$maxWidth).
     *
     * Returns [firstWithHyphen, remainder] or null если не удалось разбить.
     *
     * @return array{0: string, 1: string}|null
     */
    private function trySplitOnSoftHyphen(string $word, RunStyle $style, float $maxWidth): ?array
    {
        // SHY positions (byte-level в UTF-8 — U+00AD = 2 bytes: 0xC2 0xAD).
        $shy = "\u{00AD}";
        if (! str_contains($word, $shy)) {
            return null;
        }
        $parts = explode($shy, $word);
        // Try longest prefix first (greedy fit).
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $prefix = implode('', array_slice($parts, 0, $i));
            $remainder = implode($shy, array_slice($parts, $i));
            // measureWidth уже strip'ит SHY, безопасно.
            $w = $this->measureWidth($prefix.'-', $style);
            if ($w <= $maxWidth) {
                return [$prefix.'-', $remainder];
            }
        }

        return null;
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

        $headerRows = array_values(array_filter($t->rows, fn (Row $r): bool => $r->isHeader));

        $totalRows = count($t->rows);
        foreach ($t->rows as $rowIdx => $row) {
            $rowHeight = $this->measureRowHeight($t, $row, $colWidths);
            $isLastRow = $rowIdx === $totalRows - 1;

            if ($ctx->cursorY - $rowHeight < $ctx->bottomY) {
                $this->forcePageBreak($ctx);
                if (! $row->isHeader) {
                    foreach ($headerRows as $hr) {
                        $hh = $this->measureRowHeight($t, $hr, $colWidths);
                        // На repeat'е header row не считаем last (это всё
                        // ещё начало page, дальше будут data rows).
                        $this->renderRow($t, $hr, $colWidths, $tableLeftX, $hh, $ctx, false);
                        $ctx->cursorY -= $hh;
                    }
                }
            }

            $this->renderRow($t, $row, $colWidths, $tableLeftX, $rowHeight, $ctx, $isLastRow);
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
            $cellWidth = 0;
            for ($i = 0; $i < $cell->columnSpan && ($colIdx + $i) < count($colWidths); $i++) {
                $cellWidth += $colWidths[$colIdx + $i];
            }
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
    private function renderRow(Table $t, Row $row, array $colWidths, float $tableLeftX, float $rowHeight, LayoutContext $ctx, bool $isLastRow = false): void
    {
        $rowTopY = $ctx->cursorY;
        $rowBottomY = $rowTopY - $rowHeight;
        $collapse = $t->style->borderCollapse;
        $columnCount = count($colWidths);
        // Phase 19: border-spacing — separate mode shrink'ет каждый cell
        // на spacing/2 с каждой стороны. В collapse игнорируется.
        $spacing = ! $collapse ? $t->style->borderSpacingPt : 0;
        $gap = $spacing / 2;
        // Phase 28: track previous cell's right border для "thicker wins"
        // priority resolution on shared left/right edges в collapse mode.
        $prevCellRight = null;

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

            // Background fill (rounded если cornerRadius > 0).
            if ($cs->backgroundColor !== null) {
                [$r, $g, $b] = $this->hexToRgb($cs->backgroundColor);
                $drawX = $cellX + $gap;
                $drawY = $rowBottomY + $gap;
                $drawW = $cellWidth - 2 * $gap;
                $drawH = $rowHeight - 2 * $gap;
                if ($cs->cornerRadiusPt > 0) {
                    $ctx->currentPage->fillRoundedRect(
                        $drawX, $drawY, $drawW, $drawH,
                        $cs->cornerRadiusPt, $r, $g, $b,
                    );
                } else {
                    $ctx->currentPage->fillRect($drawX, $drawY, $drawW, $drawH, $r, $g, $b);
                }
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
                $drawX = $cellX + $gap;
                $drawY = $rowBottomY + $gap;
                $drawW = $cellWidth - 2 * $gap;
                $drawH = $rowHeight - 2 * $gap;
                if ($cs->cornerRadiusPt > 0 && ! $collapse && $this->areBordersUniform($borders)) {
                    $b = $borders->top;
                    [$r, $g, $bb] = $this->hexToRgb($b->color);
                    $ctx->currentPage->strokeRoundedRect(
                        $drawX, $drawY, $drawW, $drawH,
                        $cs->cornerRadiusPt, $b->widthPt(), $r, $g, $bb,
                    );
                } elseif ($collapse) {
                    $isLastCol = ($colIdx + $cell->columnSpan) >= $columnCount;
                    // Phase 28: "thicker wins" — для shared left edge сравниваем
                    // current.left vs previous cell's right. Top edge — current.top
                    // (cross-row priority требует state между renderRow calls,
                    // deferred к full implementation).
                    $leftBorder = $borders->left;
                    if ($prevCellRight !== null) {
                        $leftBorder = $this->moreProminent($leftBorder, $prevCellRight);
                    }
                    $collapsed = new BorderSet(
                        top: $borders->top,
                        left: $leftBorder,
                        bottom: $isLastRow ? $borders->bottom : null,
                        right: $isLastCol ? $borders->right : null,
                    );
                    $this->drawCellBorders($ctx->currentPage, $drawX, $drawY, $drawW, $drawH, $collapsed);
                    $prevCellRight = $borders->right;
                } else {
                    $this->drawCellBorders($ctx->currentPage, $drawX, $drawY, $drawW, $drawH, $borders);
                }
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

    /**
     * Phase 28: CSS border priority resolution для collapse-mode shared
     * edges. Spec rules (ISO HTML 4 / CSS 2.1 §17.6.2.1):
     *   1. Style 'hidden' beats everything (null wins; no border drawn)
     *   2. Style 'none' loses to everything
     *   3. Wider border wins
     *   4. Same width: double > solid > dashed > dotted
     *   5. Same everything: first-cell-drawn wins (left/top side preferred)
     *
     * Возвращает winning Border (или $a если equal).
     */
    private function moreProminent(?Border $a, ?Border $b): ?Border
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        // None loses.
        $aNone = $a->style === BorderStyle::None;
        $bNone = $b->style === BorderStyle::None;
        if ($aNone && $bNone) {
            return null;
        }
        if ($aNone) {
            return $b;
        }
        if ($bNone) {
            return $a;
        }
        // Wider wins.
        if ($a->sizeEighthsOfPoint !== $b->sizeEighthsOfPoint) {
            return $a->sizeEighthsOfPoint > $b->sizeEighthsOfPoint ? $a : $b;
        }
        // Style preference.
        $rank = static fn (BorderStyle $s): int => match ($s) {
            BorderStyle::Double => 5,
            BorderStyle::Single => 4,
            BorderStyle::Dashed => 3,
            BorderStyle::Dotted => 2,
            BorderStyle::None => 0,
        };

        return $rank($a->style) >= $rank($b->style) ? $a : $b;
    }

    /**
     * Все 4 стороны одинаковые (style + width + color) И non-null?
     */
    private function areBordersUniform(BorderSet $bs): bool
    {
        if ($bs->top === null || $bs->left === null || $bs->bottom === null || $bs->right === null) {
            return false;
        }
        $ref = $bs->top;
        foreach ([$bs->left, $bs->bottom, $bs->right] as $b) {
            if ($b->style !== $ref->style
                || $b->sizeEighthsOfPoint !== $ref->sizeEighthsOfPoint
                || $b->color !== $ref->color) {
                return false;
            }
        }

        return true;
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
        $totalWidth = $b->widthPt();
        if ($b->style === BorderStyle::Double) {
            // Double-line: 2 параллельные strokes, каждая width=total/3,
            // gap между ними = total/3. CSS spec: declared width = full span.
            $stroke = $totalWidth / 3;
            $offset = $totalWidth / 3 + $stroke / 2;
            $page->strokeRect($x, $y + $offset, $w, 0, $stroke, $r, $g, $bb);
            $page->strokeRect($x, $y - $offset, $w, 0, $stroke, $r, $g, $bb);

            return;
        }
        $page->strokeRect($x, $y, $w, 0, $totalWidth, $r, $g, $bb);
    }

    private function drawVerticalBorder(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $h, Border $b): void
    {
        [$r, $g, $bb] = $this->hexToRgb($b->color);
        $totalWidth = $b->widthPt();
        if ($b->style === BorderStyle::Double) {
            $stroke = $totalWidth / 3;
            $offset = $totalWidth / 3 + $stroke / 2;
            $page->strokeRect($x + $offset, $y, 0, $h, $stroke, $r, $g, $bb);
            $page->strokeRect($x - $offset, $y, 0, $h, $stroke, $r, $g, $bb);

            return;
        }
        $page->strokeRect($x, $y, 0, $h, $totalWidth, $r, $g, $bb);
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
            $block instanceof ListNode => $this->measureListNodeHeight($block, $contentWidth, 0),
            default => 0,
        };
    }

    private function measureParagraphHeight(Paragraph $p, float $contentWidth): float
    {
        $headingStyle = $this->headingStyle($p->headingLevel);
        $effectiveDefault = $p->defaultRunStyle->inheritFrom($headingStyle);

        // Build items list (тот же подход что в renderParagraph).
        /** @var list<array<string, mixed>> $items */
        $items = [];
        $this->tokenizeChildren($p->children, $effectiveDefault, $items, null);
        // Skip bookmark items для measurement — zero-width markers.
        $items = array_values(array_filter($items, fn ($it): bool => $it['type'] !== 'bookmark'));

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

    private function measureListNodeHeight(ListNode $list, float $contentWidth, int $level): float
    {
        if ($list->isEmpty()) {
            return 0;
        }
        $total = $list->spaceBeforePt;
        $baseIndent = ($level + 1) * self::LIST_LEVEL_INDENT_PT;
        $itemContentWidth = $contentWidth - $baseIndent;

        foreach ($list->items as $item) {
            foreach ($item->children as $child) {
                $total += $this->measureBlockHeight($child, $itemContentWidth);
            }
            if ($item->nestedList !== null) {
                $total += $this->measureListNodeHeight($item->nestedList, $contentWidth, $level + 1);
            }
        }
        $total += $list->spaceAfterPt;

        return $total;
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
     * Indent per nesting level (pt).
     */
    private const float LIST_LEVEL_INDENT_PT = 18.0;

    /**
     * Renders bullet/ordered list через injection маркера в первую
     * Paragraph каждого item'а + hanging-indent (через ParagraphStyle.
     * indentLeftPt + indentFirstLinePt = -markerWidth).
     *
     * Nested ListNode (через ListItem.nestedList) рекурсивно рисуется
     * с увеличенным $level.
     */
    private function renderListNode(ListNode $list, LayoutContext $ctx, int $level): void
    {
        if ($list->isEmpty()) {
            return;
        }
        $ctx->cursorY -= $list->spaceBeforePt;

        $format = $list->effectiveFormat();
        $baseIndent = ($level + 1) * self::LIST_LEVEL_INDENT_PT;

        foreach ($list->items as $i => $item) {
            $number = $list->startAt + $i;
            $marker = $this->formatListMarker($number, $format);

            $this->renderListItem($item, $marker, $baseIndent, $ctx, $level);
        }

        $ctx->cursorY -= $list->spaceAfterPt;
    }

    private function renderListItem(
        ListItem $item,
        string $marker,
        float $baseIndent,
        LayoutContext $ctx,
        int $level,
    ): void {
        $children = $item->children;

        if ($children === []) {
            // Пустой item — render только marker.
            $children = [new Paragraph([new Run('')])];
        }

        $firstChild = $children[0];
        if ($firstChild instanceof Paragraph) {
            // Prepend marker to first paragraph's first child через injection
            // нового Run'а. Hanging indent через indentLeftPt+indentFirstLinePt.
            $newFirstChildren = [new Run($marker), ...$firstChild->children];
            $newStyle = $firstChild->style->copy(
                indentLeftPt: $baseIndent,
                indentFirstLinePt: -self::LIST_LEVEL_INDENT_PT,
            );
            $modified = new Paragraph(
                children: $newFirstChildren,
                style: $newStyle,
                headingLevel: $firstChild->headingLevel,
                defaultRunStyle: $firstChild->defaultRunStyle,
            );
            $this->renderBlock($modified, $ctx);

            // Subsequent children — same baseIndent, no marker, no first-
            // line indent.
            for ($i = 1; $i < count($children); $i++) {
                $child = $children[$i];
                if ($child instanceof Paragraph) {
                    $childWithIndent = new Paragraph(
                        children: $child->children,
                        style: $child->style->copy(indentLeftPt: $baseIndent),
                        headingLevel: $child->headingLevel,
                        defaultRunStyle: $child->defaultRunStyle,
                    );
                    $this->renderBlock($childWithIndent, $ctx);
                } else {
                    // Non-paragraph (Image/Table/etc.) — render через sub-context
                    // с indented leftX.
                    $this->renderIndentedBlock($child, $baseIndent, $ctx);
                }
            }
        } else {
            // First child — не paragraph. Render marker как standalone paragraph
            // + потом все children with indent.
            $markerP = new Paragraph(
                children: [new Run(trim($marker))],
                style: new ParagraphStyle(indentLeftPt: $baseIndent - self::LIST_LEVEL_INDENT_PT),
            );
            $this->renderBlock($markerP, $ctx);
            foreach ($children as $child) {
                $this->renderIndentedBlock($child, $baseIndent, $ctx);
            }
        }

        if ($item->nestedList !== null) {
            $this->renderListNode($item->nestedList, $ctx, $level + 1);
        }
    }

    /**
     * Renders BlockElement (Image/Table/etc.) с increased leftX (sub-ctx).
     */
    private function renderIndentedBlock(BlockElement $block, float $indentPt, LayoutContext $ctx): void
    {
        $saved = [$ctx->leftX, $ctx->contentWidth];
        $ctx->leftX += $indentPt;
        $ctx->contentWidth -= $indentPt;
        try {
            $this->renderBlock($block, $ctx);
        } finally {
            [$ctx->leftX, $ctx->contentWidth] = $saved;
        }
    }

    private function formatListMarker(int $n, ListFormat $f): string
    {
        return match ($f) {
            ListFormat::Bullet => "\u{2022}  ",   // •
            ListFormat::Decimal => $n.'. ',
            ListFormat::LowerLetter => $this->toLetter($n, false).'. ',
            ListFormat::UpperLetter => $this->toLetter($n, true).'. ',
            ListFormat::LowerRoman => strtolower($this->toRoman($n)).'. ',
            ListFormat::UpperRoman => $this->toRoman($n).'. ',
        };
    }

    private function toLetter(int $n, bool $upper): string
    {
        if ($n < 1) {
            return '';
        }
        $base = $upper ? 65 : 97;
        $result = '';
        while ($n > 0) {
            $n--;
            $result = chr($base + ($n % 26)).$result;
            $n = (int) ($n / 26);
        }

        return $result;
    }

    private function toRoman(int $n): string
    {
        if ($n < 1) {
            return '';
        }
        $map = [
            ['M', 1000], ['CM', 900], ['D', 500], ['CD', 400],
            ['C', 100], ['XC', 90], ['L', 50], ['XL', 40],
            ['X', 10], ['IX', 9], ['V', 5], ['IV', 4], ['I', 1],
        ];
        $result = '';
        foreach ($map as [$sym, $val]) {
            while ($n >= $val) {
                $result .= $sym;
                $n -= $val;
            }
        }

        return $result;
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
