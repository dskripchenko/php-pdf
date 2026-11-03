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
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\ColumnSet;
use Dskripchenko\PhpPdf\Element\DonutChart;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Element\GroupedBarChart;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\MathExpression;
use Dskripchenko\PhpPdf\Element\ScatterChart;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\MultiLineChart;
use Dskripchenko\PhpPdf\Element\PieChart;
use Dskripchenko\PhpPdf\Element\StackedBarChart;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Positioned;
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
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\StandardFontMetrics;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\WinAnsi;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Layout Engine — walks the Document AST and emits a Pdf\Document with
 * absolute-positioned content.
 *
 * Core capabilities:
 *  - Paragraph: greedy line-breaking + line-by-line rendering
 *  - Multi-run paragraphs (mixed styles per line)
 *  - PageBreak (forced new page)
 *  - HorizontalRule (full-width 0.5pt line)
 *  - LineBreak inside paragraphs
 *  - Heading levels 1..6 — auto bold + bigger size
 *  - Page overflow → automatic new page
 *  - Alignment: Start/Center/End at line level
 *  - Headers/footers/watermarks
 *  - Tables, Lists
 *  - Hyperlinks, bookmarks/named destinations
 *  - Field resolution (PAGE/NUMPAGES)
 *  - Inline images, justified text
 *  - Hyphenation + soft-hyphen
 *  - Bold/italic font switching via FontProvider
 *
 * Font resolution:
 *  - If $defaultFont (PdfFont) is set, all text is rendered with it.
 *  - Otherwise fall back to $fallbackStandard (PDF base-14).
 *  - Run.style.fontFamily is resolved via FontProvider when configured.
 *  - Run.style.bold/italic select boldFont/italicFont/boldItalicFont
 *    variants when supplied.
 */
final class Engine
{
    /**
     * Total pages for NUMPAGES field resolution. Populated after the first
     * pass; null = inside first pass (use placeholder).
     */
    private ?int $totalPagesHint = null;

    /** Whether the pass being rendered resolved a NUMPAGES field. */
    private bool $totalPagesUsed = false;

    /**
     * Footnote bookkeeping, keyed by the footnote element itself.
     *
     * A cell is laid out more than once — measured, and re-rendered when a
     * row is split across pages — so collecting footnotes as they are met
     * appended the same note again on every pass: numbering drifted upward
     * and a seven-page form ate half a gigabyte. Keyed by the element, the
     * same note keeps the same number however often it is visited.
     *
     * @var array<int, int> spl_object_id → footnote number
     */
    private array $footnoteNumbers = [];

    /** @var array<int, string> footnote number → its text */
    private array $footnoteTexts = [];

    /** @var array<int, RunStyle|null> The notes' own text styles, by number. */
    private array $footnoteStyles = [];

    /** @var array<int, list<int>> page spl_object_id → numbers placed on it */
    private array $pageFootnotes = [];

    /**
     * Footnote numbers that already have room at the foot of a page, by page
     * (growing footnote area).
     *
     * @var array<int, list<int>>
     */
    private array $pageFootnoteRoom = [];

    /**
     * Current Section during render — needed for header/footer access.
     */
    private ?Section $currentSection = null;

    /**
     * `position: fixed` blocks met so far in the current section, drawn
     * again on each new page.
     *
     * @var list<Positioned>
     */
    private array $fixedPositioned = [];

    private ?PdfFontResolver $resolver = null;

    /**
     * Base-14 face for each family/bold/italic seen, so a CSS family list
     * isn't parsed again for every word measured.
     *
     * @var array<string, StandardFont>
     */
    private array $standardFontCache = [];

    /**
     * Text widths measured in the current render, by style and text. A word
     * is measured for the block's height, again for line breaking and again
     * when drawn, and tables repeat the same words row after row.
     *
     * @var array<string, float>
     */
    private array $widthCache = [];

    /** @var array<int, RunStyle> Base run style per heading level, 0 for body text. */
    private array $headingStyles = [];

    /** Entries kept before the width cache starts over. */
    private const WIDTH_CACHE_LIMIT = 20000;

    /** Font ascent and descent, in em, used to place a baseline in its line. */
    private const LINE_ASCENT = 0.905;

    private const LINE_DESCENT = 0.212;

    public function __construct(
        public readonly ?PdfFont $defaultFont = null,
        public readonly StandardFont $fallbackStandard = StandardFont::Helvetica,
        public readonly float $defaultFontSizePt = 11,
        public readonly float $defaultLineHeightMult = 1.2,
        /**
         * Optional font-variants. If set, used for Run.style.bold/italic
         * instead of $defaultFont. If not set, defaultFont applies to all
         * styles.
         */
        public readonly ?PdfFont $boldFont = null,
        public readonly ?PdfFont $italicFont = null,
        public readonly ?PdfFont $boldItalicFont = null,
        /**
         * Optional FontProvider — Engine consults it by Run.style.fontFamily
         * before falling back to the bold/italic/default chain.
         */
        public readonly ?FontProvider $fontProvider = null,
        /**
         * FlateDecode content streams in output PDF (~3-5× smaller for
         * text-heavy). Default true. Set false for debug inspection
         * (raw operators visible in bytes).
         */
        public readonly bool $compressStreams = true,
        /**
         * Font fallback chain — if the main font (defaultFont or resolved
         * variant) lacks a glyph for some char, try the next font in the
         * chain. Resolution is per whitespace-separated word: a mixed-script
         * paragraph draws Latin words with the main font and e.g. CJK words
         * with the fallback. All-or-nothing per word (no per-char switching).
         *
         * @var list<PdfFont>
         */
        public readonly array $fallbackFonts = [],
        /**
         * Tab stop interval (pt). When \t is encountered in Run text,
         * x advances to the next multiple of this value from line start.
         * Default 36pt = 0.5 inch.
         */
        public readonly float $tabStopPt = 36.0,
        /**
         * Enable hanging punctuation (trailing period/comma/etc at line
         * end extends past right margin for visual flush).
         */
        public readonly bool $hangingPunctuation = false,
    ) {
        if ($fontProvider !== null) {
            $this->resolver = new PdfFontResolver($fontProvider);
        }
    }

    /**
     * Resolves embedded font for the given RunStyle.
     *
     * Priority:
     *  1. If RunStyle.fontFamily is set and fontProvider exists → resolver
     *     (variant chain bold/italic with fallback)
     *  2. Otherwise bold/italic ctor fallback chain → defaultFont
     *  3. Otherwise null (caller uses fallbackStandard base-14)
     */
    private function resolveEmbeddedFont(RunStyle $style, ?string $text = null): ?PdfFont
    {
        if ($style->fontFamily !== null && $this->resolver !== null) {
            // A CSS list ("Brand, Arial, sans-serif"): the first family the
            // provider has.
            foreach (self::families($style->fontFamily) as $family) {
                $resolved = $this->resolver->resolve($family, $style->bold, $style->italic);
                if ($resolved !== null) {
                    return $this->applyFallbackChain($resolved, $text);
                }
            }
        }

        $primary = match (true) {
            $style->bold && $style->italic => $this->boldItalicFont ?? $this->boldFont ?? $this->italicFont ?? $this->defaultFont,
            $style->bold => $this->boldFont ?? $this->defaultFont,
            $style->italic => $this->italicFont ?? $this->defaultFont,
            default => $this->defaultFont,
        };

        return $this->applyFallbackChain($primary, $text);
    }

    /**
     * If the primary font lacks a glyph for some char in $text, try the
     * fallbackFonts chain. Returns the first font supporting all chars,
     * or primary (graceful degradation if none qualifies).
     */
    private function applyFallbackChain(?PdfFont $primary, ?string $text): ?PdfFont
    {
        if ($primary === null || $text === null || $text === '' || $this->fallbackFonts === []) {
            return $primary;
        }
        if ($primary->supportsText($text)) {
            return $primary;
        }
        foreach ($this->fallbackFonts as $fb) {
            if ($fb->supportsText($text)) {
                return $fb;
            }
        }

        return $primary;
    }

    public function render(AstDocument $document): PdfDocument
    {
        // Two passes only when NUMPAGES needs the page count:
        //  - Pass 1: render with totalPagesHint=null, count pages
        //  - Pass 2: render with known totalPagesHint, NUMPAGES → actual count
        // A document that never shows the total is finished after pass 1.
        // The width cache lives for one render only, so its memory goes
        // with it.
        $this->totalPagesHint = null;
        try {
            $firstPass = $this->renderOnce($document);
            if (! $this->totalPagesUsed) {
                return $firstPass;
            }
            $this->totalPagesHint = $firstPass->pageCount();

            return $this->renderOnce($document);
        } finally {
            $this->totalPagesHint = null;
            $this->widthCache = [];
        }
    }

    /** Walks the tree once and gives every footnote its number. */
    private function assignFootnoteNumbers(AstDocument $document): void
    {
        $this->footnoteNumbers = [];
        $this->footnoteTexts = [];
        $this->footnoteStyles = [];
        $this->pageFootnotes = [];
        $this->pageFootnoteRoom = [];

        foreach ($document->sections() as $section) {
            $this->collectFootnotes($section->body);
        }
    }

    /** @param  iterable<mixed>  $nodes */
    private function collectFootnotes(iterable $nodes): void
    {
        foreach ($nodes as $node) {
            // Text runs are leaves and the bulk of any tree.
            if ($node instanceof Run) {
                continue;
            }
            if ($node instanceof \Dskripchenko\PhpPdf\Element\Footnote) {
                $id = spl_object_id($node);
                if (! isset($this->footnoteNumbers[$id])) {
                    $number = count($this->footnoteNumbers) + 1;
                    $this->footnoteNumbers[$id] = $number;
                    $this->footnoteTexts[$number] = $node->content;
                    $this->footnoteStyles[$number] = $node->style;
                }

                continue;
            }

            foreach (['children', 'body', 'rows', 'cells', 'items'] as $prop) {
                if (isset($node->{$prop}) && is_iterable($node->{$prop})) {
                    $this->collectFootnotes($node->{$prop});
                }
            }
        }
    }

    /**
     * Number of a footnote; assigns one if the pre-pass could not reach it
     * (a tree shape the walk does not know about).
     */
    private function footnoteNumberFor(\Dskripchenko\PhpPdf\Element\Footnote $footnote): int
    {
        $id = spl_object_id($footnote);
        if (! isset($this->footnoteNumbers[$id])) {
            $number = count($this->footnoteNumbers) + 1;
            $this->footnoteNumbers[$id] = $number;
            $this->footnoteTexts[$number] = $footnote->content;
            $this->footnoteStyles[$number] = $footnote->style;
        }

        return $this->footnoteNumbers[$id];
    }

    /**
     * Remembers that a marker was painted on this page.
     *
     * Recorded when the marker is drawn rather than when it is tokenized:
     * a cell may be tokenized and thrown away when a row does not fit, and
     * a note that never appeared must not claim space at the page foot.
     */
    private function registerFootnoteOnPage(Page $page, int $number): void
    {
        $pageId = spl_object_id($page);
        if (! in_array($number, $this->pageFootnotes[$pageId] ?? [], true)) {
            $this->pageFootnotes[$pageId][] = $number;
        }
    }

    /** A note as it is drawn at the foot of the page. */
    private function footnoteParagraph(int $number): Paragraph
    {
        return new Paragraph([new Run($number.'. '.($this->footnoteTexts[$number] ?? ''), $this->footnoteStyles[$number] ?? new RunStyle)]);
    }

    /**
     * The height the notes take at the foot of a page, with the separator
     * above them when $withSeparator.
     *
     * @param  list<int>  $numbers
     */
    private function footnoteHeight(array $numbers, float $width, bool $withSeparator): float
    {
        $height = 0.0;
        foreach ($numbers as $number) {
            $height += $this->measureParagraphHeight($this->footnoteParagraph($number), $width);
        }

        return $height > 0 && $withSeparator ? $height + 6.0 : $height;
    }

    /**
     * The room the notes still need on the current page: those that have
     * none yet, plus the separator for the page's first.
     *
     * @param  list<int>  $numbers
     */
    private function footnoteRoomNeeded(LayoutContext $ctx, array $numbers): float
    {
        $have = $this->pageFootnoteRoom[spl_object_id($ctx->currentPage)] ?? [];
        $new = array_values(array_diff(array_unique($numbers), $have));

        return $this->footnoteHeight($new, $ctx->contentWidth, $have === []);
    }

    /**
     * Gives the notes their room at the foot of the current page: the
     * content bottom rises by what they take.
     *
     * @param  list<int>  $numbers
     */
    private function reserveFootnoteRoom(LayoutContext $ctx, array $numbers): void
    {
        if (! $ctx->footnoteGrowing || $numbers === []) {
            return;
        }
        $height = $this->footnoteRoomNeeded($ctx, $numbers);
        $pageId = spl_object_id($ctx->currentPage);
        $this->pageFootnoteRoom[$pageId] = array_values(array_unique([...($this->pageFootnoteRoom[$pageId] ?? []), ...$numbers]));
        $ctx->bottomY += $height;
        $ctx->footnoteReserveBottomPt = (float) $ctx->footnoteReserveBottomPt + $height;
    }

    /**
     * The numbers of the notes inside elements.
     *
     * @param  iterable<mixed>  $nodes
     * @return list<int>
     */
    private function footnotesIn(iterable $nodes): array
    {
        $numbers = [];
        foreach ($nodes as $node) {
            // Text runs are leaves and the bulk of any tree.
            if ($node instanceof Run) {
                continue;
            }
            if ($node instanceof \Dskripchenko\PhpPdf\Element\Footnote) {
                $numbers[] = $this->footnoteNumberFor($node);

                continue;
            }
            foreach (['children', 'body', 'rows', 'cells', 'items'] as $prop) {
                if (isset($node->{$prop}) && is_iterable($node->{$prop})) {
                    $numbers = [...$numbers, ...$this->footnotesIn($node->{$prop})];
                }
            }
        }

        return $numbers;
    }

    private function renderOnce(AstDocument $document): PdfDocument
    {
        $this->totalPagesUsed = false;

        // Numbers are handed out in a single walk of the tree, in document
        // order, before anything is laid out. Assigning them as footnotes
        // are met would tie numbering to the order of layout passes, and a
        // measured table would claim numbers before the paragraph above it.
        $this->assignFootnoteNumbers($document);

        // Iterate through all sections. First section initializes the
        // PDF document; subsequent sections — force new page with their PageSetup.
        $sections = $document->sections();
        $primary = $sections[0];
        $primarySetup = $primary->pageSetup;

        $pdf = new PdfDocument(
            $primarySetup->paperSize,
            $primarySetup->orientation,
            $primarySetup->customDimensionsPt,
            $this->compressStreams,
        );
        // Forward tagged flag from AST.
        // PDF/A-1a implies tagged — auto-enable for consistent
        // struct element collection during rendering.
        $taggedRequired = $document->tagged
            || ($document->pdfA?->conformance === \Dskripchenko\PhpPdf\Pdf\PdfAConfig::CONFORMANCE_A);
        if ($taggedRequired) {
            $pdf->enableTagged();
        }
        // Forward language hint.
        if ($document->lang !== null) {
            $pdf->setLang($document->lang);
        }
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

        // Track page ranges per section for the watermark post-pass.
        // sectionPageRanges[i] = [startPageIdx, endPageIdx] — inclusive
        // indices in $pdf->pages() array.
        $sectionPageRanges = [];
        foreach ($sections as $idx => $section) {
            $this->currentSection = $section;
            $this->fixedPositioned = [];
            if ($idx > 0) {
                // Section break — force new page with new PageSetup.
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

            // Footnote per-page bottom mode. Reduce bottomY so that
            // body content shrinks upward, leaving a zone for footnotes.
            $context->footnoteReserveBottomPt = $section->footnoteBottomReservedPt;
            $context->footnoteGrowing = $section->footnoteBottomReservedPt === null && $section->footnotesAtPageFoot;
            if ($context->footnoteGrowing) {
                $context->footnoteReserveBottomPt = 0.0;
            }
            $context->pageFootnoteStart = count($context->footnotes);
            if ($section->footnoteBottomReservedPt !== null) {
                $context->bottomY += $section->footnoteBottomReservedPt;
            }

            $sectionStartIdx = count($pdf->pages()) - 1;
            // Render header/footer on the section's new first page.
            $this->renderHeaderFooter($context);

            // The loop variable deliberately has its own name: the outer loop
            // walks the sections by $idx, and a shared name would clobber it.
            $bodyBlocks = array_values($section->body);
            foreach ($bodyBlocks as $blockIdx => $block) {
                $this->keepWithNextBreak($block, $bodyBlocks[$blockIdx + 1] ?? null, $context);
                $this->renderBlock($block, $context);
            }

            if ($context->footnoteReserveBottomPt !== null) {
                // Flush last page's footnotes at its bottom.
                $this->renderPageBottomFootnotes($context);
                // Restore bottomY (next section may not have a reservation).
                $context->bottomY -= $context->footnoteReserveBottomPt;
                $context->footnoteReserveBottomPt = null;
                $context->footnoteGrowing = false;
            } elseif ($this->footnoteTexts !== []) {
                // Emit collected endnotes per section (if any).
                $this->renderEndnotes($context);
            }
            $sectionPageRanges[$idx] = [$sectionStartIdx, count($pdf->pages()) - 1];
        }

        // Watermark post-pass — draw watermarks ON TOP of body
        // content on every page of the section. PDF z-order: later = above,
        // so a watermark appended after body commands appears OVER the body.
        $allPages = $pdf->pages();
        foreach ($sections as $idx => $section) {
            if (! $section->hasWatermark()) {
                continue;
            }
            [$startIdx, $endIdx] = $sectionPageRanges[$idx];
            for ($p = $startIdx; $p <= $endIdx; $p++) {
                if (! isset($allPages[$p])) {
                    continue;
                }
                $this->renderWatermarksOnPage($section, $allPages[$p]);
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
            $block instanceof ListNode => $this->renderListNode($block, $ctx, 0, 0.0),
            $block instanceof Barcode => $this->renderBarcode($block, $ctx),
            $block instanceof ColumnSet => $this->renderColumnSet($block, $ctx),
            $block instanceof FormField => $this->renderFormField($block, $ctx),
            $block instanceof SignatureField => $this->renderSignatureField($block, $ctx),
            $block instanceof BarChart => $this->renderBarChart($block, $ctx),
            $block instanceof LineChart => $this->renderLineChart($block, $ctx),
            $block instanceof PieChart => $this->renderPieChart($block, $ctx),
            $block instanceof GroupedBarChart => $this->renderGroupedBarChart($block, $ctx),
            $block instanceof StackedBarChart => $this->renderStackedBarChart($block, $ctx),
            $block instanceof MultiLineChart => $this->renderMultiLineChart($block, $ctx),
            $block instanceof DonutChart => $this->renderDonutChart($block, $ctx),
            $block instanceof ScatterChart => $this->renderScatterChart($block, $ctx),
            $block instanceof AreaChart => $this->renderAreaChart($block, $ctx),
            $block instanceof SvgElement => $this->renderSvgElement($block, $ctx),
            $block instanceof Heading => $this->renderHeading($block, $ctx),
            $block instanceof MathExpression => $this->renderMathExpression($block, $ctx),
            $block instanceof Positioned => $this->renderPositioned($block, $ctx),
            default => null,
        };
    }

    /**
     * Out-of-flow blocks (CSS `position: absolute | fixed`): drawn at their
     * offsets, the cursor untouched. A fixed one comes back on every new
     * page of the section.
     */
    private function renderPositioned(Positioned $p, LayoutContext $ctx): void
    {
        if ($p->fixed) {
            $this->fixedPositioned[] = $p;
        }
        $this->drawPositioned($p, $ctx);
    }

    private function drawPositioned(Positioned $p, LayoutContext $ctx): void
    {
        $setup = $ctx->pageSetup;
        [, $pageHeight] = $setup->dimensions();
        if ($p->inFlow) {
            $areaX = $ctx->leftX;
            $areaTop = $ctx->cursorY;
            $areaW = $ctx->contentWidth;
            $areaBottom = $ctx->bottomY;
        } else {
            $areaX = $setup->margins->leftPt;
            $areaTop = $pageHeight - $setup->margins->topPt;
            $areaW = $setup->contentWidthPt();
            $areaBottom = $setup->margins->bottomPt;
        }
        $width = max(1.0, $p->widthPt ?? ($areaW - ($p->leftPt ?? 0.0) - ($p->rightPt ?? 0.0)));
        $height = $this->measureBlockListHeight($p->children, $width);
        $x = match (true) {
            $p->leftPt !== null => $areaX + $p->leftPt,
            $p->rightPt !== null => $areaX + $areaW - $p->rightPt - $width,
            default => $areaX,
        };
        $top = match (true) {
            $p->topPt !== null => $areaTop - $p->topPt,
            $p->bottomPt !== null => $areaBottom + $p->bottomPt + $height,
            default => $areaTop,
        };

        $inner = new LayoutContext(
            pdf: $ctx->pdf,
            currentPage: $ctx->currentPage,
            cursorY: $top,
            leftX: $x,
            contentWidth: $width,
            bottomY: $top - max($height, 1.0) - 1.0,
            topY: $top,
            pageSetup: $setup,
            skipParagraphTag: $ctx->skipParagraphTag,
            // No page breaks out of here: what does not fit is cut.
            inHeaderFooterRender: true,
        );
        foreach ($p->children as $child) {
            $this->renderBlock($child, $inner);
        }
    }

    /**
     * Math expression block.
     */
    private function renderMathExpression(MathExpression $math, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $math->spaceBeforePt;

        // Parse as multi-line — split on \\\\.
        $rows = \Dskripchenko\PhpPdf\Math\MathRenderer::parseLines($math->tex);
        // Custom font family if specified.
        $font = $this->defaultFont ?? $this->fallbackStandard;
        if ($math->fontFamily !== null && $this->resolver !== null) {
            $resolved = $this->resolver->resolve($math->fontFamily);
            if ($resolved !== null) {
                $font = $resolved;
            }
        }
        $rowHeight = $math->fontSizePt * 1.6;
        $totalHeight = $rowHeight * count($rows);
        $this->ensureRoomFor($ctx, $totalHeight);

        foreach ($rows as $rowTokens) {
            $rowW = \Dskripchenko\PhpPdf\Math\MathRenderer::measureWidth($rowTokens, $math->fontSizePt, $font);
            $x = match ($math->alignment) {
                Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $rowW) / 2,
                Alignment::End => $ctx->leftX + $ctx->contentWidth - $rowW,
                default => $ctx->leftX,
            };
            $baseline = $ctx->cursorY - $math->fontSizePt;
            \Dskripchenko\PhpPdf\Math\MathRenderer::render($rowTokens, $ctx->currentPage, $x, $baseline, $math->fontSizePt, $font);
            $ctx->cursorY -= $rowHeight;
        }

        $ctx->cursorY -= $math->spaceAfterPt;
    }

    /**
     * Heading — paragraph with auto-styled bold/larger font;
     * in tagged PDF mode emits as /H1.../H6 struct element.
     */
    private function renderHeading(Heading $h, LayoutContext $ctx): void
    {
        $taggedPdf = $ctx->pdf->isTagged();
        $mcid = null;
        if ($taggedPdf) {
            $mcid = $ctx->currentPage->nextMcid();
            $ctx->currentPage->beginMarkedContent('H'.$h->level, $mcid);
        }

        // Register named destination if heading has an anchor.
        // Position = current cursor (top of heading line).
        if ($h->anchor !== null && $h->anchor !== '') {
            $ctx->pdf->registerDestination(
                $h->anchor,
                $ctx->currentPage,
                $ctx->leftX,
                $ctx->cursorY,
            );
        }

        $p = $this->headingParagraph($h);
        $this->renderParagraphNoTag($p, $ctx);

        if ($taggedPdf && $mcid !== null) {
            $ctx->currentPage->endMarkedContent();
            $ctx->pdf->addStructElement('H'.$h->level, $mcid, $ctx->currentPage);
        }
    }

    /**
     * The paragraph a heading is drawn as: bold, at the level's size unless
     * a run sets its own (HTML: `h1 { font-size: … }`).
     */
    private function headingParagraph(Heading $h): Paragraph
    {
        $size = $h->defaultFontSizePt();
        $paraStyle = $h->style
            ?? new \Dskripchenko\PhpPdf\Style\ParagraphStyle(
                spaceBeforePt: 12.0,
                spaceAfterPt: 6.0,
            );

        $children = [];
        foreach ($h->children as $child) {
            if ($child instanceof Run) {
                $style = $child->style->withBold(true);
                $children[] = new Run($child->text, $style->sizePt === null ? $style->withSizePt($size) : $style);
            } else {
                $children[] = $child;
            }
        }

        return new Paragraph($children, $paraStyle);
    }

    /**
     * Internal helper — render Paragraph WITHOUT wrapping in tagged
     * BDC/EMC (used by renderHeading which manages tagging itself).
     */
    private function renderParagraphNoTag(Paragraph $p, LayoutContext $ctx): void
    {
        $wasTagged = $ctx->pdf->isTagged();
        // Temporarily mask tagged mode so renderParagraph does not emit /P.
        // Self-tracked through a property doesn't exist; use a reflection-free
        // approach: swap pdf's field? That would be invasive. Simpler: emit raw
        // operators directly.
        // Workaround — flag via context (mark current paragraph as already-tagged).
        $ctx->skipParagraphTag = true;
        $this->renderParagraph($p, $ctx);
        $ctx->skipParagraphTag = false;
    }

    /**
     * Donut chart — pie with inner hole.
     */
    private function renderDonutChart(DonutChart $dc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $dc->spaceBeforePt;
        $totalW = min($dc->sizePt, $ctx->contentWidth);
        $totalH = $totalW;
        $legendW = $dc->showLegend ? 80.0 : 0;
        $legendW = min($legendW, max(0, $ctx->contentWidth - $totalW));
        $blockW = $totalW + $legendW;
        $this->ensureRoomFor($ctx, $totalH + ($dc->title !== null ? $dc->titleSizePt + 4 : 0));

        $blockX = match ($dc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $blockW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $blockW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;

        if ($dc->title !== null) {
            $titleW = $this->chartTextWidth($dc->title, $dc->titleSizePt);
            $this->chartText($ctx->currentPage, $dc->title,
                $blockX + ($blockW - $titleW) / 2, $topY - $dc->titleSizePt, $dc->titleSizePt);
            $topY -= $dc->titleSizePt + 4;
        }

        $cx = $blockX + $totalW / 2;
        $cy = $topY - $totalW / 2;
        $outerRadius = $totalW / 2 * 0.9;
        $innerRadius = $outerRadius * $dc->innerRatio;

        $total = 0.0;
        foreach ($dc->slices as $s) {
            $total += $s['value'];
        }
        if ($total <= 0) {
            $total = 1.0;
        }

        $colorWheel = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0', 'f542a7', '8c8c8c'];
        $angle = -M_PI / 2;
        $segmentsPerFullCircle = 60;

        foreach ($dc->slices as $idx => $slice) {
            $sliceAngle = ($slice['value'] / $total) * 2 * M_PI;
            $segments = max(1, (int) ceil($sliceAngle / (2 * M_PI) * $segmentsPerFullCircle));
            // Ring sector: outer arc + inner arc reverse.
            $points = [];
            for ($i = 0; $i <= $segments; $i++) {
                $a = $angle + ($sliceAngle * $i / $segments);
                $points[] = [$cx + cos($a) * $outerRadius, $cy + sin($a) * $outerRadius];
            }
            for ($i = $segments; $i >= 0; $i--) {
                $a = $angle + ($sliceAngle * $i / $segments);
                $points[] = [$cx + cos($a) * $innerRadius, $cy + sin($a) * $innerRadius];
            }
            $hex = $slice['color'] ?? $colorWheel[$idx % count($colorWheel)];
            [$r, $g, $b] = $this->hexToRgb($hex);
            $ctx->currentPage->fillPolygon($points, $r, $g, $b);
            $angle += $sliceAngle;
        }

        // Legend.
        if ($dc->showLegend && $legendW > 0) {
            $legendX = $blockX + $totalW + 6;
            $legendY = $topY - 4;
            foreach ($dc->slices as $idx => $slice) {
                $hex = $slice['color'] ?? $colorWheel[$idx % count($colorWheel)];
                [$r, $g, $b] = $this->hexToRgb($hex);
                $ctx->currentPage->fillRect($legendX, $legendY - $dc->legendSizePt, $dc->legendSizePt, $dc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $slice['label'],
                    $legendX + $dc->legendSizePt + 4, $legendY - $dc->legendSizePt + 1, $dc->legendSizePt);
                $legendY -= $dc->legendSizePt + 4;
            }
        }

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $dc->spaceAfterPt;
    }

    /**
     * Area chart — line with filled regions. Stacked mode accumulates
     * values from previous series.
     */
    private function renderAreaChart(AreaChart $ac, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $ac->spaceBeforePt;
        $totalW = min($ac->widthPt, $ctx->contentWidth);
        $totalH = $ac->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($ac->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $ac->title !== null ? $ac->titleSizePt + 4.0 : 0;
        $labelStripH = $ac->axisLabelSizePt + 4.0;
        $legendStripH = $ac->showLegend ? $ac->legendSizePt + 6.0 : 0;
        $axisLabelPaddingLeft = 32.0;

        $plotTop = $topY - $titleStripH - $legendStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($ac->title !== null) {
            $titleW = $this->chartTextWidth($ac->title, $ac->titleSizePt);
            $this->chartText($ctx->currentPage, $ac->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $ac->titleSizePt, $ac->titleSizePt);
        }

        $defaultColors = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0'];

        $n = count($ac->xLabels);
        $nSeries = count($ac->series);

        // Compute max value (stacked → sum per x; otherwise → max per series).
        $maxValue = 0.0;
        if ($ac->stacked) {
            for ($i = 0; $i < $n; $i++) {
                $colSum = 0.0;
                foreach ($ac->series as $s) {
                    $colSum += max(0.0, $s['values'][$i]);
                }
                if ($colSum > $maxValue) {
                    $maxValue = $colSum;
                }
            }
        } else {
            foreach ($ac->series as $s) {
                foreach ($s['values'] as $v) {
                    if ($v > $maxValue) {
                        $maxValue = $v;
                    }
                }
            }
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }
        // yMax override.
        if ($ac->yMax !== null) {
            $maxValue = $ac->yMax;
        }

        // Legend at top.
        if ($ac->showLegend) {
            $legendY = $topY - $titleStripH - $ac->legendSizePt;
            $legendX = $blockX + $axisLabelPaddingLeft;
            foreach ($ac->series as $idx => $s) {
                $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
                [$r, $g, $b] = $this->hexToRgb($color);
                $ctx->currentPage->fillRect($legendX, $legendY, $ac->legendSizePt, $ac->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $s['name'],
                    $legendX + $ac->legendSizePt + 3, $legendY + 1, $ac->legendSizePt);
                $legendX += $ac->legendSizePt + 3
                    + ($this->chartTextWidth($s['name'], $ac->legendSizePt))
                    + 14;
            }
        }

        // Grid lines.
        if ($ac->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        $maxLabel = $this->formatChartNumber($maxValue);
        $maxLabelW = $this->chartTextWidth($maxLabel, $ac->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel,
            $plotLeft - $maxLabelW - 2, $plotTop - $ac->axisLabelSizePt * 0.5, $ac->axisLabelSizePt);

        $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
        $acRotRad = $ac->xLabelRotationDeg * M_PI / 180.0;
        $acRotated = abs($ac->xLabelRotationDeg) > 0.01;

        // X-axis labels.
        foreach ($ac->xLabels as $i => $label) {
            $x = $plotLeft + $i * $stepX;
            $labelW = $this->chartTextWidth($label, $ac->axisLabelSizePt);
            if ($acRotated) {
                $this->chartTextRotated($ctx->currentPage, $label, $x, $plotBottom - 2.0, $labelW, $ac->axisLabelSizePt, $acRotRad);
            } else {
                $this->chartText($ctx->currentPage, $label,
                    $x - $labelW / 2, $plotBottom - $ac->axisLabelSizePt - 2, $ac->axisLabelSizePt);
            }
        }

        // Build series y-tops; for stacked — cumulative bottoms.
        $cumulativeBottoms = array_fill(0, $n, $plotBottom);
        foreach ($ac->series as $idx => $s) {
            $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
            [$r, $g, $b] = $this->hexToRgb($color);

            $topPoints = [];
            for ($i = 0; $i < $n; $i++) {
                $x = $plotLeft + $i * $stepX;
                $value = $s['values'][$i];
                if ($ac->stacked) {
                    $y = $cumulativeBottoms[$i] + ($value / $maxValue) * $plotH;
                } else {
                    $y = $plotBottom + ($value / $maxValue) * $plotH;
                }
                $topPoints[] = [$x, $y];
            }

            // Build polygon: top points (left → right) + bottom (right → left).
            $poly = $topPoints;
            for ($i = $n - 1; $i >= 0; $i--) {
                $x = $plotLeft + $i * $stepX;
                $poly[] = [$x, $cumulativeBottoms[$i]];
            }
            $ctx->currentPage->fillPolygon($poly, $r, $g, $b);

            // Stroke the top line to reinforce border.
            $ctx->currentPage->strokePolyline($topPoints, 1.0, $r * 0.7, $g * 0.7, $b * 0.7);

            if ($ac->stacked) {
                for ($i = 0; $i < $n; $i++) {
                    $cumulativeBottoms[$i] = $topPoints[$i][1];
                }
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $ac->xAxisTitle, $ac->yAxisTitle, $ac->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $ac->spaceAfterPt;
    }

    /**
     * Scatter chart — discrete points, no connecting lines.
     */
    private function renderScatterChart(ScatterChart $sc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $sc->spaceBeforePt;
        $totalW = min($sc->widthPt, $ctx->contentWidth);
        $totalH = $sc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($sc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $sc->title !== null ? $sc->titleSizePt + 4.0 : 0;
        $labelStripH = $sc->axisLabelSizePt + 4.0;
        $legendStripH = $sc->showLegend ? $sc->legendSizePt + 6.0 : 0;
        $axisLabelPaddingLeft = 36.0;

        $plotTop = $topY - $titleStripH - $legendStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($sc->title !== null) {
            $titleW = $this->chartTextWidth($sc->title, $sc->titleSizePt);
            $this->chartText($ctx->currentPage, $sc->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $sc->titleSizePt, $sc->titleSizePt);
        }

        // Auto-scale: find x/y min/max across all series.
        $xMin = INF; $xMax = -INF; $yMin = INF; $yMax = -INF;
        foreach ($sc->series as $s) {
            foreach ($s['points'] as $p) {
                if ($p['x'] < $xMin) $xMin = $p['x'];
                if ($p['x'] > $xMax) $xMax = $p['x'];
                if ($p['y'] < $yMin) $yMin = $p['y'];
                if ($p['y'] > $yMax) $yMax = $p['y'];
            }
        }
        if ($xMin === $xMax) { $xMax = $xMin + 1; }
        if ($yMin === $yMax) { $yMax = $yMin + 1; }

        $defaultColors = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0'];

        // Legend at top.
        if ($sc->showLegend) {
            $legendY = $topY - $titleStripH - $sc->legendSizePt;
            $legendX = $blockX + $axisLabelPaddingLeft;
            foreach ($sc->series as $idx => $s) {
                $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
                [$r, $g, $b] = $this->hexToRgb($color);
                $ctx->currentPage->fillRect($legendX, $legendY, $sc->legendSizePt, $sc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $s['name'],
                    $legendX + $sc->legendSizePt + 3, $legendY + 1, $sc->legendSizePt);
                $legendX += $sc->legendSizePt + 3
                    + ($this->chartTextWidth($s['name'], $sc->legendSizePt))
                    + 14;
            }
        }

        // Grid lines.
        if ($sc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        // Axis labels (min + max for X, max for Y).
        $this->chartText($ctx->currentPage, $this->formatChartNumber($xMin),
            $plotLeft, $plotBottom - $sc->axisLabelSizePt - 2, $sc->axisLabelSizePt);
        $xMaxLabel = $this->formatChartNumber($xMax);
        $xMaxW = $this->chartTextWidth($xMaxLabel, $sc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $xMaxLabel,
            $plotRight - $xMaxW, $plotBottom - $sc->axisLabelSizePt - 2, $sc->axisLabelSizePt);

        $yMaxLabel = $this->formatChartNumber($yMax);
        $yMaxW = $this->chartTextWidth($yMaxLabel, $sc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $yMaxLabel,
            $plotLeft - $yMaxW - 2, $plotTop - $sc->axisLabelSizePt * 0.5, $sc->axisLabelSizePt);

        // Points.
        $half = $sc->markerSize / 2;
        foreach ($sc->series as $idx => $s) {
            $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
            [$r, $g, $b] = $this->hexToRgb($color);
            foreach ($s['points'] as $p) {
                $x = $plotLeft + (($p['x'] - $xMin) / ($xMax - $xMin)) * $plotW;
                $y = $plotBottom + (($p['y'] - $yMin) / ($yMax - $yMin)) * $plotH;
                $ctx->currentPage->fillRect($x - $half, $y - $half, $sc->markerSize, $sc->markerSize, $r, $g, $b);
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $sc->xAxisTitle, $sc->yAxisTitle, $sc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $sc->spaceAfterPt;
    }

    /**
     * SVG block — delegates SvgRenderer for translating
     * SVG primitives → PDF native paths.
     */
    private function renderSvgElement(SvgElement $svg, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $svg->spaceBeforePt;
        $w = min($svg->widthPt, $ctx->contentWidth);
        $h = $svg->heightPt;
        $this->ensureRoomFor($ctx, $h);

        $blockX = match ($svg->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $w) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $w,
            default => $ctx->leftX,
        };
        $blockY = $ctx->cursorY - $h;

        // SVG text takes the document's fonts — the family chain and the
        // fallbacks — like any other run: a base-14 face has no Cyrillic.
        \Dskripchenko\PhpPdf\Svg\SvgRenderer::render(
            $svg->svgXml, $ctx->currentPage, $blockX, $blockY, $w, $h,
            function (?string $family, bool $bold, bool $italic, string $text): PdfFont|StandardFont {
                $style = new RunStyle(fontFamily: $family, bold: $bold, italic: $italic);

                return $this->resolveEmbeddedFont($style, $text) ?? $this->standardFontFor($style);
            },
        );

        $ctx->cursorY -= $h;
        $ctx->cursorY -= $svg->spaceAfterPt;
    }

    /**
     * Grouped bar chart — N bars per label, side-by-side.
     */
    private function renderGroupedBarChart(GroupedBarChart $gbc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $gbc->spaceBeforePt;
        $totalW = min($gbc->widthPt, $ctx->contentWidth);
        $totalH = $gbc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($gbc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $gbc->title !== null ? $gbc->titleSizePt + 4.0 : 0;
        $labelStripH = $gbc->axisLabelSizePt + 4.0;
        $legendStripH = $gbc->showLegend ? $gbc->legendSizePt + 6.0 : 0;
        $axisLabelPaddingLeft = 32.0;

        $plotTop = $topY - $titleStripH - $legendStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($gbc->title !== null) {
            $titleW = $this->chartTextWidth($gbc->title, $gbc->titleSizePt);
            $this->chartText($ctx->currentPage, $gbc->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $gbc->titleSizePt, $gbc->titleSizePt);
        }

        $defaultColors = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0'];
        $colors = $gbc->seriesColors !== []
            ? array_pad($gbc->seriesColors, count($gbc->seriesNames), '8c8c8c')
            : array_slice($defaultColors, 0, count($gbc->seriesNames));

        // Legend at top.
        if ($gbc->showLegend) {
            $legendY = $topY - $titleStripH - $gbc->legendSizePt;
            $legendX = $blockX + $axisLabelPaddingLeft;
            foreach ($gbc->seriesNames as $idx => $name) {
                [$r, $g, $b] = $this->hexToRgb($colors[$idx]);
                $ctx->currentPage->fillRect($legendX, $legendY, $gbc->legendSizePt, $gbc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $name,
                    $legendX + $gbc->legendSizePt + 3, $legendY + 1, $gbc->legendSizePt);
                $legendX += $gbc->legendSizePt + 3
                    + ($this->chartTextWidth($name, $gbc->legendSizePt))
                    + 14;
            }
        }

        // Max value scan.
        $maxValue = 0.0;
        foreach ($gbc->bars as $bar) {
            foreach ($bar['values'] as $v) {
                if ($v > $maxValue) {
                    $maxValue = $v;
                }
            }
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        // Grid lines.
        if ($gbc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        $maxLabel = $this->formatChartNumber($maxValue);
        $maxLabelW = $this->chartTextWidth($maxLabel, $gbc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel, $plotLeft - $maxLabelW - 2, $plotTop - $gbc->axisLabelSizePt * 0.5, $gbc->axisLabelSizePt);

        // Bars.
        $nLabels = count($gbc->bars);
        $nSeries = count($gbc->seriesNames);
        $slotW = $plotW / max($nLabels, 1);
        $groupPadding = 0.2; // 20% horizontal gap between groups.
        $groupW = $slotW * (1 - $groupPadding);
        $barW = $groupW / $nSeries;

        $gbcRotRad = $gbc->xLabelRotationDeg * M_PI / 180.0;
        $gbcRotated = abs($gbc->xLabelRotationDeg) > 0.01;
        foreach ($gbc->bars as $bi => $bar) {
            $slotLeftX = $plotLeft + $bi * $slotW + ($slotW - $groupW) / 2;
            foreach ($bar['values'] as $si => $value) {
                $h = ($value / $maxValue) * $plotH;
                $x = $slotLeftX + $si * $barW;
                $y = $plotBottom;
                [$r, $g, $b] = $this->hexToRgb($colors[$si]);
                $ctx->currentPage->fillRect($x, $y, $barW * 0.9, $h, $r, $g, $b);
            }
            // X-axis label.
            $label = $bar['label'];
            $labelW = $this->chartTextWidth($label, $gbc->axisLabelSizePt);
            if ($gbcRotated) {
                $anchorX = $slotLeftX + $groupW / 2;
                $this->chartTextRotated($ctx->currentPage, $label, $anchorX, $plotBottom - 2.0, $labelW, $gbc->axisLabelSizePt, $gbcRotRad);
            } else {
                $labelX = $slotLeftX + ($groupW - $labelW) / 2;
                $this->chartText($ctx->currentPage, $label,
                    $labelX, $plotBottom - $gbc->axisLabelSizePt - 2, $gbc->axisLabelSizePt);
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $gbc->xAxisTitle, $gbc->yAxisTitle, $gbc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $gbc->spaceAfterPt;
    }

    /**
     * Stacked bar chart — segments stacked vertically per category.
     */
    private function renderStackedBarChart(StackedBarChart $sbc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $sbc->spaceBeforePt;
        $totalW = min($sbc->widthPt, $ctx->contentWidth);
        $totalH = $sbc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($sbc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $sbc->title !== null ? $sbc->titleSizePt + 4.0 : 0;
        $labelStripH = $sbc->axisLabelSizePt + 4.0;
        $legendStripH = $sbc->showLegend ? $sbc->legendSizePt + 6.0 : 0;
        $axisLabelPaddingLeft = 32.0;

        $plotTop = $topY - $titleStripH - $legendStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($sbc->title !== null) {
            $titleW = $this->chartTextWidth($sbc->title, $sbc->titleSizePt);
            $this->chartText($ctx->currentPage, $sbc->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $sbc->titleSizePt, $sbc->titleSizePt);
        }

        $defaultColors = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0'];
        $colors = $sbc->seriesColors !== []
            ? array_pad($sbc->seriesColors, count($sbc->seriesNames), '8c8c8c')
            : array_slice($defaultColors, 0, count($sbc->seriesNames));

        // Legend at top.
        if ($sbc->showLegend) {
            $legendY = $topY - $titleStripH - $sbc->legendSizePt;
            $legendX = $blockX + $axisLabelPaddingLeft;
            foreach ($sbc->seriesNames as $idx => $name) {
                [$r, $g, $b] = $this->hexToRgb($colors[$idx]);
                $ctx->currentPage->fillRect($legendX, $legendY, $sbc->legendSizePt, $sbc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $name,
                    $legendX + $sbc->legendSizePt + 3, $legendY + 1, $sbc->legendSizePt);
                $legendX += $sbc->legendSizePt + 3
                    + ($this->chartTextWidth($name, $sbc->legendSizePt))
                    + 14;
            }
        }

        // Compute max stacked total per category for scale.
        $maxTotal = 0.0;
        foreach ($sbc->bars as $bar) {
            $sum = 0.0;
            foreach ($bar['values'] as $v) {
                $sum += max(0, $v);
            }
            if ($sum > $maxTotal) {
                $maxTotal = $sum;
            }
        }
        if ($maxTotal <= 0) {
            $maxTotal = 1.0;
        }

        // Grid lines.
        if ($sbc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        $maxLabel = $this->formatChartNumber($maxTotal);
        $maxLabelW = $this->chartTextWidth($maxLabel, $sbc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel, $plotLeft - $maxLabelW - 2, $plotTop - $sbc->axisLabelSizePt * 0.5, $sbc->axisLabelSizePt);

        // Bars — each category = stacked segments.
        $nLabels = count($sbc->bars);
        $slotW = $plotW / max($nLabels, 1);
        $gapRatio = 0.3;
        $barW = $slotW * (1 - $gapRatio);

        $sbcRotRad = $sbc->xLabelRotationDeg * M_PI / 180.0;
        $sbcRotated = abs($sbc->xLabelRotationDeg) > 0.01;
        foreach ($sbc->bars as $bi => $bar) {
            $barX = $plotLeft + $bi * $slotW + ($slotW - $barW) / 2;
            $stackY = $plotBottom;
            foreach ($bar['values'] as $si => $value) {
                if ($value <= 0) {
                    continue;
                }
                $segH = ($value / $maxTotal) * $plotH;
                [$r, $g, $b] = $this->hexToRgb($colors[$si]);
                $ctx->currentPage->fillRect($barX, $stackY, $barW, $segH, $r, $g, $b);
                $stackY += $segH;
            }
            // X-axis label.
            $label = $bar['label'];
            $labelW = $this->chartTextWidth($label, $sbc->axisLabelSizePt);
            if ($sbcRotated) {
                $this->chartTextRotated($ctx->currentPage, $label, $barX + $barW / 2, $plotBottom - 2.0, $labelW, $sbc->axisLabelSizePt, $sbcRotRad);
            } else {
                $labelX = $barX + ($barW - $labelW) / 2;
                $this->chartText($ctx->currentPage, $label,
                    $labelX, $plotBottom - $sbc->axisLabelSizePt - 2, $sbc->axisLabelSizePt);
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $sbc->xAxisTitle, $sbc->yAxisTitle, $sbc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $sbc->spaceAfterPt;
    }

    /**
     * Multi-series line chart.
     */
    private function renderMultiLineChart(MultiLineChart $mlc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $mlc->spaceBeforePt;
        $totalW = min($mlc->widthPt, $ctx->contentWidth);
        $totalH = $mlc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($mlc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $mlc->title !== null ? $mlc->titleSizePt + 4.0 : 0;
        $labelStripH = $mlc->axisLabelSizePt + 4.0;
        $legendStripH = $mlc->showLegend ? $mlc->legendSizePt + 6.0 : 0;
        $axisLabelPaddingLeft = 32.0;

        $plotTop = $topY - $titleStripH - $legendStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($mlc->title !== null) {
            $titleW = $this->chartTextWidth($mlc->title, $mlc->titleSizePt);
            $this->chartText($ctx->currentPage, $mlc->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $mlc->titleSizePt, $mlc->titleSizePt);
        }

        $defaultColors = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0'];

        // Max value scan.
        $maxValue = 0.0;
        foreach ($mlc->series as $s) {
            foreach ($s['values'] as $v) {
                if ($v > $maxValue) {
                    $maxValue = $v;
                }
            }
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        // Legend at top.
        if ($mlc->showLegend) {
            $legendY = $topY - $titleStripH - $mlc->legendSizePt;
            $legendX = $blockX + $axisLabelPaddingLeft;
            foreach ($mlc->series as $idx => $s) {
                $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
                [$r, $g, $b] = $this->hexToRgb($color);
                $ctx->currentPage->fillRect($legendX, $legendY, $mlc->legendSizePt, $mlc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $s['name'],
                    $legendX + $mlc->legendSizePt + 3, $legendY + 1, $mlc->legendSizePt);
                $legendX += $mlc->legendSizePt + 3
                    + ($this->chartTextWidth($s['name'], $mlc->legendSizePt))
                    + 14;
            }
        }

        // Grid lines.
        if ($mlc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        $maxLabel = $this->formatChartNumber($maxValue);
        $maxLabelW = $this->chartTextWidth($maxLabel, $mlc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel, $plotLeft - $maxLabelW - 2, $plotTop - $mlc->axisLabelSizePt * 0.5, $mlc->axisLabelSizePt);

        $n = count($mlc->xLabels);
        $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
        $mlcRotRad = $mlc->xLabelRotationDeg * M_PI / 180.0;
        $mlcRotated = abs($mlc->xLabelRotationDeg) > 0.01;

        // X-axis labels.
        foreach ($mlc->xLabels as $i => $label) {
            $x = $plotLeft + $i * $stepX;
            $labelW = $this->chartTextWidth($label, $mlc->axisLabelSizePt);
            if ($mlcRotated) {
                $this->chartTextRotated($ctx->currentPage, $label, $x, $plotBottom - 2.0, $labelW, $mlc->axisLabelSizePt, $mlcRotRad);
            } else {
                $this->chartText($ctx->currentPage, $label,
                    $x - $labelW / 2, $plotBottom - $mlc->axisLabelSizePt - 2, $mlc->axisLabelSizePt);
            }
        }

        // Each series → polyline.
        foreach ($mlc->series as $idx => $s) {
            $color = $s['color'] ?? $defaultColors[$idx % count($defaultColors)];
            [$lr, $lg, $lb] = $this->hexToRgb($color);
            $coords = [];
            foreach ($s['values'] as $i => $v) {
                $x = $plotLeft + $i * $stepX;
                $y = $plotBottom + ($v / $maxValue) * $plotH;
                $coords[] = [$x, $y];
            }
            $ctx->currentPage->strokePolyline($coords, 1.5, $lr, $lg, $lb);
            if ($mlc->showMarkers) {
                foreach ($coords as $c) {
                    $ctx->currentPage->fillRect($c[0] - 1.5, $c[1] - 1.5, 3, 3, $lr, $lg, $lb);
                }
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $mlc->xAxisTitle, $mlc->yAxisTitle, $mlc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $mlc->spaceAfterPt;
    }

    /**
     * Line chart — strokePolyline through data points + axes.
     */
    private function renderLineChart(LineChart $lc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $lc->spaceBeforePt;
        $totalW = min($lc->widthPt, $ctx->contentWidth);
        $totalH = $lc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($lc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        $titleStripH = $lc->title !== null ? $lc->titleSizePt + 4.0 : 0;
        $labelStripH = $lc->axisLabelSizePt + 4.0;
        $axisLabelPaddingLeft = 32.0;

        $plotTop = $topY - $titleStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        if ($lc->title !== null) {
            $titleW = $this->chartTextWidth($lc->title, $lc->titleSizePt);
            $this->chartText($ctx->currentPage, $lc->title,
                $blockX + ($totalW - $titleW) / 2, $topY - $lc->titleSizePt, $lc->titleSizePt);
        }

        // Max value scaling.
        $maxValue = 0.0;
        foreach ($lc->points as $p) {
            if ($p['value'] > $maxValue) {
                $maxValue = $p['value'];
            }
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }
        // yMax override.
        if ($lc->yMax !== null) {
            $maxValue = $lc->yMax;
        }

        // Grid lines.
        if ($lc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Axes.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        $maxLabel = $this->formatChartNumber($maxValue);
        $maxLabelW = $this->chartTextWidth($maxLabel, $lc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel, $plotLeft - $maxLabelW - 2, $plotTop - $lc->axisLabelSizePt * 0.5, $lc->axisLabelSizePt);

        $n = count($lc->points);
        $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
        [$lr, $lg, $lb] = $this->hexToRgb($lc->lineColor);

        // Compute polyline points.
        $coords = [];
        $lcRotRad = $lc->xLabelRotationDeg * M_PI / 180.0;
        $lcRotated = abs($lc->xLabelRotationDeg) > 0.01;
        foreach ($lc->points as $i => $p) {
            $x = $plotLeft + $i * $stepX;
            $y = $plotBottom + ($p['value'] / $maxValue) * $plotH;
            $coords[] = [$x, $y];

            // Label below x-axis.
            $label = $p['label'];
            $labelW = $this->chartTextWidth($label, $lc->axisLabelSizePt);
            if ($lcRotated) {
                $this->chartTextRotated($ctx->currentPage, $label, $x, $plotBottom - 2.0, $labelW, $lc->axisLabelSizePt, $lcRotRad);
            } else {
                $this->chartText($ctx->currentPage, $label,
                    $x - $labelW / 2, $plotBottom - $lc->axisLabelSizePt - 2, $lc->axisLabelSizePt);
            }
        }

        // Smoothed (Catmull-Rom → cubic Bezier) or straight polyline.
        if ($lc->smoothed && count($coords) >= 2) {
            $commands = self::catmullRomToBezierPath($coords);
            $ctx->currentPage->emitPath(
                $commands,
                'stroke',
                strokeRgb: ['r' => $lr, 'g' => $lg, 'b' => $lb],
                lineWidthPt: 1.5,
            );
        } else {
            $ctx->currentPage->strokePolyline($coords, 1.5, $lr, $lg, $lb);
        }

        // Markers (filled small rects at points — fast instead of circles).
        if ($lc->showMarkers) {
            foreach ($coords as $c) {
                $ctx->currentPage->fillRect($c[0] - 1.5, $c[1] - 1.5, 3, 3, $lr, $lg, $lb);
            }
        }

        $this->drawChartAxisTitles(
            $ctx->currentPage, $lc->xAxisTitle, $lc->yAxisTitle, $lc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $lc->spaceAfterPt;
    }

    /**
     * Pie chart — slices via Bezier arcs (with polygon fallback historically).
     */
    private function renderPieChart(PieChart $pc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $pc->spaceBeforePt;
        $totalW = min($pc->sizePt, $ctx->contentWidth);
        $totalH = $totalW;

        // Legend takes extra horizontal space to the right if shown.
        $legendW = $pc->showLegend ? 80.0 : 0;
        $legendW = min($legendW, max(0, $ctx->contentWidth - $totalW));
        $blockW = $totalW + $legendW;
        $this->ensureRoomFor($ctx, $totalH + ($pc->title !== null ? $pc->titleSizePt + 4 : 0));

        $blockX = match ($pc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $blockW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $blockW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;

        if ($pc->title !== null) {
            $titleW = $this->chartTextWidth($pc->title, $pc->titleSizePt);
            $this->chartText($ctx->currentPage, $pc->title,
                $blockX + ($blockW - $titleW) / 2, $topY - $pc->titleSizePt, $pc->titleSizePt);
            $topY -= $pc->titleSizePt + 4;
        }

        $cx = $blockX + $totalW / 2;
        $cy = $topY - $totalW / 2;
        $radius = $totalW / 2 * 0.9;

        $total = 0.0;
        foreach ($pc->slices as $s) {
            $total += $s['value'];
        }
        if ($total <= 0) {
            $total = 1.0;
        }

        $colorWheel = ['4287f5', 'f56242', '42f55a', 'f5e042', 'b042f5', '42f5e0', 'f542a7', '8c8c8c'];
        $angle = -M_PI / 2; // start at top.

        // Cubic Bezier arc rendering instead of polygon approximation.
        // Each slice as a PDF path: M center → L arc-start → C ... (Bezier arcs)
        // → close → fill. Sub-arc cap 90° for accuracy. k = 4/3*tan(θ/4)*r.
        foreach ($pc->slices as $idx => $slice) {
            $sliceAngle = ($slice['value'] / $total) * 2 * M_PI;
            // Explode offset — sliceCx/Cy shifted radially when slice.explode is set.
            $explode = $slice['explode'] ?? false;
            $offsetFraction = is_float($explode) ? $explode : ($explode === true ? 0.08 : 0.0);
            $sliceCx = $cx;
            $sliceCy = $cy;
            if ($offsetFraction > 0) {
                $midAngle = $angle + $sliceAngle / 2;
                $offsetDist = $radius * $offsetFraction;
                $sliceCx = $cx + cos($midAngle) * $offsetDist;
                $sliceCy = $cy + sin($midAngle) * $offsetDist;
            }
            $arcStartX = $sliceCx + cos($angle) * $radius;
            $arcStartY = $sliceCy + sin($angle) * $radius;
            $commands = [
                ['M', $sliceCx, $sliceCy],
                ['L', $arcStartX, $arcStartY],
            ];
            // Subdivide slice angle into chunks ≤ 90°.
            $subArcs = max(1, (int) ceil($sliceAngle / (M_PI / 2)));
            $perArc = $sliceAngle / $subArcs;
            $a0 = $angle;
            for ($k = 0; $k < $subArcs; $k++) {
                $a1 = $a0 + $perArc;
                // Bezier control distance: 4/3 * tan(θ/4) * r
                $k_factor = (4.0 / 3.0) * tan($perArc / 4.0) * $radius;
                $p0x = $sliceCx + cos($a0) * $radius;
                $p0y = $sliceCy + sin($a0) * $radius;
                $p3x = $sliceCx + cos($a1) * $radius;
                $p3y = $sliceCy + sin($a1) * $radius;
                // C1 = P0 + tangent at P0 (perpendicular to radius, rotation direction).
                $p1x = $p0x + cos($a0 + M_PI / 2) * $k_factor;
                $p1y = $p0y + sin($a0 + M_PI / 2) * $k_factor;
                // C2 = P3 - tangent at P3
                $p2x = $p3x - cos($a1 + M_PI / 2) * $k_factor;
                $p2y = $p3y - sin($a1 + M_PI / 2) * $k_factor;
                $commands[] = ['C', $p1x, $p1y, $p2x, $p2y, $p3x, $p3y];
                $a0 = $a1;
            }
            $commands[] = 'Z';
            $hex = $slice['color'] ?? $colorWheel[$idx % count($colorWheel)];
            [$r, $g, $b] = $this->hexToRgb($hex);
            $ctx->currentPage->emitPath($commands, 'fill', fillRgb: ['r' => $r, 'g' => $g, 'b' => $b]);
            $angle += $sliceAngle;
        }

        // Legend.
        if ($pc->showLegend && $legendW > 0) {
            $legendX = $blockX + $totalW + 6;
            $legendY = $topY - 4;
            foreach ($pc->slices as $idx => $slice) {
                $hex = $slice['color'] ?? $colorWheel[$idx % count($colorWheel)];
                [$r, $g, $b] = $this->hexToRgb($hex);
                $ctx->currentPage->fillRect($legendX, $legendY - $pc->legendSizePt, $pc->legendSizePt, $pc->legendSizePt, $r, $g, $b);
                $this->chartText($ctx->currentPage, $slice['label'],
                    $legendX + $pc->legendSizePt + 4, $legendY - $pc->legendSizePt + 1, $pc->legendSizePt);
                $legendY -= $pc->legendSizePt + 4;
            }
        }

        // Perimeter labels with leader lines. For each slice larger
        // than minLabelAngleDeg, draw a line from arc midpoint → outside + label.
        if ($pc->showPerimeterLabels) {
            $minAngleRad = deg2rad($pc->minLabelAngleDeg);
            $a2 = -M_PI / 2;
            foreach ($pc->slices as $idx => $slice) {
                $sliceAng = ($slice['value'] / $total) * 2 * M_PI;
                if ($sliceAng < $minAngleRad) {
                    $a2 += $sliceAng;

                    continue;
                }
                $midAngle = $a2 + $sliceAng / 2;
                // Leader line points: arc midpoint → outer offset.
                $x1 = $cx + cos($midAngle) * $radius;
                $y1 = $cy + sin($midAngle) * $radius;
                $x2 = $cx + cos($midAngle) * ($radius + 8);
                $y2 = $cy + sin($midAngle) * ($radius + 8);
                $ctx->currentPage->strokeLine($x1, $y1, $x2, $y2, 0.5, 0.4, 0.4, 0.4);
                // Label position: extends to the right side if cos>0, to the left if <0.
                $labelText = (string) $slice['label'];
                $labelW = $this->chartTextWidth($labelText, $pc->perimeterLabelSizePt);
                $labelX = cos($midAngle) >= 0 ? $x2 + 2 : $x2 - $labelW - 2;
                $labelY = $y2 - $pc->perimeterLabelSizePt * 0.5;
                $this->chartText($ctx->currentPage, $labelText, $labelX, $labelY, $pc->perimeterLabelSizePt);
                $a2 += $sliceAng;
            }
        }

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $pc->spaceAfterPt;
    }

    /**
     * Bar chart rendering — fillRect for bars, line operators
     * for axes, showText for labels + title.
     *
     * Layout (vertical bars):
     *  - Title at top (if set)
     *  - Plot area: bars rise from x-axis at bottom
     *  - X-axis labels under bars
     *  - Y-axis: leftmost vertical line + max value label at top
     */
    private function renderBarChart(BarChart $bc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $bc->spaceBeforePt;

        $totalW = min($bc->widthPt, $ctx->contentWidth);
        $totalH = $bc->heightPt;
        $this->ensureRoomFor($ctx, $totalH);

        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalW) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalW,
            default => $ctx->leftX,
        };
        $topY = $ctx->cursorY;
        $bottomY = $topY - $totalH;

        // Reserve title strip at top, label strip at bottom.
        $titleStripH = $bc->title !== null ? $bc->titleSizePt + 4.0 : 0;
        $labelStripH = $bc->axisLabelSizePt + 4.0;
        $axisLabelPaddingLeft = 32.0; // Reserve space for y-axis values.

        $plotTop = $topY - $titleStripH;
        $plotBottom = $bottomY + $labelStripH;
        $plotLeft = $blockX + $axisLabelPaddingLeft;
        $plotRight = $blockX + $totalW;
        $plotW = $plotRight - $plotLeft;
        $plotH = $plotTop - $plotBottom;

        // Title.
        if ($bc->title !== null) {
            $titleW = $this->chartTextWidth($bc->title, $bc->titleSizePt);
            $titleX = $blockX + ($totalW - $titleW) / 2;
            $titleY = $topY - $bc->titleSizePt;
            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText($bc->title, $titleX, $titleY, $this->defaultFont, $bc->titleSizePt);
            } else {
                $ctx->currentPage->showText($bc->title, $titleX, $titleY, $this->fallbackStandard, $bc->titleSizePt);
            }
        }

        // Compute max value for scaling.
        $maxValue = 0.0;
        foreach ($bc->bars as $bar) {
            if ($bar['value'] > $maxValue) {
                $maxValue = $bar['value'];
            }
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }
        // Optional explicit y-axis range overrides auto-max.
        if ($bc->yMax !== null) {
            $maxValue = $bc->yMax;
        }

        // Grid lines (if enabled) drawn before bars/lines so they do
        // not overlap data marks.
        if ($bc->showGridLines) {
            $this->drawChartGridLines($ctx->currentPage, $plotLeft, $plotRight, $plotBottom, $plotTop);
        }

        // Y-axis vertical line.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotLeft, $plotTop, 0.5, 0.4, 0.4, 0.4);
        // X-axis horizontal line.
        $ctx->currentPage->strokeLine($plotLeft, $plotBottom, $plotRight, $plotBottom, 0.5, 0.4, 0.4, 0.4);

        // Y-axis max label.
        $maxLabel = $this->formatChartNumber($maxValue);
        $maxLabelW = $this->chartTextWidth($maxLabel, $bc->axisLabelSizePt);
        $this->chartText($ctx->currentPage, $maxLabel, $plotLeft - $maxLabelW - 2, $plotTop - $bc->axisLabelSizePt * 0.5, $bc->axisLabelSizePt);

        // Bars.
        $n = count($bc->bars);
        $gapRatio = 0.3; // 30% gap, 70% bar width.
        $slotW = $plotW / max($n, 1);
        $barW = $slotW * (1 - $gapRatio);

        $rotationRad = $bc->xLabelRotationDeg * M_PI / 180.0;
        $rotated = abs($bc->xLabelRotationDeg) > 0.01;
        foreach ($bc->bars as $i => $bar) {
            $h = ($bar['value'] / $maxValue) * $plotH;
            $x = $plotLeft + $i * $slotW + ($slotW - $barW) / 2;
            $y = $plotBottom;
            $hex = $bar['color'] ?? $bc->defaultBarColor;
            [$r, $g, $b] = $this->hexToRgb($hex);
            $ctx->currentPage->fillRect($x, $y, $barW, $h, $r, $g, $b);

            // X-axis label under bar.
            $label = $bar['label'];
            $labelW = $this->chartTextWidth($label, $bc->axisLabelSizePt);
            if ($rotated) {
                // End-anchor: right end of label at tick position (bar center, just below axis).
                $anchorX = $x + $barW / 2;
                $anchorY = $plotBottom - 2.0;
                $this->chartTextRotated($ctx->currentPage, $label, $anchorX, $anchorY, $labelW, $bc->axisLabelSizePt, $rotationRad);
            } else {
                $labelX = $x + ($barW - $labelW) / 2;
                $this->chartText($ctx->currentPage, $label, $labelX, $plotBottom - $bc->axisLabelSizePt - 2, $bc->axisLabelSizePt);
            }
        }

        // Axis titles.
        $this->drawChartAxisTitles(
            $ctx->currentPage, $bc->xAxisTitle, $bc->yAxisTitle, $bc->axisTitleSizePt,
            $plotLeft, $plotRight, $plotBottom, $plotTop,
        );

        $ctx->cursorY -= $totalH;
        $ctx->cursorY -= $bc->spaceAfterPt;
    }

    /**
     * Draw axis titles. xTitle centered below x-axis labels;
     * yTitle rotated 90° counter-clockwise centered left of y-axis labels.
     */
    private function drawChartAxisTitles(
        \Dskripchenko\PhpPdf\Pdf\Page $page,
        ?string $xTitle, ?string $yTitle, float $sizePt,
        float $plotLeft, float $plotRight, float $plotBottom, float $plotTop,
    ): void {
        $charWidth = $sizePt * 0.5;
        if ($xTitle !== null && $xTitle !== '') {
            $w = mb_strlen($xTitle, 'UTF-8') * $charWidth;
            $cx = $plotLeft + ($plotRight - $plotLeft - $w) / 2;
            $cy = $plotBottom - $sizePt - 14.0;
            $this->chartText($page, $xTitle, $cx, $cy, $sizePt);
        }
        if ($yTitle !== null && $yTitle !== '') {
            $w = mb_strlen($yTitle, 'UTF-8') * $charWidth;
            // Rotated 90° counter-clockwise.
            $cx = $plotLeft - 22.0;
            $cy = $plotBottom + ($plotTop - $plotBottom - $w) / 2;
            if ($this->defaultFont !== null) {
                $page->drawWatermarkEmbedded($yTitle, $cx, $cy, $this->defaultFont, $sizePt, M_PI / 2, 0, 0, 0);
            } else {
                $page->drawWatermark($yTitle, $cx, $cy, $this->fallbackStandard, $sizePt, M_PI / 2, 0, 0, 0);
            }
        }
    }

    /**
     * Draw horizontal grid lines at 25/50/75% between plotBottom
     * and plotTop. Light-gray semi-transparent — visual reference.
     */
    private function drawChartGridLines(\Dskripchenko\PhpPdf\Pdf\Page $page, float $plotLeft, float $plotRight, float $plotBottom, float $plotTop): void
    {
        $h = $plotTop - $plotBottom;
        foreach ([0.25, 0.5, 0.75] as $frac) {
            $y = $plotBottom + $h * $frac;
            $page->strokeLine($plotLeft, $y, $plotRight, $y, 0.3, 0.85, 0.85, 0.85);
        }
    }

    /**
     * Convert a Catmull-Rom spline through points to cubic Bezier
     * path commands. Endpoints virtually duplicated.
     *
     * Per pair (P_i, P_{i+1}), control points:
     *   C1 = P_i + (P_{i+1} - P_{i-1}) / 6
     *   C2 = P_{i+1} - (P_{i+2} - P_i) / 6
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: string, 1: float, 2: float, 3?: float, 4?: float, 5?: float, 6?: float}|string>
     */
    private static function catmullRomToBezierPath(array $points): array
    {
        $n = count($points);
        if ($n < 2) {
            return [];
        }
        $cmds = [['M', $points[0][0], $points[0][1]]];
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $points[$i - 1] ?? $points[0];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[$i + 2] ?? $points[$n - 1];
            $c1x = $p1[0] + ($p2[0] - $p0[0]) / 6;
            $c1y = $p1[1] + ($p2[1] - $p0[1]) / 6;
            $c2x = $p2[0] - ($p3[0] - $p1[0]) / 6;
            $c2y = $p2[1] - ($p3[1] - $p1[1]) / 6;
            $cmds[] = ['C', $c1x, $c1y, $c2x, $c2y, $p2[0], $p2[1]];
        }

        return $cmds;
    }

    private function chartText(\Dskripchenko\PhpPdf\Pdf\Page $page, string $text, float $x, float $y, float $sizePt): void
    {
        if ($this->defaultFont !== null) {
            $page->showEmbeddedText($text, $x, $y, $this->defaultFont, $sizePt);
        } else {
            $page->showText($text, $x, $y, $this->fallbackStandard, $sizePt);
        }
    }

    /**
     * Draw chart label rotated by $angleRad. End-anchor convention:
     * label's natural right-end (in unrotated frame) lands at ($anchorX, $anchorY).
     * Caller computes label width up-front.
     */
    private function chartTextRotated(
        \Dskripchenko\PhpPdf\Pdf\Page $page,
        string $text, float $anchorX, float $anchorY,
        float $labelW, float $sizePt, float $angleRad,
    ): void {
        $cos = cos($angleRad);
        $sin = sin($angleRad);
        $originX = $anchorX - $labelW * $cos;
        $originY = $anchorY - $labelW * $sin;
        if ($this->defaultFont !== null) {
            $page->drawWatermarkEmbedded($text, $originX, $originY, $this->defaultFont, $sizePt, $angleRad, 0, 0, 0);
        } else {
            $page->drawWatermark($text, $originX, $originY, $this->fallbackStandard, $sizePt, $angleRad, 0, 0, 0);
        }
    }

    private function formatChartNumber(float $value): string
    {
        if ($value === floor($value)) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }

    /**
     * AcroForm field widget. Reserves space on the page,
     * draws a visual border (or circles for radio buttons), registers
     * field annotation(s) on the page.
     */
    private function renderFormField(FormField $field, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $field->spaceBeforePt;

        if ($field->type === FormField::TYPE_RADIO_GROUP) {
            $this->renderRadioGroup($field, $ctx);

            return;
        }

        $h = $field->heightPt;
        $w = min($field->widthPt, $ctx->contentWidth);
        $this->ensureRoomFor($ctx, $h);

        $x = $ctx->leftX;
        $y = $ctx->cursorY - $h;

        // Visual hint: thin border (most readers override this with
        // native widget rendering, but the fallback border matters for print).
        $ctx->currentPage->strokeRect($x, $y, $w, $h, 0.5, 0.6, 0.6, 0.6);

        $ctx->currentPage->addFormField(
            type: $field->type,
            name: $field->name,
            x: $x,
            y: $y,
            width: $w,
            height: $h,
            defaultValue: $field->defaultValue,
            tooltip: $field->tooltip,
            required: $field->required,
            readOnly: $field->readOnly,
            options: $field->options,
            validateScript: $field->validateScript,
            calculateScript: $field->calculateScript,
            formatScript: $field->formatScript,
            keystrokeScript: $field->keystrokeScript,
            buttonCaption: $field->buttonCaption,
            submitUrl: $field->submitUrl,
            clickScript: $field->clickScript,
        );

        $ctx->cursorY -= $h;
        $ctx->cursorY -= $field->spaceAfterPt;
    }

    /**
     * Digital-signature field: reserves its room and places the widget; the
     * stamp itself is drawn when the document is emitted.
     */
    private function renderSignatureField(SignatureField $field, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $field->spaceBeforePt;

        $h = $field->heightPt;
        $w = min($field->widthPt, $ctx->contentWidth);
        $this->ensureRoomFor($ctx, $h);

        $x = match ($field->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $w) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $w,
            default => $ctx->leftX,
        };

        $ctx->currentPage->addSignatureField(
            name: $field->name,
            x: $x,
            y: $ctx->cursorY - $h,
            width: $w,
            height: $h,
            appearance: $field->appearance,
            tooltip: $field->tooltip,
            repeatOnOtherPages: $field->repeatOnOtherPages,
            fallbackFont: $this->resolveEmbeddedFont(new RunStyle),
            fallbackBoldFont: $this->resolveEmbeddedFont(new RunStyle(bold: true)),
        );

        $ctx->cursorY -= $h;
        $ctx->cursorY -= $field->spaceAfterPt;
    }

    /**
     * Radio button group. Each option gets its own widget
     * (small circle outline), but all widgets share the group's /T name.
     * Layout: vertical stack, ~16pt each row.
     */
    private function renderRadioGroup(FormField $field, LayoutContext $ctx): void
    {
        $rowHeight = 16.0;
        $totalHeight = $rowHeight * count($field->options);
        $this->ensureRoomFor($ctx, $totalHeight);

        $widgetSize = 12.0; // square button.
        $widgets = [];
        $rowY = $ctx->cursorY;
        foreach ($field->options as $idx => $optionLabel) {
            $rowY -= $rowHeight;
            $bx = $ctx->leftX;
            $by = $rowY + ($rowHeight - $widgetSize) / 2;
            // Visual: circle outline (approx via square — many readers
            // render radio as a proper circle).
            $ctx->currentPage->strokeRect($bx, $by, $widgetSize, $widgetSize, 0.5, 0.4, 0.4, 0.4);
            // If checked, fill inner.
            if ($optionLabel === $field->defaultValue) {
                $ctx->currentPage->fillRect($bx + 3, $by + 3, $widgetSize - 6, $widgetSize - 6, 0.2, 0.2, 0.2);
            }
            // Label text.
            $labelX = $bx + $widgetSize + 6;
            $labelY = $by + 3;
            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText($optionLabel, $labelX, $labelY, $this->defaultFont, 10);
            } else {
                $ctx->currentPage->showText($optionLabel, $labelX, $labelY, $this->fallbackStandard, 10);
            }
            $widgets[] = ['x' => $bx, 'y' => $by, 'w' => $widgetSize, 'h' => $widgetSize];
        }

        $ctx->currentPage->addFormField(
            type: $field->type,
            name: $field->name,
            x: $ctx->leftX,
            y: $rowY,
            width: $ctx->contentWidth,
            height: $totalHeight,
            defaultValue: $field->defaultValue,
            tooltip: $field->tooltip,
            required: $field->required,
            readOnly: $field->readOnly,
            options: $field->options,
            radioWidgets: $widgets,
        );

        $ctx->cursorY -= $totalHeight;
        $ctx->cursorY -= $field->spaceAfterPt;
    }

    /**
     * Moves a "keep with next" paragraph to a new page when it no longer fits
     * there together with the following block.
     *
     * What is measured is not the whole following block but its first line:
     * Word's requirement is that something is left after the heading. Demanding
     * the whole block would mean throwing away half a page for the sake of a
     * long table.
     */
    private function keepWithNextBreak(BlockElement $block, ?BlockElement $next, LayoutContext $ctx): void
    {
        if ($next === null || $ctx->inTableCell || $ctx->inHeaderFooterRender) {
            return;
        }
        $trailing = $this->trailingParagraph($block);
        if ($trailing === null || ! $trailing->style->keepWithNext) {
            return;
        }
        // A paragraph at the top of a page has nowhere to move — it is first
        // already.
        if ($ctx->cursorY >= $ctx->topY) {
            return;
        }

        $needed = $this->measureBlockHeight($block, $ctx->contentWidth)
            + $this->firstLineHeight($next, $ctx->contentWidth);

        if ($ctx->cursorY - $needed < $ctx->bottomY) {
            $this->forcePageBreak($ctx);
        }
    }

    /**
     * The last paragraph of a block is the one "keep with next" applies to.
     *
     * A section heading is often formatted as an item of a numbered list: the
     * requirement is then carried by the list, and it is the list as a whole
     * that has to be kept with the following block.
     */
    private function trailingParagraph(BlockElement $block): ?Paragraph
    {
        if ($block instanceof Paragraph) {
            return $block;
        }

        if ($block instanceof ListNode) {
            $items = $block->items;
            if ($items === []) {
                return null;
            }
            $last = $items[count($items) - 1];
            for ($i = count($last->children) - 1; $i >= 0; $i--) {
                $child = $last->children[$i];
                if ($child instanceof Paragraph) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * The height of the block's first line — how much room is needed for
     * something to be left after the heading.
     */
    private function firstLineHeight(BlockElement $block, float $contentWidth): float
    {
        $full = $this->measureBlockHeight($block, $contentWidth);

        if ($block instanceof Table) {
            $rows = $block->rows;
            if ($rows === []) {
                return $full;
            }
            $tableWidth = $this->computeTableWidth($block->style, $contentWidth);
            $colWidths = $this->computeColumnWidths($block, $tableWidth, $block->columnCount());

            return min($full, $this->measureRowHeight($block, $rows[0], $colWidths));
        }

        // For the other blocks one line of text at the normal size is enough.
        return min($full, $this->defaultFontSizePt * 1.5);
    }

    private function forcePageBreak(LayoutContext $ctx): void
    {
        // Re-entrance guard. If we are already rendering header/footer and
        // a block from there tries forcePageBreak — that is overflow in the
        // header zone. Don't recurse — truncate header content instead of
        // an infinite loop.
        if ($ctx->inHeaderFooterRender) {
            $ctx->cursorY = $ctx->bottomY;

            return;
        }

        // A forced break inside a table cell is ignored — as Word ignores it.
        // Word cannot start a page in the middle of a cell, so such a break
        // stays in the file as a leftover of editing. Acting on it tore a
        // form apart: the row carrying the break went to a page of its own,
        // and everything after it began on the following page.
        if ($ctx->inTableCell) {
            return;
        }

        // Per-page footnote bottom — flush current page's
        // footnotes before switching pages.
        if ($ctx->footnoteReserveBottomPt !== null && ! $ctx->footnoteGrowing) {
            $this->renderPageBottomFootnotes($ctx);
            $ctx->pageFootnoteStart = count($ctx->footnotes);
        }

        // Inside ColumnSet, overflow → next column, not page break,
        // until columns are exhausted.
        if ($ctx->columnCount > 1 && $ctx->currentColumn + 1 < $ctx->columnCount) {
            $ctx->currentColumn++;
            $this->applyColumnGeometry($ctx);
            $ctx->cursorY = $ctx->topY;

            return;
        }

        // A growing footnote area belongs to its page: drawn, and the next
        // page starts with none.
        if ($ctx->footnoteGrowing) {
            $this->renderPageBottomFootnotes($ctx);
            $ctx->bottomY -= (float) $ctx->footnoteReserveBottomPt;
            $ctx->footnoteReserveBottomPt = 0.0;
        }

        // A new page inside a section must preserve its PageSetup
        // (paper, orientation, customDimensions) even if the document has
        // a different default orientation.
        $setup = $ctx->pageSetup;
        $ctx->currentPage = $ctx->pdf->addPage(
            $setup->paperSize,
            $setup->orientation,
            $setup->customDimensionsPt,
        );
        $this->applyPerPageMargins($ctx);
        $ctx->cursorY = $ctx->topY;
        $this->renderHeaderFooter($ctx);

        // After page break inside ColumnSet — reset to column 0
        // and apply column geometry on the new page.
        if ($ctx->columnCount > 1) {
            $ctx->currentColumn = 0;
            $this->applyColumnGeometry($ctx);
        }
    }

    /**
     * Sets leftX/contentWidth for the current column
     * (based on columnOrigin + columnCount + columnGap + currentColumn).
     */
    private function applyColumnGeometry(LayoutContext $ctx): void
    {
        if ($ctx->columnCount <= 1) {
            return;
        }
        $totalGap = $ctx->columnGapPt * ($ctx->columnCount - 1);
        $columnWidth = ($ctx->columnOriginContentWidth - $totalGap) / $ctx->columnCount;
        $ctx->leftX = $ctx->columnOriginLeftX
            + $ctx->currentColumn * ($columnWidth + $ctx->columnGapPt);
        $ctx->contentWidth = $columnWidth;
    }

    /**
     * ColumnSet block — render body in N columns.
     */
    private function renderColumnSet(ColumnSet $cs, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $cs->spaceBeforePt;
        if ($cs->columnCount <= 1) {
            // Degenerate: 1 column — simply render body inline.
            foreach ($cs->body as $block) {
                $this->renderBlock($block, $ctx);
            }
            $ctx->cursorY -= $cs->spaceAfterPt;

            return;
        }

        // Save outer state.
        $savedLeftX = $ctx->leftX;
        $savedContentWidth = $ctx->contentWidth;
        $savedColumnCount = $ctx->columnCount;
        $savedCurrentColumn = $ctx->currentColumn;
        $savedColumnGap = $ctx->columnGapPt;
        $savedOriginLeftX = $ctx->columnOriginLeftX;
        $savedOriginContentWidth = $ctx->columnOriginContentWidth;
        $startY = $ctx->cursorY;

        $ctx->columnCount = $cs->columnCount;
        $ctx->currentColumn = 0;
        $ctx->columnGapPt = $cs->columnGapPt;
        $ctx->columnOriginLeftX = $savedLeftX;
        $ctx->columnOriginContentWidth = $savedContentWidth;
        $this->applyColumnGeometry($ctx);

        foreach ($cs->body as $block) {
            $this->renderBlock($block, $ctx);
        }

        // Restore outer state. cursorY = bottom most position (for simplicity
        // we use the bottom of column 0 — treating the column-set as
        // occupying the full vertical span).
        $ctx->columnCount = $savedColumnCount;
        $ctx->currentColumn = $savedCurrentColumn;
        $ctx->columnGapPt = $savedColumnGap;
        $ctx->columnOriginLeftX = $savedOriginLeftX;
        $ctx->columnOriginContentWidth = $savedOriginContentWidth;
        $ctx->leftX = $savedLeftX;
        $ctx->contentWidth = $savedContentWidth;
        // cursorY — at text inside-column. If a page break occurred inside
        // columns, we observe the cursorY of the new page. We use it as-is
        // (subsequent content starts below the last column).
        $ctx->cursorY -= $cs->spaceAfterPt;
    }

    /**
     * Applies mirrored/gutter margins for the current page (called after
     * a new page is created). cursorY/topY/bottomY are not changed —
     * only leftX and contentWidth.
     */
    private function applyPerPageMargins(LayoutContext $ctx): void
    {
        $pageNum = $this->currentPageNumber($ctx);
        $ctx->leftX = $ctx->pageSetup->leftXForPage($pageNum);
        $ctx->contentWidth = $ctx->pageSetup->contentWidthPtForPage($pageNum);
    }

    /**
     * Renders header in the top-margin area and footer in the bottom-margin
     * area of the current page. Called whenever a new page is created.
     *
     * Header bounds: leftX..leftX+contentWidth × [pageHeight - topMargin
     * .. pageHeight]. Cursor starts just below the top edge.
     * Footer bounds: leftX..leftX+contentWidth × [0 .. bottomMargin].
     * Cursor starts sufficiently below the content area.
     */
    private function renderHeaderFooter(LayoutContext $ctx): void
    {
        if ($this->currentSection === null) {
            return;
        }
        foreach ($this->fixedPositioned as $fixed) {
            $this->drawPositioned($fixed, $ctx);
        }
        $section = $this->currentSection;

        // PDF/UA — header/footer/watermark = /Artifact (excluded
        // from struct tree / screen readers). Only emit if there is
        // anything to draw.
        $taggedPdf = $ctx->pdf->isTagged();
        $hasAnything = $section->hasWatermark()
            || $section->effectiveHeaderBlocksFor($this->currentPageNumber($ctx)) !== []
            || $section->effectiveFooterBlocksFor($this->currentPageNumber($ctx)) !== [];
        $artifactOpen = false;
        $savedSkip = false;
        if ($taggedPdf && $hasAnything) {
            $ctx->currentPage->beginArtifact('Pagination');
            $artifactOpen = true;
            $savedSkip = $ctx->skipParagraphTag;
            $ctx->skipParagraphTag = true;
        }

        // Watermarks moved to a post-pass (Engine::renderWatermarksPostPass).
        // mpdf-style: watermark over body content with opacity, not under —
        // otherwise transparent cells let the watermark show through and it
        // blends with background-color text. Only header/footer rendering here.
        $setup = $ctx->pageSetup;
        [$pageWidth, $pageHeight] = $setup->dimensions();

        $pageNum = $this->currentPageNumber($ctx);
        $effectiveLeftX = $setup->leftXForPage($pageNum);
        $effectiveContentWidth = $setup->contentWidthPtForPage($pageNum);

        // Adaptive header/footer zones (mpdf-style behavior) — if the
        // header/footer height exceeds margins.topPt/.bottomPt, expand the
        // zone and shift body topY/bottomY. Otherwise header is rendered
        // over body content (overlap visible).
        $headerPaddingPt = 8.0;  // gap between header and body content
        $footerPaddingPt = 8.0;

        $headerBlocks = $section->effectiveHeaderBlocksFor($pageNum);
        if ($headerBlocks !== []) {
            $headerHeight = 0.0;
            foreach ($headerBlocks as $block) {
                $headerHeight += $this->measureBlockHeight($block, $effectiveContentWidth);
            }
            // Header zone goes from pageHeight-4 (close to top edge) down;
            // bottom of zone = pageHeight - max(margins.topPt, headerHeight + headerPaddingPt).
            $effectiveTopMargin = max(
                $setup->margins->topPt,
                $setup->headerDistancePt + $headerHeight + $headerPaddingPt,
            );
            $headerZoneBottomY = $pageHeight - $effectiveTopMargin + $headerPaddingPt / 2;
            // Push body topY down if header overflows default margin.
            $adaptiveBodyTopY = $pageHeight - $effectiveTopMargin;
            if ($adaptiveBodyTopY < $ctx->topY) {
                $ctx->topY = $adaptiveBodyTopY;
                if ($ctx->cursorY > $adaptiveBodyTopY) {
                    $ctx->cursorY = $adaptiveBodyTopY;
                }
            }
            $headerArea = new LayoutContext(
                pdf: $ctx->pdf,
                currentPage: $ctx->currentPage,
                cursorY: $pageHeight - $setup->headerDistancePt,
                leftX: $effectiveLeftX,
                contentWidth: $effectiveContentWidth,
                bottomY: $headerZoneBottomY,
                topY: $pageHeight - $setup->headerDistancePt,
                pageSetup: $setup,
                skipParagraphTag: $ctx->skipParagraphTag,
                inHeaderFooterRender: true,
            );
            foreach ($headerBlocks as $block) {
                $this->renderBlock($block, $headerArea);
            }
        }

        $footerBlocks = $section->effectiveFooterBlocksFor($pageNum);
        if ($footerBlocks !== []) {
            $footerHeight = 0;
            foreach ($footerBlocks as $block) {
                $footerHeight += $this->measureBlockHeight($block, $effectiveContentWidth);
            }
            // Footer zone starts at the bottom margin line and flows down into
            // the margin — the same way Word lays it out. The body is pushed up
            // only when the footer does not fit between that line and the page
            // edge, and the decision is made on the footer's own geometry:
            // distance from the edge plus its height.
            //
            // Adding a visual gap to that comparison (as this did) makes the
            // body shrink for footers that fit perfectly well. On an A4 policy
            // with a one-line footer that cost ~15pt of body height on every
            // page — about six lines over five pages, enough to push the last
            // page over.
            $effectiveBottomMargin = max(
                $setup->margins->bottomPt,
                $setup->footerDistancePt + $footerHeight,
            );
            $footerZoneTopY = $effectiveBottomMargin - $footerPaddingPt / 2;
            // Push body bottomY up if footer overflows default margin.
            if ($effectiveBottomMargin > $ctx->bottomY) {
                $ctx->bottomY = $effectiveBottomMargin;
            }
            $footerArea = new LayoutContext(
                pdf: $ctx->pdf,
                currentPage: $ctx->currentPage,
                cursorY: $footerZoneTopY,
                leftX: $effectiveLeftX,
                contentWidth: $effectiveContentWidth,
                bottomY: $setup->footerDistancePt,
                topY: $footerZoneTopY,
                pageSetup: $setup,
                skipParagraphTag: $ctx->skipParagraphTag,
                inHeaderFooterRender: true,
            );
            foreach ($footerBlocks as $block) {
                $this->renderBlock($block, $footerArea);
            }
        }

        // Close /Artifact + restore tag suppression.
        if ($artifactOpen) {
            $ctx->currentPage->endMarkedContent();
            $ctx->skipParagraphTag = $savedSkip;
        }
    }


    /**
     * Draw both image and text watermarks on a specific Page.
     * Called in a post-pass after body content rendering so that the
     * watermark lands ABOVE the content (mpdf-style stamp).
     */
    private function renderWatermarksOnPage(\Dskripchenko\PhpPdf\Section $section, \Dskripchenko\PhpPdf\Pdf\Page $page): void
    {
        // Image first (if both, text lies over image).
        if ($section->hasImageWatermark()) {
            $this->renderWatermarkImageOnPage(
                $section->watermarkImage,
                $section->watermarkImageWidthPt,
                $section->watermarkImageOpacity,
                $page,
                $section->pageSetup,
            );
        }
        if ($section->hasTextWatermark()) {
            $this->renderWatermarkTextOnPage(
                (string) $section->watermarkText,
                $section->watermarkTextOpacity,
                $page,
                $section->pageSetup,
            );
        }
    }

    private function renderWatermarkTextOnPage(
        string $text,
        ?float $opacity,
        \Dskripchenko\PhpPdf\Pdf\Page $page,
        \Dskripchenko\PhpPdf\Style\PageSetup $setup,
    ): void {
        [$pageWidth, $pageHeight] = $setup->dimensions();

        $sizePt = 72;
        $textWidth = $this->chartTextWidth($text, $sizePt);

        $angleRad = -M_PI / 4;
        $halfWidth = $textWidth / 2;
        $cx = $pageWidth / 2 - $halfWidth * cos($angleRad);
        $cy = $pageHeight / 2 - $halfWidth * sin($angleRad) - $sizePt * 0.3;

        if ($this->defaultFont !== null) {
            $page->drawWatermarkEmbedded($text, $cx, $cy, $this->defaultFont, $sizePt, $angleRad, opacity: $opacity);
        } else {
            $page->drawWatermark($text, $cx, $cy, $this->fallbackStandard, $sizePt, $angleRad, opacity: $opacity);
        }
    }

    private function renderWatermarkImageOnPage(
        \Dskripchenko\PhpPdf\Image\PdfImage $image,
        ?float $widthPt,
        ?float $opacity,
        \Dskripchenko\PhpPdf\Pdf\Page $page,
        \Dskripchenko\PhpPdf\Style\PageSetup $setup,
    ): void {
        [$pageWidth, $pageHeight] = $setup->dimensions();
        $w = $widthPt ?? $pageWidth * 0.5;
        $aspect = $image->heightPx > 0 ? $image->widthPx / $image->heightPx : 1.0;
        $h = $aspect > 0 ? $w / $aspect : $w;
        $x = ($pageWidth - $w) / 2;
        $y = ($pageHeight - $h) / 2;
        if ($opacity !== null && $opacity < 1.0) {
            $page->drawImageWithOpacity($image, $x, $y, $w, $h, $opacity);
        } else {
            $page->drawImage($image, $x, $y, $w, $h);
        }
    }


    /**
     * Physical 1-based index of the current page (for mirrored margins +
     * first-page logic). Does NOT account for the firstPageNumber offset.
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
     * Displayed page number for Field PAGE — accounting for the
     * pageSetup firstPageNumber offset.
     */
    private function displayedPageNumber(LayoutContext $ctx): int
    {
        $physical = $this->currentPageNumber($ctx);
        if ($physical === 0) {
            return 0;
        }

        return $physical + ($ctx->pageSetup->firstPageNumber - 1);
    }

    /**
     * NUMPAGES: the displayed total, or 99 while measuring in the first
     * pass. Notes that the document needs a second pass for the real count.
     */
    private function resolveTotalPages(?LayoutContext $ctx): int
    {
        $this->totalPagesUsed = true;

        return $ctx !== null ? $this->displayedTotalPages($ctx) : ($this->totalPagesHint ?? 99);
    }

    private function displayedTotalPages(LayoutContext $ctx): int
    {
        $count = $this->totalPagesHint ?? 1;

        return $count + ($ctx->pageSetup->firstPageNumber - 1);
    }

    private function renderHorizontalRule(LayoutContext $ctx): void
    {
        // Spacing around hr ~6pt above and below.
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
     * Linear barcode block (Code 128 and others).
     *
     * Algorithm:
     *  1. Encode value via the appropriate encoder → list<bool> modules
     *     (with quiet zone).
     *  2. Module width = barcodeWidth / moduleCount. Default barcode width
     *     = moduleCount × 1pt (rough; typically needs a tweak in the caller).
     *  3. Draw bars: each contiguous run of black modules → fillRect.
     *  4. Optional caption under bars (human-readable).
     */
    private function renderBarcode(Barcode $bc, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $bc->spaceBeforePt;

        if ($bc->format->is2D()) {
            if ($bc->format === \Dskripchenko\PhpPdf\Element\BarcodeFormat::DataMatrix) {
                $this->renderDataMatrixBarcode($bc, $ctx);
            } elseif ($bc->format === \Dskripchenko\PhpPdf\Element\BarcodeFormat::Aztec) {
                $this->renderAztecBarcode($bc, $ctx);
            } else {
                $this->renderQrBarcode($bc, $ctx);
            }

            return;
        }

        // PDF417 — stacked linear (multiple rows of bars).
        if ($bc->format === \Dskripchenko\PhpPdf\Element\BarcodeFormat::Pdf417) {
            $this->renderPdf417Barcode($bc, $ctx);

            return;
        }

        // Encode by format (linear barcodes only — QR handled above).
        [$modules, $captionText] = match ($bc->format) {
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Code128 => [
                (new \Dskripchenko\PhpPdf\Barcode\Code128Encoder($bc->value))->modulesWithQuietZone(10),
                $bc->value,
            ],
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Code11 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Code11Encoder($bc->value);

                return [$e->modulesWithQuietZone(10), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Code39 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Code39Encoder($bc->value);

                return [$e->modulesWithQuietZone(10), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Itf => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\ItfEncoder($bc->value);

                return [$e->modulesWithQuietZone(10), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Codabar => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\CodabarEncoder($bc->value);

                return [$e->modulesWithQuietZone(10), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Code93 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Code93Encoder($bc->value);

                return [$e->modulesWithQuietZone(10), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::MsiPlessey => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\MsiPlesseyEncoder($bc->value);

                return [$e->modulesWithQuietZone(12), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Pharmacode => (function () use ($bc): array {
                if (! preg_match('@^\d+$@', $bc->value)) {
                    throw new \InvalidArgumentException('Pharmacode requires numeric value');
                }
                $e = new \Dskripchenko\PhpPdf\Barcode\PharmacodeEncoder((int) $bc->value);

                return [$e->modulesWithQuietZone(6), (string) $e->value];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Ean13 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Ean13Encoder($bc->value);

                return [$e->modulesWithQuietZone(9), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::Ean8 => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Ean8Encoder($bc->value);

                return [$e->modulesWithQuietZone(7), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::UpcA => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\Ean13Encoder($bc->value, upcA: true);

                return [$e->modulesWithQuietZone(9), $e->canonical];
            })(),
            \Dskripchenko\PhpPdf\Element\BarcodeFormat::UpcE => (function () use ($bc): array {
                $e = new \Dskripchenko\PhpPdf\Barcode\UpcEEncoder($bc->value);

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

        // X-position by alignment.
        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalWidth) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalWidth,
            default => $ctx->leftX,
        };

        // Draw bars: collapse contiguous black runs into a single fillRect.
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

        // Caption (human-readable). Use base-14 Helvetica or the
        // embedded font when set. For EAN-13/UPC-A caption — canonical
        // form with checksum digit.
        if ($bc->showText) {
            $captionY = $yBottom - $bc->textSizePt - 1.0;
            $captionWidth = $this->chartTextWidth($captionText, $bc->textSizePt);
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
     * DataMatrix 2D barcode (ECC 200). Modules — 2D bool matrix;
     * rendered through the shared 2D matrix path with quiet zone 1 module.
     */
    private function renderDataMatrixBarcode(Barcode $bc, LayoutContext $ctx): void
    {
        $enc = new \Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder($bc->value);
        $this->render2DMatrix(
            $enc->modules(), $enc->symbolWidth(), $bc, $ctx,
            quietZone: 1,
        );
    }

    /**
     * Aztec compact (1-4 layers, 15..27 squared).
     */
    private function renderAztecBarcode(\Dskripchenko\PhpPdf\Element\Barcode $bc, LayoutContext $ctx): void
    {
        $enc = new \Dskripchenko\PhpPdf\Barcode\AztecEncoder($bc->value);
        $this->render2DMatrix(
            $enc->modules(), $enc->matrixSize(), $bc, $ctx,
            quietZone: 2,
        );
    }

    /**
     * PDF417 stacked-linear render. Each logical row is repeated
     * vertically rowHeight times (default 3 modules — ISO recommends 3).
     */
    private function renderPdf417Barcode(\Dskripchenko\PhpPdf\Element\Barcode $bc, LayoutContext $ctx): void
    {
        $enc = new \Dskripchenko\PhpPdf\Barcode\Pdf417Encoder($bc->value);
        $matrix = $enc->modules();
        $logicalRows = count($matrix);
        $cols = count($matrix[0]);
        $rowHeightModules = 3; // ISO §5.3.2 recommends rowHeight ≥ 3 × X-dim.
        $quietZoneH = 2;
        $quietZoneV = 2;

        $totalModuleCols = $cols + 2 * $quietZoneH;
        $totalModuleRows = $logicalRows * $rowHeightModules + 2 * $quietZoneV;

        $totalWidthPt = $bc->widthPt ?? ($totalModuleCols * 0.5);
        $totalWidthPt = min($totalWidthPt, $ctx->contentWidth);
        $moduleWidth = $totalWidthPt / $totalModuleCols;
        // Aspect ratio converted to module height = X-dim units.
        $moduleHeight = $moduleWidth;
        $totalHeightPt = $totalModuleRows * $moduleHeight;

        $captionHeight = $bc->showText ? $bc->textSizePt + 2.0 : 0;
        $this->ensureRoomFor($ctx, $totalHeightPt + $captionHeight);

        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalWidthPt) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalWidthPt,
            default => $ctx->leftX,
        };

        $yTop = $ctx->cursorY;
        $matrixOffsetX = $quietZoneH * $moduleWidth;
        // Each logical row is rendered rowHeight times (vertical replication).
        for ($lr = 0; $lr < $logicalRows; $lr++) {
            for ($vr = 0; $vr < $rowHeightModules; $vr++) {
                $globalRow = $lr * $rowHeightModules + $vr;
                $rowYBottom = $yTop - ($quietZoneV * $moduleHeight) - ($globalRow + 1) * $moduleHeight;
                $runStart = null;
                for ($c = 0; $c < $cols; $c++) {
                    if ($matrix[$lr][$c]) {
                        if ($runStart === null) {
                            $runStart = $c;
                        }
                    } elseif ($runStart !== null) {
                        $w = ($c - $runStart) * $moduleWidth;
                        $ctx->currentPage->fillRect(
                            $blockX + $matrixOffsetX + $runStart * $moduleWidth,
                            $rowYBottom, $w, $moduleHeight, 0, 0, 0,
                        );
                        $runStart = null;
                    }
                }
                if ($runStart !== null) {
                    $w = ($cols - $runStart) * $moduleWidth;
                    $ctx->currentPage->fillRect(
                        $blockX + $matrixOffsetX + $runStart * $moduleWidth,
                        $rowYBottom, $w, $moduleHeight, 0, 0, 0,
                    );
                }
            }
        }

        if ($bc->showText) {
            $captionY = $yTop - $totalHeightPt - $bc->textSizePt - 1.0;
            $captionWidth = $this->chartTextWidth($bc->value, $bc->textSizePt);
            $captionX = $blockX + ($totalWidthPt - $captionWidth) / 2;
            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText($bc->value, $captionX, $captionY, $this->defaultFont, $bc->textSizePt);
            } else {
                $ctx->currentPage->showText($bc->value, $captionX, $captionY, $this->fallbackStandard, $bc->textSizePt);
            }
        }

        $ctx->cursorY -= $totalHeightPt + $captionHeight + $bc->spaceAfterPt;
    }

    /**
     * Shared 2D matrix render (QR + DataMatrix).
     *
     * @param  list<list<bool>>  $matrix
     */
    private function render2DMatrix(array $matrix, int $matrixSize, Barcode $bc, LayoutContext $ctx, int $quietZone): void
    {
        // $matrixSize is the column count; the row count comes from the
        // matrix itself — DataMatrix ECC 200 has rectangular symbols
        // (e.g. 12×26), so the symbol must not be assumed square.
        $cols = $matrixSize;
        $rows = count($matrix);
        $gridCols = $cols + 2 * $quietZone;
        $gridRows = $rows + 2 * $quietZone;
        $totalSizePt = $bc->widthPt ?? 80.0;
        $totalSizePt = min($totalSizePt, $ctx->contentWidth);
        $moduleSize = $totalSizePt / $gridCols;
        $symbolHeightPt = $gridRows * $moduleSize;

        $captionHeight = $bc->showText ? $bc->textSizePt + 2.0 : 0;
        $totalHeight = $symbolHeightPt + $captionHeight;
        $this->ensureRoomFor($ctx, $totalHeight);

        $blockX = match ($bc->alignment) {
            Alignment::Center => $ctx->leftX + ($ctx->contentWidth - $totalSizePt) / 2,
            Alignment::End => $ctx->leftX + $ctx->contentWidth - $totalSizePt,
            default => $ctx->leftX,
        };

        $yTop = $ctx->cursorY;
        $matrixOffset = $quietZone * $moduleSize;
        for ($row = 0; $row < $rows; $row++) {
            $rowYBottom = $yTop - $matrixOffset - ($row + 1) * $moduleSize;
            $runStart = null;
            for ($col = 0; $col < $cols; $col++) {
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
                $w = ($cols - $runStart) * $moduleSize;
                $ctx->currentPage->fillRect(
                    $blockX + $matrixOffset + $runStart * $moduleSize,
                    $rowYBottom, $w, $moduleSize, 0, 0, 0,
                );
            }
        }

        if ($bc->showText) {
            $captionY = $yTop - $symbolHeightPt - $bc->textSizePt - 1.0;
            $captionWidth = $this->chartTextWidth($bc->value, $bc->textSizePt);
            $captionX = $blockX + ($totalSizePt - $captionWidth) / 2;
            if ($this->defaultFont !== null) {
                $ctx->currentPage->showEmbeddedText($bc->value, $captionX, $captionY, $this->defaultFont, $bc->textSizePt);
            } else {
                $ctx->currentPage->showText($bc->value, $captionX, $captionY, $this->fallbackStandard, $bc->textSizePt);
            }
        }

        $ctx->cursorY -= $totalHeight;
        $ctx->cursorY -= $bc->spaceAfterPt;
    }

    /**
     * QR code 2D barcode. Modules — 2D bool matrix; rendered
     * as a grid of black squares. Quiet zone (4 modules) is added
     * around the matrix.
     */
    private function renderQrBarcode(Barcode $bc, LayoutContext $ctx): void
    {
        $enc = new \Dskripchenko\PhpPdf\Barcode\QrEncoder($bc->value, $bc->eccLevel, $bc->qrMode);
        $matrix = $enc->modules();
        $matrixSize = $enc->size();
        $quietZone = 4; // ISO/IEC 18004 minimum.
        $gridSize = $matrixSize + 2 * $quietZone;

        // 2D — totalWidth = totalHeight. widthPt determines size; heightPt
        // ignored for QR (preserved for caption layout).
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
        // QR top-left corner in PDF (origin = bottom-left): yTop minus
        // (quietZone modules) for the actual matrix region.
        // Draw matrix row by row; for each row collapse contiguous black
        // modules into a single horizontal fillRect for efficiency.
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

        // Optional caption (default false for QR — traditionally no text).
        if ($bc->showText) {
            $captionY = $yTop - $totalSizePt - $bc->textSizePt - 1.0;
            $captionWidth = $this->chartTextWidth($bc->value, $bc->textSizePt);
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

    /**
     * Render current page's footnotes at page bottom (per-page mode).
     *
     * Saves cursorY, jumps to reserved zone Y, renders separator + numbered
     * footnotes, restores cursorY.
     *
     * Which notes belong here is decided by the markers actually painted on
     * this page, not by a range in a running list: a marker inside a table
     * cell is laid out in the cell's own context, and a range would lose it
     * along with that context.
     */
    private function renderPageBottomFootnotes(LayoutContext $ctx): void
    {
        if ($ctx->footnoteReserveBottomPt === null) {
            return;
        }

        $pageId = spl_object_id($ctx->currentPage);
        $numbers = $this->pageFootnotes[$pageId] ?? [];
        if ($numbers === []) {
            return; // no footnotes on current page
        }
        sort($numbers);

        // The page is marked as emitted BEFORE it is drawn: the footnote area
        // itself is drawn as ordinary paragraphs, and those can break the page —
        // so re-entering here with the same list went into infinite recursion
        // and ate the memory.
        unset($this->pageFootnotes[$pageId]);

        $savedCursorY = $ctx->cursorY;
        $savedBottomY = $ctx->bottomY;

        if ($ctx->footnoteGrowing) {
            // The area sits on the page's own bottom and is as tall as its
            // notes; nothing drawn in it may start a page.
            $pageBottom = $ctx->bottomY - (float) $ctx->footnoteReserveBottomPt;
            $ctx->cursorY = $pageBottom + $this->footnoteHeight($numbers, $ctx->contentWidth, true);
            $ctx->bottomY = $pageBottom - 10000;
            $ctx->currentPage->fillRect($ctx->leftX, $ctx->cursorY, $ctx->contentWidth * 0.3, 0.5, 0.5, 0.5, 0.5);
            $ctx->cursorY -= 6.0;
            foreach ($numbers as $number) {
                $this->renderBlock($this->footnoteParagraph($number), $ctx);
            }
            $ctx->bottomY = $savedBottomY;
            $ctx->cursorY = $savedCursorY;

            return;
        }

        // Compute footnote zone top Y. bottomY shifted up by reserved size
        // earlier — so original page bottom = bottomY - reservedPt. The zone
        // spans (bottomY - reservedPt) to bottomY. Render footnotes downward
        // from top of zone.
        $zoneTop = $ctx->bottomY; // current adjusted bottomY = top of zone
        $zoneBottom = $zoneTop - $ctx->footnoteReserveBottomPt;

        // Temporarily allow rendering in zone area: extend bottomY down.
        $ctx->bottomY = $zoneBottom;
        $ctx->cursorY = $zoneTop;

        // Thin separator line.
        $ctx->currentPage->fillRect(
            $ctx->leftX, $ctx->cursorY, $ctx->contentWidth * 0.3, 0.5,
            0.5, 0.5, 0.5,
        );
        $ctx->cursorY -= 6.0;

        // Numbers are the ones handed out by the pre-pass, so a reference in
        // the text and its note at the foot always carry the same figure.
        foreach ($numbers as $number) {
            // Use renderBlock but avoid recursion on footnote rendering itself.
            $this->renderBlock($this->footnoteParagraph($number), $ctx);
        }

        // Restore.
        $ctx->bottomY = $savedBottomY;
        $ctx->cursorY = $savedCursorY;
    }

    /**
     * Renders collected footnotes as an endnotes-style block:
     *  - 0.5pt horizontal rule separator
     *  - "N. content" lines numbered by collection order
     *
     * Rendered at the end of section's body (after the last block, before
     * switching to the next section).
     */
    private function renderEndnotes(LayoutContext $ctx): void
    {
        $this->ensureRoomFor($ctx, 12.0);
        $ctx->cursorY -= 6.0;
        // Thin separator line.
        $ctx->currentPage->fillRect(
            $ctx->leftX, $ctx->cursorY, $ctx->contentWidth * 0.3, 0.5,
            0.5, 0.5, 0.5,
        );
        $ctx->cursorY -= 8.0;

        foreach (array_keys($this->footnoteTexts) as $number) {
            $p = $this->footnoteParagraph($number);
            $this->renderBlock($p, $ctx);
        }
    }

    private function renderImage(Image $img, LayoutContext $ctx): void
    {
        $ctx->cursorY -= $img->spaceBeforePt;

        // Tagged PDF — wrap image in /Figure struct element with
        // optional /Alt text.
        $taggedPdf = $ctx->pdf->isTagged();
        $mcid = null;
        if ($taggedPdf) {
            $mcid = $ctx->currentPage->nextMcid();
            $ctx->currentPage->beginMarkedContent('Figure', $mcid);
        }

        // A block image lays out in the line less its indents.
        $areaX = $ctx->leftX + $img->indentLeftPt;
        $areaW = $ctx->contentWidth - $img->indentLeftPt - $img->indentRightPt;
        [$widthPt, $heightPt] = $img->effectiveSizePt($areaW);

        // Scale down if image is larger than content area in any dimension.
        $maxWidth = $areaW;
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

        // A floating object never forces a page break: it takes up no room, so
        // there is nothing for it to "not fit" into.
        if (! $img->outOfFlow) {
            $this->ensureRoomFor($ctx, $heightPt);
        }

        // X-position by alignment.
        $x = match ($img->alignment) {
            Alignment::Center => $areaX + ($areaW - $widthPt) / 2,
            Alignment::End => $areaX + $areaW - $widthPt,
            default => $areaX,
        };

        // PDF coords: drawImage takes (x, y, w, h), where y is the
        // bottom-left corner of the image (PDF Y-axis grows upward).
        $y = $ctx->cursorY - $heightPt;
        $ctx->currentPage->drawImage($img->source, $x, $y, $widthPt, $heightPt);

        if (! $img->outOfFlow) {
            $ctx->cursorY -= $heightPt;
            $ctx->cursorY -= $img->spaceAfterPt;
        } else {
            // The offset before the object was the anchor displacement — put the
            // cursor back where it stood: the flow never noticed the object.
            $ctx->cursorY += $img->spaceBeforePt;
        }

        // End Figure tag + register struct element with alt text.
        if ($taggedPdf && $mcid !== null) {
            $ctx->currentPage->endMarkedContent();
            $ctx->pdf->addStructElement('Figure', $mcid, $ctx->currentPage, $img->altText);
        }
    }

    private function renderParagraph(Paragraph $p, LayoutContext $ctx): void
    {
        if ($p->style->pageBreakBefore) {
            $this->forcePageBreak($ctx);
        }

        // Tagged PDF — wrap paragraph content in BDC/EMC.
        // skipParagraphTag=true when the caller (e.g. Heading)
        // manages tagging itself.
        $taggedPdf = $ctx->pdf->isTagged() && ! $ctx->skipParagraphTag;
        $mcid = null;
        if ($taggedPdf) {
            $mcid = $ctx->currentPage->nextMcid();
            $ctx->currentPage->beginMarkedContent('P', $mcid);
        }

        $ctx->cursorY -= $p->style->spaceBeforePt;

        // The paragraph's box: background under border and padding, the
        // border on its edges, the lines inside. Measured up front so the
        // background goes BEFORE the content.
        [$insetTop, $insetRight, $insetBottom, $insetLeft] = $this->paragraphInsets($p);
        $hasBox = $insetTop + $insetRight + $insetBottom + $insetLeft > 0
            || $p->style->backgroundColor !== null;
        $savedLeftX = $ctx->leftX;
        $savedContentWidth = $ctx->contentWidth;
        if ($hasBox) {
            $boxH = $insetTop + $insetBottom
                + $this->measureParagraphLines($p, $ctx->contentWidth - $insetLeft - $insetRight);
            $boxBottom = $ctx->cursorY - $boxH;
            // The box sits inside the paragraph's left/right indents (its
            // margins, in CSS terms); the lines are indented inside it.
            $boxX = $ctx->leftX + $p->style->indentLeftPt;
            $boxW = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
            if ($p->style->backgroundColor !== null) {
                [$r, $g, $b] = $this->hexToRgb($p->style->backgroundColor);
                $ctx->currentPage->fillRect($boxX, $boxBottom, $boxW, $boxH, $r, $g, $b);
            }
            if ($p->style->borders !== null) {
                $this->drawBoxBorders($ctx->currentPage, $boxX, $boxBottom, $boxW, $boxH, $p->style->borders);
            }
            $ctx->cursorY -= $insetTop;
            $ctx->leftX += $insetLeft;
            $ctx->contentWidth -= $insetLeft + $insetRight;
        }

        // Outline entry for heading paragraph. Registered on every pass:
        // the first pass is the final one unless NUMPAGES needs a second.
        if ($p->headingLevel !== null) {
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

        // Build list of "items" — atomic units for line breaking.
        // Word | LineBreak | PageBreak | Bookmark (synthetic marker).
        // Word items may have a 'link' tag for Hyperlink wrap — needed
        // to emit /Link annotation after the line renders.
        // Field instances are resolved to words via resolveField($ctx).
        $items = [];
        $this->tokenizeChildren($p->children, $effectiveDefault, $items, null, $ctx);

        // Layout indents for first line (firstLineIndent applies
        // only to the first line; the rest use indentLeft).
        $isFirstLine = true;
        $availableWidth = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $firstLineExtraIndent = $p->style->indentFirstLinePt + $this->leadingSpaceIndent($p, $effectiveDefault);

        foreach ($this->breakLines($items, $effectiveDefault, $availableWidth, $firstLineExtraIndent) as $n => $line) {
            $this->emitLine($line['items'], $p, $ctx, $effectiveDefault, $n === 0, $firstLineExtraIndent, isLastLine: $line['kind'] !== 'wrap');
            if ($line['kind'] === 'pagebreak') {
                $this->forcePageBreak($ctx);
            }
        }
        // Restore leftX/contentWidth, step past padding and border.
        if ($hasBox) {
            $ctx->leftX = $savedLeftX;
            $ctx->contentWidth = $savedContentWidth;
            $ctx->cursorY -= $insetBottom;
        }

        $ctx->cursorY -= $p->style->spaceAfterPt;

        // End tagged content + register struct element.
        if ($taggedPdf && $mcid !== null) {
            $ctx->currentPage->endMarkedContent();
            $ctx->pdf->addStructElement('P', $mcid, $ctx->currentPage);
        }
    }

    /**
     * Flatten the inline tree into a plain list of items for the line-break
     * algorithm.
     *
     * Hyperlink children get a 'link' tag for later annotation emission.
     * Bookmark is inserted as a synthetic 'bookmark' item.
     * Field resolves to a word item with concrete text (PAGE → currentPage,
     * NUMPAGES → totalPagesHint, DATE/TIME → now, MERGEFIELD → field name).
     *
     * @param  list<\Dskripchenko\PhpPdf\Element\InlineElement>  $children
     * @param  array<string, mixed>|null  $currentLink
     * @param  list<array<string, mixed>>  $items
     */
    /**
     * Whether the previous token ended with content rather than whitespace.
     *
     * This matters at a run boundary: Word cuts a line where the typeface
     * changes, so «(» and «залогодатель» arrive as separate runs with no space
     * between them.
     */
    private bool $lastTokenEndedInside = false;

    /**
     * @param  list<mixed>  $children
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>|null  $currentLink
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
                // \t (tab) — emit 'tab' marker between segments.
                $segments = explode("\t", $child->text);
                // The first word of a run is glued to the previous one when
                // neither side had whitespace. Otherwise the seam between two
                // runs turns into a space that is not in the text: «(» and
                // «залогодатель» are separate runs (Word cuts a line where the
                // typeface changes), and the document printed as
                // «( залогодатель )».
                $gluedToPrevious = $items !== []
                    && $this->lastTokenEndedInside
                    && $child->text !== ''
                    && preg_match('/^\s/u', $child->text) !== 1;
                $firstWordOfRun = true;
                foreach ($segments as $segIdx => $segment) {
                    foreach ($this->splitWords($segment) as $word) {
                        $items[] = [
                            'type' => 'word',
                            'text' => $word,
                            'style' => $childStyle,
                            'link' => $currentLink,
                            'glue' => $firstWordOfRun && $gluedToPrevious,
                        ];
                        $firstWordOfRun = false;
                    }
                    if ($segIdx < count($segments) - 1) {
                        $items[] = ['type' => 'tab', 'style' => $childStyle, 'link' => $currentLink];
                    }
                }
                // A run that ended in whitespace already has its separator — the
                // next one must not be glued to it.
                $this->lastTokenEndedInside = $child->text !== ''
                    && preg_match('/\s$/u', $child->text) !== 1;
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
                    // Preserve trailing space (important for "Page X of Y" layout).
                    $items[] = ['type' => 'word', 'text' => '', 'style' => $childStyle, 'link' => $currentLink];
                }
            } elseif ($child instanceof Image) {
                // Inline image — atom in the line-break algorithm.
                // Width/height from effectiveSizePt(); link tag propagates
                // (image wrapped in Hyperlink → clickable image).
                [$imgW, $imgH] = $child->effectiveSizePt();
                $items[] = [
                    'type' => 'image',
                    'image' => $child->source,
                    'width' => $imgW,
                    'height' => $imgH,
                    'link' => $currentLink,
                ];
            } elseif ($child instanceof \Dskripchenko\PhpPdf\Element\Footnote) {
                // Number comes from the register, so tokenizing the same
                // cell again yields the same marker instead of a new one.
                // The item carries the number: the page it belongs to is
                // only known when the marker is actually painted.
                $number = $this->footnoteNumberFor($child);
                $markerStyle = $effectiveDefault->withSuperscript(true);
                $items[] = [
                    'type' => 'word',
                    'text' => (string) $number,
                    'style' => $markerStyle,
                    'link' => $currentLink,
                    'footnote' => $number,
                ];
            }
        }
    }

    /**
     * Resolves a Field to a ready-to-emit string.
     *
     * PAGE     → currentPageNumber($ctx) (or 1 when measuring without ctx)
     * NUMPAGES → totalPagesHint (or 99 placeholder during first pass)
     * DATE     → current date in the specified format (DD.MM.YYYY default)
     * TIME     → current time (HH:mm default)
     * MERGEFIELD → format parameter = name (placeholder text)
     */
    private function resolveField(Field $f, ?LayoutContext $ctx): string
    {
        return match ($f->kind()) {
            Field::PAGE => (string) ($ctx !== null ? $this->displayedPageNumber($ctx) : 1),
            Field::NUMPAGES => (string) $this->resolveTotalPages($ctx),
            Field::DATE => $this->formatDateTime($f->format() ?: 'dd.MM.yyyy'),
            Field::TIME => $this->formatDateTime($f->format() ?: 'HH:mm'),
            Field::MERGEFIELD => $f->format() !== '' ? $f->format() : '?',
            default => '',
        };
    }

    /**
     * Extracts plain text from inline children, recursively descending into
     * Hyperlink/Bookmark wrappers. Used for outline titles and similar
     * cases where only the content is needed without styling.
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
     * Breaks a paragraph's items into lines — the one place it is decided, so
     * the layout and the measurement of a paragraph cannot disagree (a cell
     * measured shorter than it lays out ran past the bottom of the page and
     * lost its text).
     *
     * A line ends by wrapping, at a `<br>`, at a page break, or with the
     * paragraph ('last').
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{items: list<array<string, mixed>>, kind: 'wrap'|'br'|'pagebreak'|'last'}>
     */
    private function breakLines(array $items, RunStyle $effectiveDefault, float $availableWidth, float $firstLineExtraIndent): array
    {
        $lines = [];
        $isFirstLine = true;
        // Greedy line breaking.
        /** @var list<array{type: string, text?: string, style?: RunStyle}> $currentLine */
        $currentLine = [];
        $currentWidth = 0;
        $effectiveAvail = $availableWidth - ($isFirstLine ? $firstLineExtraIndent : 0);

        // While-loop with explicit index because soft-hyphen
        // overflow handling uses array_splice() to re-enqueue
        // remainder into the same items array.
        $i = -1;
        while (++$i < count($items)) {
            $item = $items[$i];
            if ($item['type'] === 'br') {
                $lines[] = ['items' => $currentLine, 'kind' => 'br'];
                $currentLine = [];
                $currentWidth = 0;
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;

                continue;
            }
            if ($item['type'] === 'pagebreak') {
                $lines[] = ['items' => $currentLine, 'kind' => 'pagebreak'];
                $currentLine = [];
                $currentWidth = 0;
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;

                continue;
            }
            if ($item['type'] === 'bookmark') {
                // Bookmark — zero-width marker; attaches to the current line's
                // top-Y during emitLine(). If the current line is empty,
                // attached to the next line.
                $currentLine[] = $item;

                continue;
            }

            if ($item['type'] === 'image') {
                // Image atom — width is known, sep behaves like a word.
                $atomWidth = $item['width'];
                $sepWidth = $currentLine === [] ? 0 : $this->measureWidth(' ', $effectiveDefault);
                if ($currentLine !== [] && $currentWidth + $sepWidth + $atomWidth > $effectiveAvail) {
                    $lines[] = ['items' => $currentLine, 'kind' => 'wrap'];
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
            $sepWidth = ($currentLine === [] || ($item['glue'] ?? false))
                ? 0
                : $this->measureWidth(' ', $style);

            // Hanging punctuation — trailing punct discounted from
            // the wrap decision (it visually overflows past the margin).
            $effectiveWordWidth = $wordWidth;
            if ($this->hangingPunctuation) {
                $lastChar = mb_substr($word, -1, 1, 'UTF-8');
                if (in_array($lastChar, ['.', ',', ';', ':', '!', '?', '。', '，', '；', '：', '！', '？'], true)) {
                    $effectiveWordWidth -= $this->measureWidth($lastChar, $style);
                }
            }

            // A word that does not fit even on an empty line is broken by
            // characters. Otherwise it prints as is and runs past the column: a
            // long URL in a narrow cell crawled onto the neighbouring one. Word
            // and the browser both do this — breaking a long string is better
            // than losing the column boundary.
            if ($wordWidth > $availableWidth && $availableWidth > 0) {
                if ($currentLine !== []) {
                    $lines[] = ['items' => $currentLine, 'kind' => 'wrap'];
                    $currentLine = [];
                    $currentWidth = 0;
                    $isFirstLine = false;
                    $effectiveAvail = $availableWidth;
                }
                // A soft hyphen is a real division point, and it beats a blind
                // cut between characters.
                $shy = $this->trySplitOnSoftHyphen($word, $style, $effectiveAvail);
                [$head, $tail] = $shy ?? $this->splitOverlongWord($word, $style, $effectiveAvail);
                if ($tail !== '') {
                    $headItem = $item;
                    $headItem['text'] = $head;
                    $currentLine[] = $headItem;
                    $lines[] = ['items' => $currentLine, 'kind' => 'wrap'];
                    $currentLine = [];
                    $currentWidth = 0;
                    $isFirstLine = false;
                    $effectiveAvail = $availableWidth;

                    $tailItem = $item;
                    $tailItem['text'] = $tail;
                    // The tail is already glued on: there is no space before it.
                    $tailItem['glue'] = true;
                    array_splice($items, $i + 1, 0, [$tailItem]);

                    continue;
                }
            }

            if ($currentLine !== [] && $currentWidth + $sepWidth + $effectiveWordWidth > $effectiveAvail) {
                // Try soft-hyphen split — if the word can be broken at SHY
                // marker such that prefix + '-' fits in remaining space, place
                // the prefix here and put the remainder as a new item on the next line.
                $remainingSpace = $effectiveAvail - $currentWidth - $sepWidth;
                $shySplit = $this->trySplitOnSoftHyphen($word, $style, $remainingSpace);
                if ($shySplit !== null) {
                    [$firstWithHyphen, $remainder] = $shySplit;
                    $firstItem = $item;
                    $firstItem['text'] = $firstWithHyphen;
                    $currentLine[] = $firstItem;
                    // Emit line with the split prefix.
                    $lines[] = ['items' => $currentLine, 'kind' => 'wrap'];
                    $currentLine = [];
                    $currentWidth = 0;
                    $isFirstLine = false;
                    $effectiveAvail = $availableWidth;
                    // Re-enqueue remainder for the next iteration.
                    $remainderItem = $item;
                    $remainderItem['text'] = $remainder;
                    array_splice($items, $i + 1, 0, [$remainderItem]);

                    continue;
                }
                // A word made of several runs ("настояще" + "му", as Word
                // splits text at formatting changes) moves to the next line
                // whole: the line breaks before the first of its pieces, not
                // between them.
                $carry = [];
                if ($item['glue'] ?? false) {
                    $start = count($currentLine) - 1;
                    while ($start > 0 && ($currentLine[$start]['glue'] ?? false) && ($currentLine[$start]['type'] ?? null) === 'word') {
                        $start--;
                    }
                    if ($start > 0 && ($currentLine[$start]['type'] ?? null) === 'word') {
                        $carry = array_splice($currentLine, $start);
                    }
                }
                // Overflow-driven line break — line followed by more content,
                // so isLastLine = false (justify candidate).
                $lines[] = ['items' => $currentLine, 'kind' => 'wrap'];
                $currentWidth = $wordWidth;
                foreach ($carry as $piece) {
                    $currentWidth += $this->measureWidth($piece['text'] ?? '', $piece['style'] ?? $effectiveDefault);
                }
                $currentLine = [...$carry, $item];
                $isFirstLine = false;
                $effectiveAvail = $availableWidth;
            } else {
                $currentLine[] = $item;
                $currentWidth += $sepWidth + $wordWidth;
            }
        }
        // Flush last line.
        if ($currentLine !== []) {
            $lines[] = ['items' => $currentLine, 'kind' => 'last'];
        }

        return $lines;
    }

    /**
     * The height of a line and what it is made of — shared by the layout and
     * the measurement. The largest font sets the text line height; an inline
     * image taller than that sets the line. An empty line takes the default
     * font's height.
     *
     * @param  list<array<string, mixed>>  $wordItems  The line without its bookmarks.
     * @return array{0: float, 1: float, 2: float, 3: float} line height, largest font size, text line height, tallest image
     */
    private function lineMetrics(array $wordItems, Paragraph $p, RunStyle $defaultStyle): array
    {
        $maxSizePt = 0.0;
        $maxImageHeight = 0.0;
        foreach ($wordItems as $item) {
            if (($item['type'] ?? null) === 'image') {
                $maxImageHeight = max($maxImageHeight, (float) $item['height']);

                continue;
            }
            $maxSizePt = max($maxSizePt, (float) (($item['style'] ?? $defaultStyle)->sizePt ?? $this->defaultFontSizePt));
        }
        if ($maxSizePt === 0.0) {
            // An empty line, or one of images only: the default font's height.
            $maxSizePt = $defaultStyle->sizePt ?? $this->defaultFontSizePt;
        }
        $textLineHeight = $this->effectiveLineHeightPt($p, $maxSizePt);

        return [max($textLineHeight, $maxImageHeight > 0 ? $maxImageHeight + 2 : 0.0), $maxSizePt, $textLineHeight, $maxImageHeight];
    }

    /**
     * Emits a line — positions words, updates cursorY.
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
        // Split items into word/marker. Markers (bookmark) are zero-width;
        // attached to the line top-Y but not counted in line-width.
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
            // Empty line — advance cursorY by the default height, attach bookmarks
            // to the current top-Y.
            [$lineHeight] = $this->lineMetrics($wordItems, $p, $defaultStyle);
            $this->ensureRoomFor($ctx, $lineHeight);
            $this->registerBookmarksAt($ctx, $bookmarks, $ctx->cursorY);
            $ctx->cursorY -= $lineHeight;

            return;
        }

        [$lineHeight, $maxSizePt, $textLineHeight, $maxImageHeight] = $this->lineMetrics($wordItems, $p, $defaultStyle);

        // A line's notes go to the foot of its page: the line moves on when
        // it and they do not fit together. Notes in a table cell are the
        // row's to place.
        if ($ctx->footnoteGrowing && ! $ctx->inTableCell && ! $ctx->inHeaderFooterRender) {
            $notes = [];
            foreach ($wordItems as $item) {
                if (isset($item['footnote'])) {
                    $notes[] = (int) $item['footnote'];
                }
            }
            if ($notes !== []) {
                $this->ensureRoomFor($ctx, $lineHeight + $this->footnoteRoomNeeded($ctx, $notes));
                $this->reserveFootnoteRoom($ctx, $notes);
            }
        }

        $this->ensureRoomFor($ctx, $lineHeight);

        $this->registerBookmarksAt($ctx, $bookmarks, $ctx->cursorY);

        // Compute total content width of this line.
        // Tabs are treated as minimum-width spacers (tabStopPt) for wrap.
        $totalContentWidth = 0;
        $countWords = count($wordItems);
        for ($i = 0; $i < $countWords; $i++) {
            $item = $wordItems[$i];
            $style = $item['style'] ?? $defaultStyle;

            if (isset($item['footnote'])) {
                $this->registerFootnoteOnPage($ctx->currentPage, (int) $item['footnote']);
            }
            $type = $item['type'] ?? null;
            if ($type === 'image') {
                $totalContentWidth += $item['width'];
            } elseif ($type === 'tab') {
                $totalContentWidth += $this->tabStopPt;
            } else {
                $word = $item['text'] ?? '';
                $totalContentWidth += $this->measureWidth($word, $style);
            }
            if ($i + 1 < $countWords
                && $type !== 'tab'
                && ($wordItems[$i + 1]['type'] ?? null) !== 'tab'
                && ! ($wordItems[$i + 1]['glue'] ?? false)) {
                $totalContentWidth += $this->measureWidth(' ', $style);
            }
        }

        // Start X based on alignment.
        $availableWidth = $ctx->contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $effectiveAvail = $availableWidth - ($isFirstLine ? $firstLineIndent : 0);
        $startX = $ctx->leftX + $p->style->indentLeftPt + ($isFirstLine ? $firstLineIndent : 0);

        // Justify (Both/Distribute) — distribute extra space across gaps
        // between words. Last-line + lines with ≥80% fill are skipped
        // (CSS norm: avoid huge gaps).
        $extraPerGap = 0.0;
        $isJustify = ($p->style->alignment === Alignment::Both
            || $p->style->alignment === Alignment::Distribute);
        if ($isJustify && ! $isLastLine && $countWords > 1) {
            $slack = $effectiveAvail - $totalContentWidth;
            $fillRatio = $effectiveAvail > 0 ? $totalContentWidth / $effectiveAvail : 1.0;
            // Expand only if the line is at least 60% filled (avoid
            // absurdly large gaps on short lines).
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
                // is distributed via $extraPerGap at rendering time.
                break;
        }

        // CSS half-leading: the room a line height adds over the font's
        // content area is split evenly above and below the glyphs, so the
        // baseline sits at half the line height plus (ascent − content/2).
        // Ascent 0.905 and descent 0.212 em are the Arial/Liberation hhea
        // metrics browsers use; other text faces differ by a fraction of a
        // point. An inline image sits on the baseline, inside its line.
        $baselineY = $ctx->cursorY - max(
            $textLineHeight / 2 + $maxSizePt * (self::LINE_ASCENT - (self::LINE_ASCENT + self::LINE_DESCENT) / 2),
            $maxImageHeight + 1,
        );

        // TJ-array grouping — accumulate consecutive items with the same
        // effective style/baseline/size into a single showText call. Each
        // showText emits BT/Tf/Tm/Tj/ET — batching removes 4×N overhead bytes.
        // Don't batch when: justified (extraPerGap > 0), images, super/sub,
        // style change, link boundary change. Decorations (underline/strike)
        // are emitted inline per-batch.
        //
        // Link tracking + decorations work per-batch: when flushing a batch,
        // if all items are underlined — draw a line under the batch width.
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

        // Text batch accumulator.
        $batchText = '';
        $batchStartX = 0.0;
        $batchBaselineY = 0.0;
        $batchSizePt = 0.0;
        $batchStyle = null;
        $batchFont = null; // resolved font of the words in the batch
        $batchEndX = 0.0;  // running end-X for decorations + link tracking
        $flushBatch = function () use (&$batchText, &$batchStartX, &$batchBaselineY, &$batchSizePt, &$batchStyle, &$batchFont, &$batchEndX, $ctx): void {
            if ($batchText === '' || $batchStyle === null) {
                $batchText = '';
                $batchStyle = null;
                $batchFont = null;

                return;
            }
            $this->showText($ctx->currentPage, $batchText, $batchStartX, $batchBaselineY, $batchSizePt, $batchStyle);
            if ($batchStyle->underline || $batchStyle->strikethrough) {
                $this->drawTextDecorations(
                    $ctx->currentPage, $batchStartX, $batchBaselineY,
                    $batchEndX - $batchStartX, $batchSizePt, $batchStyle,
                );
            }
            $batchText = '';
            $batchStyle = null;
            $batchFont = null;
        };

        // Compatibility: is the current item compatible with the currently building batch?
        $compatible = function (RunStyle $itemStyle, float $itemSizePt, float $itemBaselineY, ?PdfFont $itemFont) use (&$batchStyle, &$batchSizePt, &$batchBaselineY, &$batchFont): bool {
            if ($batchStyle === null) {
                return false;
            }
            // The RESOLVED font must match, not just the style: with a
            // fallbackFonts chain, two equal-styled words in different
            // scripts resolve to different fonts (e.g. Latin → default,
            // CJK → fallback) and must not merge into one draw call.
            if ($itemFont !== $batchFont) {
                return false;
            }
            if (abs($itemSizePt - $batchSizePt) > 0.001) {
                return false;
            }
            if (abs($itemBaselineY - $batchBaselineY) > 0.001) {
                return false;
            }
            // Same color, super/sub, underline, strikethrough, letterSpacing, fontFamily, bold/italic.
            return $itemStyle->color === $batchStyle->color
                && $itemStyle->superscript === $batchStyle->superscript
                && $itemStyle->subscript === $batchStyle->subscript
                && $itemStyle->underline === $batchStyle->underline
                && $itemStyle->strikethrough === $batchStyle->strikethrough
                && ($itemStyle->letterSpacingPt ?? 0) === ($batchStyle->letterSpacingPt ?? 0)
                && $itemStyle->fontFamily === $batchStyle->fontFamily
                && $itemStyle->bold === $batchStyle->bold
                && $itemStyle->italic === $batchStyle->italic
                && $itemStyle->backgroundColor === $batchStyle->backgroundColor
                && $itemStyle->highlight === $batchStyle->highlight;
        };

        for ($i = 0; $i < $countWords; $i++) {
            $item = $wordItems[$i];
            $style = $item['style'] ?? $defaultStyle;

            // Tab item — advance x to next tab stop.
            if (($item['type'] ?? null) === 'tab') {
                $flushBatch();
                // A hanging indent is a tab stop of its own on the first line,
                // as in Word: a list label and a tab put the text on the indent.
                $hangingStop = $ctx->leftX + $p->style->indentLeftPt;
                if ($isFirstLine && $p->style->indentFirstLinePt < 0 && $x < $hangingStop - 0.01) {
                    $x = $hangingStop;

                    continue;
                }
                $relativeX = $x - $startX;
                $nextStop = ((int) ($relativeX / $this->tabStopPt) + 1) * $this->tabStopPt;
                $newX = $startX + $nextStop;
                // Ensure we move forward at least the minimum (if currently at a multiple of tabStopPt).
                if ($newX <= $x) {
                    $newX = $x + $this->tabStopPt;
                }
                $x = $newX;
                // Skip implicit space after tab (we placed exact x).
                continue;
            }

            // Image atom: flush text batch first, then draw image.
            if (($item['type'] ?? null) === 'image') {
                $flushBatch();
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
                if ($i + 1 < $countWords && ($wordItems[$i + 1]['type'] ?? null) !== 'tab') {
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
            $wordFont = $this->resolveEmbeddedFont($style, $word);

            $wordLink = $item['link'] ?? null;
            if ($wordLink !== $linkRef) {
                $flushBatch(); // link boundary — flush text segment
                $linkFlush();
                if ($wordLink !== null) {
                    $linkRef = $wordLink;
                    $linkStartX = $x;
                }
            }

            // Try to batch this word with the previous batch.
            $canBatch = $extraPerGap === 0.0 && $compatible($style, $sizePt, $wordBaselineY, $wordFont);
            if ($canBatch) {
                $batchText .= $word;
                $batchEndX = $x + $wordWidth;
            } else {
                $flushBatch();
                $batchText = $word;
                $batchStartX = $x;
                $batchBaselineY = $wordBaselineY;
                $batchSizePt = $sizePt;
                $batchStyle = $style;
                $batchFont = $wordFont;
                $batchEndX = $x + $wordWidth;
            }
            $x += $wordWidth;
            if ($linkRef !== null) {
                $linkLastX = $x;
            }

            if ($i + 1 < $countWords) {
                $nextItem = $wordItems[$i + 1];
                // If next item is tab, do not append space (tab sets exact x).
                if (($nextItem['type'] ?? null) === 'tab') {
                    continue;
                }
                // A glued run seam: there was no space in the text, so none may
                // be drawn (see tokenizeChildren).
                if ($nextItem['glue'] ?? false) {
                    continue;
                }
                $spaceWidth = $this->measureWidth(' ', $style) + $extraPerGap;
                $nextIsImage = ($nextItem['type'] ?? null) === 'image';
                $nextStyle = $nextItem['style'] ?? $defaultStyle;
                $nextSize = $nextStyle->sizePt ?? $this->defaultFontSizePt;
                $nextBaselineY = $baselineY;
                if ($nextStyle->superscript) {
                    $nextSize *= 0.7;
                    $nextBaselineY += $baseSizePt * 0.33;
                } elseif ($nextStyle->subscript) {
                    $nextSize *= 0.7;
                    $nextBaselineY -= $baseSizePt * 0.15;
                }
                // Add space to the current batch if the next item is also
                // text and compatible — then the space becomes part of a single showText.
                $nextLink = $nextItem['link'] ?? null;
                $spaceCanBatch = ! $nextIsImage
                    && $extraPerGap === 0.0
                    && $nextLink === $linkRef
                    && $compatible(
                        $nextStyle,
                        $nextSize,
                        $nextBaselineY,
                        $this->resolveEmbeddedFont($nextStyle, $nextItem['text'] ?? ''),
                    );
                if ($spaceCanBatch && $batchText !== '') {
                    // Append space to batch — next iteration appends word.
                    $batchText .= ' ';
                    $batchEndX = $x + $spaceWidth;
                } else {
                    // Space not batchable — flush + emit separately.
                    $flushBatch();
                    $x += $spaceWidth;
                    $this->showText($ctx->currentPage, ' ', $x - $spaceWidth, $baselineY, $sizePt, $style);
                    if ($linkRef !== null && $nextLink === $linkRef) {
                        $linkLastX = $x;
                    }

                    continue;
                }
                $x += $spaceWidth;
                if ($linkRef !== null && $nextLink === $linkRef) {
                    $linkLastX = $x;
                }
            }
        }
        $flushBatch();
        $linkFlush();

        $ctx->cursorY -= $lineHeight;
    }

    /**
     * Registers bookmark destinations for the current line top-Y.
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
     * Draws underline and/or strikethrough lines below/through the rendered word.
     *
     * Position relative to baseline (PDF coordinate system Y grows upward):
     *  - Underline: ~ baselineY - sizePt × 0.12 (slightly below baseline)
     *  - Strike:    ~ baselineY + sizePt × 0.28 (through x-height)
     * Stroke width: sizePt × 0.055 (thinner than glyphs).
     * Color: current text color (Run.style.color), default black.
     */
    /**
     * The colour under a run: its background, else its highlight (OOXML
     * names: yellow, darkBlue, …, or a hex value).
     */
    private function runShade(RunStyle $style): ?string
    {
        if ($style->backgroundColor !== null) {
            return $style->backgroundColor;
        }
        if ($style->highlight === null || strtolower($style->highlight) === 'none') {
            return null;
        }

        return [
            'yellow' => 'ffff00', 'green' => '00ff00', 'cyan' => '00ffff', 'magenta' => 'ff00ff',
            'blue' => '0000ff', 'red' => 'ff0000', 'darkblue' => '000080', 'darkcyan' => '008080',
            'darkgreen' => '008000', 'darkmagenta' => '800080', 'darkred' => '800000',
            'darkyellow' => '808000', 'darkgray' => '808080', 'lightgray' => 'c0c0c0',
            'black' => '000000', 'white' => 'ffffff',
        ][strtolower($style->highlight)] ?? (preg_match('/^#?[0-9a-f]{6}$/i', $style->highlight) === 1 ? ltrim($style->highlight, '#') : null);
    }

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
     * Renders text using the engine's resolved font (bold/italic variant if
     * registered, otherwise defaultFont, otherwise fallbackStandard).
     * Applies style.color via the rg-operator when set.
     */
    private function showText(Page $page, string $text, float $x, float $baselineY, float $sizePt, RunStyle $style): void
    {
        $shade = $this->runShade($style);
        if ($shade !== null && $text !== '') {
            // Under the text: from the descender to the top of the line box.
            [$r, $g, $b] = $this->hexToRgb($shade);
            $page->fillRect($x, $baselineY - $sizePt * 0.22, $this->measureWidth($text, $style), $sizePt * 1.15, $r, $g, $b);
        }

        // SHY (U+00AD) — invisible soft hyphen marker, strip before
        // drawing (visually it must not render except at the wrap point —
        // already handled via '-' append in the split helper).
        $text = self::stripSoftHyphens($text);
        $r = $g = $b = null;
        if ($style->color !== null) {
            [$r, $g, $b] = $this->hexToRgb($style->color);
        }
        $tracking = $style->letterSpacingPt ?? 0;
        $font = $this->resolveEmbeddedFont($style, $text);
        if ($font !== null) {
            $page->showEmbeddedText($text, $x, $baselineY, $font, $sizePt, $r, $g, $b, $tracking);
        } else {
            $page->showText($text, $x, $baselineY, $this->standardFontFor($style), $sizePt, $r, $g, $b, $tracking);
        }
    }

    /**
     * Measures width in pt — uses resolveEmbeddedFont for precise
     * metrics of the bold/italic variant.
     */
    /**
     * Width of the whitespace a paragraph opens with.
     *
     * Word processors position headings with runs of spaces instead of an
     * alignment: a document may carry twenty spaces before "Statement" and
     * expect it in the middle of the line. Words are split on whitespace and
     * the run is discarded, so such a heading printed hard against the left
     * margin. Measured here and added to the first-line indent, which is
     * exactly the mechanism it stands for.
     *
     * Only the opening whitespace of the paragraph counts: whitespace between
     * words is a separator, and treating it as position would change how
     * every line breaks.
     */
    private function leadingSpaceIndent(Paragraph $p, RunStyle $default): float
    {
        foreach ($p->children as $child) {
            if (! $child instanceof Run) {
                return 0.0;
            }
            if ($child->text === '') {
                continue;
            }
            if (preg_match('/^[ \x{00A0}]+/u', $child->text, $m) !== 1) {
                return 0.0;
            }

            return $this->measureWidth($m[0], $child->style->inheritFrom($default));
        }

        return 0.0;
    }

    private function measureWidth(string $text, RunStyle $style): float
    {
        // Everything the width depends on: the font (family, weight, slant
        // and, through the fallback chain, the text) and the size.
        $sizePt = $style->sizePt ?? $this->defaultFontSizePt;
        $key = ($style->bold ? 'b' : '-').($style->italic ? 'i' : '-').pack('e', $sizePt).$style->fontFamily."\0".$text;
        if (isset($this->widthCache[$key])) {
            return $this->widthCache[$key];
        }
        if (count($this->widthCache) >= self::WIDTH_CACHE_LIMIT) {
            $this->widthCache = [];
        }

        return $this->widthCache[$key] = $this->computeWidth($text, $style, $sizePt);
    }

    private function computeWidth(string $text, RunStyle $style, float $sizePt): float
    {
        // SHY is invisible → strip for width estimation.
        $text = self::stripSoftHyphens($text);
        $font = $this->resolveEmbeddedFont($style, $text);
        if ($font !== null) {
            return (new TextMeasurer($font, $sizePt))->widthPt($text);
        }

        return $this->standardWidth($text, $this->standardFontFor($style), $sizePt);
    }

    /**
     * The base-14 face for text without an embedded font: the family of
     * `fallbackStandard`, or the one a `fontFamily` names (serif → Times,
     * mono → Courier, sans → Helvetica), in the run's weight and slant.
     */
    private function standardFontFor(RunStyle $style): StandardFont
    {
        $key = ($style->bold ? 'b' : '-').($style->italic ? 'i' : '-').$style->fontFamily;

        return $this->standardFontCache[$key] ??= $this->resolveStandardFont($style);
    }

    private function resolveStandardFont(RunStyle $style): StandardFont
    {
        $base = self::standardFamily($this->fallbackStandard);
        foreach (self::families((string) $style->fontFamily) as $family) {
            $family = strtolower($family);
            $named = match (true) {
                str_contains($family, 'mono') || str_contains($family, 'courier') || str_contains($family, 'consol') => 'courier',
                str_contains($family, 'sans') || str_contains($family, 'helvetica') || str_contains($family, 'arial') => 'helvetica',
                str_contains($family, 'serif') || str_contains($family, 'times') || str_contains($family, 'georgia') => 'times',
                default => null,
            };
            if ($named !== null) {
                $base = $named;
                break;
            }
        }
        if ($base === null) {
            // Symbol or ZapfDingbats as the fallback: no faces to choose.
            return $this->fallbackStandard;
        }

        return match ($base.($style->bold ? '-b' : '').($style->italic ? '-i' : '')) {
            'helvetica' => StandardFont::Helvetica,
            'helvetica-b' => StandardFont::HelveticaBold,
            'helvetica-i' => StandardFont::HelveticaOblique,
            'helvetica-b-i' => StandardFont::HelveticaBoldOblique,
            'times' => StandardFont::TimesRoman,
            'times-b' => StandardFont::TimesBold,
            'times-i' => StandardFont::TimesItalic,
            'times-b-i' => StandardFont::TimesBoldItalic,
            'courier' => StandardFont::Courier,
            'courier-b' => StandardFont::CourierBold,
            'courier-i' => StandardFont::CourierOblique,
            default => StandardFont::CourierBoldOblique,
        };
    }

    /**
     * The families of a `fontFamily` that may be a CSS list.
     *
     * @return list<string>
     */
    private static function families(string $fontFamily): array
    {
        // Shared across renders; capped so a long-running worker fed many
        // distinct family strings doesn't grow it without bound.
        static $cache = [];
        if (count($cache) >= 256 && ! isset($cache[$fontFamily])) {
            $cache = [];
        }

        return $cache[$fontFamily] ??= array_values(array_filter(array_map('trim', explode(',', $fontFamily)), static fn (string $f): bool => $f !== ''));
    }

    private static function standardFamily(StandardFont $font): ?string
    {
        return match (true) {
            str_starts_with($font->value, 'Helvetica') => 'helvetica',
            str_starts_with($font->value, 'Times') => 'times',
            str_starts_with($font->value, 'Courier') => 'courier',
            default => null,
        };
    }

    /** Width of text in a base-14 font, from its WinAnsi advance widths. */
    private function standardWidth(string $text, StandardFont $font, float $sizePt): float
    {
        if (! StandardFontMetrics::has($font->value)) {
            return mb_strlen($text, 'UTF-8') * $sizePt * 0.5;
        }
        $units = StandardFontMetrics::stringWidth($font->value, WinAnsi::encodeLossy($text));

        return $units * $sizePt / 1000;
    }

    /** Width of a chart label in the chart's font. */
    private function chartTextWidth(string $text, float $sizePt): float
    {
        return $this->defaultFont !== null
            ? (new TextMeasurer($this->defaultFont, $sizePt))->widthPt($text)
            : $this->standardWidth($text, $this->fallbackStandard, $sizePt);
    }

    /**
     * U+00AD (SOFT HYPHEN, HTML &shy;) — invisible wrap hint.
     */
    public static function stripSoftHyphens(string $text): string
    {
        return str_replace("\u{00AD}", '', $text);
    }

    /**
     * Splits a word that does not fit on a line in one piece.
     *
     * Returns [head, tail]; the head always holds at least one character,
     * otherwise the splitting would loop forever. An empty tail means no split
     * was needed.
     *
     * @return array{0: string, 1: string}
     */
    private function splitOverlongWord(string $word, RunStyle $style, float $maxWidth): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($chars) < 2) {
            return [$word, ''];
        }

        $head = '';
        foreach ($chars as $index => $char) {
            $candidate = $head.$char;
            if ($index > 0 && $this->measureWidth($candidate, $style) > $maxWidth) {
                return [$head, mb_substr($word, $index, null, 'UTF-8')];
            }
            $head = $candidate;
        }

        return [$word, ''];
    }

    /**
     * Tries to split a word into (prefix + '-', remainder) at one of the
     * soft hyphen positions such that prefix + '-' fits in \$maxWidth.
     * Greedy: prefer the latest SHY that leaves more space for the
     * remaining string (but still fits in \$maxWidth).
     *
     * Returns [firstWithHyphen, remainder] or null if no split was possible.
     *
     * @return array{0: string, 1: string}|null
     */
    private function trySplitOnSoftHyphen(string $word, RunStyle $style, float $maxWidth): ?array
    {
        // SHY positions (byte-level in UTF-8 — U+00AD = 2 bytes: 0xC2 0xAD).
        $shy = "\u{00AD}";
        if (! str_contains($word, $shy)) {
            return null;
        }
        $parts = explode($shy, $word);
        // Try longest prefix first (greedy fit).
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $prefix = implode('', array_slice($parts, 0, $i));
            $remainder = implode($shy, array_slice($parts, $i));
            // measureWidth already strips SHY, so this is safe.
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

    /**
     * Effective line height in pt absolute.
     * lineHeightPt explicit > lineHeightMult * fontSize > default.
     */
    private function effectiveLineHeightPt(Paragraph $p, float $fontSize): float
    {
        if ($p->style->lineHeightPt !== null) {
            return $p->style->lineHeightPt;
        }
        $mult = $p->style->lineHeightMult ?? $this->defaultLineHeightMult;

        return $fontSize * $mult;
    }

    /**
     * Heading style cascade defaults: H1 = 24pt bold, H2 = 20pt bold, ...
     * Can be overridden by the caller via explicit RunStyle/ParagraphStyle.
     */
    private function headingStyle(?int $level): RunStyle
    {
        return $this->headingStyles[$level ?? 0] ??= $this->makeHeadingStyle($level);
    }

    private function makeHeadingStyle(?int $level): RunStyle
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
     * Renders Table: column-width distribution, row-by-row layout with
     * pre-measurement of each row (for row-height), drawing background
     * + borders, vertical alignment of cell content.
     *
     * Algorithm:
     *  1. Compute total table width + per-column widths
     *  2. Position table inside content area by table.style.alignment
     *  3. For each row:
     *     a. Pre-measure each cell's height → row height = max
     *     b. ensureRoomFor(row height) → page break if it doesn't fit
     *     c. Draw cell backgrounds, then content, then borders
     *  4. spaceBefore/After
     */
    private function renderTable(Table $t, LayoutContext $ctx): void
    {
        if ($t->isEmpty()) {
            return;
        }

        $ctx->cursorY -= $t->style->spaceBeforePt;

        // Tagged PDF — /Table is a grouping element: it owns no marked
        // content itself (nested MCIDs are invalid); its /K lists the
        // TR children in the structure tree.
        $taggedPdf = $ctx->pdf->isTagged();
        if ($taggedPdf) {
            $ctx->pdf->beginStructContainer('Table', $ctx->currentPage);
        }

        [$cols, $columnCount] = $this->tableGrid($t);
        $inner = $ctx->contentWidth - $t->style->indentLeftPt - $t->style->indentRightPt;
        $tableWidth = $this->computeTableWidth($t->style, $inner);
        $colWidths = $this->computeColumnWidths($t, $tableWidth, $columnCount, $cols);
        $tableLeftX = $this->computeTableLeftX($t->style->alignment, $ctx, $tableWidth, $t->style->indentLeftPt, $inner);
        $heights = $this->rowHeights($t, $colWidths, $cols);

        /** @var array<int, Row> $headerRows */
        $headerRows = array_filter($t->rows, fn (Row $r): bool => $r->isHeader);
        $repeatHeaders = function (array &$prevRowBottomByCol) use ($t, $headerRows, $colWidths, $cols, $heights, $tableLeftX, $ctx): void {
            foreach ($headerRows as $hi => $hr) {
                // On header row repeat, don't count it as last (this is still
                // the start of the page, more data rows will follow).
                $this->renderRow($t, $hr, $colWidths, $tableLeftX, $heights[$hi], $ctx, false, $prevRowBottomByCol, $cols[$hi]);
                $ctx->cursorY -= $heights[$hi];
            }
        };

        $totalRows = count($t->rows);
        // The table's own border is drawn around what lands on each page.
        $segmentTop = $ctx->cursorY;
        // Cross-row border priority tracking. Reset on page break
        // (repeated header rows form a fresh row sequence).
        $prevRowBottomByCol = [];
        // A row taller than what is left of the page: split, the head drawn,
        // the tail carried to the next page and split again as long as it does
        // not fit.
        $flowRow = function (Row $first, int $rowIdx, bool $isLastRow) use ($t, $colWidths, $cols, $ctx, $tableLeftX, $tableWidth, &$segmentTop, &$prevRowBottomByCol): void {
            $rowsToProcess = [$first];
            while ($rowsToProcess !== []) {
                $current = array_shift($rowsToProcess);
                $currentHeight = $this->measureRowHeight($t, $current, $colWidths, $cols[$rowIdx]);
                $room = $ctx->cursorY - $ctx->bottomY;
                if ($currentHeight <= $room) {
                    $this->renderRow($t, $current, $colWidths, $tableLeftX, $currentHeight, $ctx, $isLastRow, $prevRowBottomByCol, $cols[$rowIdx]);
                    $ctx->cursorY -= $currentHeight;

                    continue;
                }
                $next = $this->splitRowForPage($t, $current, $colWidths, $room, $cols[$rowIdx]);
                if ($next === null) {
                    // Nothing of it fits here. On a fresh page there is
                    // nowhere better to go: it is drawn as it is.
                    if ($ctx->cursorY < $ctx->topY - 0.01) {
                        $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                        $this->forcePageBreak($ctx);
                        $segmentTop = $ctx->cursorY;
                        $prevRowBottomByCol = [];
                        if ($this->measureRowHeight($t, $current, $colWidths, $cols[$rowIdx]) > $ctx->cursorY - $ctx->bottomY) {
                            $rowsToProcess = [$current, ...$rowsToProcess];

                            continue;
                        }
                    }
                    $this->renderRow($t, $current, $colWidths, $tableLeftX, $currentHeight, $ctx, $isLastRow, $prevRowBottomByCol, $cols[$rowIdx]);
                    $ctx->cursorY -= $currentHeight;

                    continue;
                }
                [$nextHead, $nextTail] = $next;
                $this->renderRow($t, $nextHead, $colWidths, $tableLeftX, $room, $ctx, false, $prevRowBottomByCol, $cols[$rowIdx]);
                $ctx->cursorY -= $room;
                $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                $this->forcePageBreak($ctx);
                $segmentTop = $ctx->cursorY;
                $prevRowBottomByCol = [];
                $rowsToProcess[] = $nextTail;
            }
        };
        // A growing footnote area: a row's notes get their room before the
        // row is fitted, and the notes of a row carried to a new page get it
        // there. A nested table leaves this to the row it sits in.
        $placeNotes = ! $ctx->inTableCell && $ctx->footnoteGrowing;
        foreach ($t->rows as $rowIdx => $row) {
            if ($placeNotes) {
                $this->reserveFootnoteRoom($ctx, $this->pageFootnotes[spl_object_id($ctx->currentPage)] ?? []);
                $this->reserveFootnoteRoom($ctx, $this->footnotesIn($row->cells));
            }
            $rowHeight = $heights[$rowIdx];
            $isLastRow = $rowIdx === $totalRows - 1;
            [$spanHeights, $spanLast] = $this->rowSpanGeometry($t, $rowIdx, $heights);

            // A table header is never left as the last line of a page: it
            // explains columns that this page will no longer carry, and it
            // repeats on the next one anyway. Move to a new page together with
            // the first row of data.
            $next = $t->rows[$rowIdx + 1] ?? null;
            if ($row->isHeader && $next !== null && ! $next->isHeader) {
                $together = $rowHeight + $heights[$rowIdx + 1];
                if ($ctx->cursorY - $rowHeight >= $ctx->bottomY && $ctx->cursorY - $together < $ctx->bottomY) {
                    $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                    $this->forcePageBreak($ctx);
                    $segmentTop = $ctx->cursorY;
                    $prevRowBottomByCol = [];
                }
            }

            // Rows bound by a vertical merge move to the next page together:
            // a merged cell cannot be cut. A group taller than a whole page
            // goes as it is.
            $groupEnd = $this->rowSpanGroupEnd($t, $rowIdx);
            if (! $row->isHeader && $groupEnd > $rowIdx) {
                $groupHeight = array_sum(array_slice($heights, $rowIdx, $groupEnd - $rowIdx + 1));
                $atPageTop = $ctx->cursorY >= $ctx->topY - 0.01;
                if (! $atPageTop && $ctx->cursorY - $groupHeight < $ctx->bottomY && $groupHeight <= $ctx->topY - $ctx->bottomY) {
                    $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                    $this->forcePageBreak($ctx);
                    $segmentTop = $ctx->cursorY;
                    $prevRowBottomByCol = [];
                    $repeatHeaders($prevRowBottomByCol);
                }
            }

            if ($ctx->cursorY - $rowHeight < $ctx->bottomY) {
                // The row does not fit. Before carrying it over whole, try to
                // split it: a row taller than the page otherwise leaves a
                // half-empty sheet behind, and a row that does not fit a page AT
                // ALL would go into an endless carry-over.
                //
                // Tried and rejected: "carry over whole any row that fits on a
                // clean page" (which is what Word did in the case we took
                // apart) — on the corpus that cost three pages of similarity and
                // pulled two more documents out of shape. Word's rule here is
                // more than a single condition.
                $available = $ctx->cursorY - $ctx->bottomY;
                // A row kept together that is taller than a whole page splits
                // after all, as in a browser — carried over, it would not fit
                // on the next page either.
                if ($row->cantSplit && $rowHeight > $ctx->topY - $ctx->bottomY) {
                    $row = new Row($row->cells, $row->isHeader, $row->heightPt, $row->minHeightPt);
                }
                $split = $this->splitRowForPage($t, $row, $colWidths, $available, $cols[$rowIdx]);

                if ($split !== null) {
                    [$head, $tail] = $split;
                    $this->renderRow($t, $head, $colWidths, $tableLeftX, $available, $ctx, false, $prevRowBottomByCol, $cols[$rowIdx]);
                    $ctx->cursorY -= $available;

                    $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                    $this->forcePageBreak($ctx);
                    $segmentTop = $ctx->cursorY;
                    $prevRowBottomByCol = [];
                    $repeatHeaders($prevRowBottomByCol);

                    // The tail may not fit again — it is handled by the same code,
                    // hence going back to the start of the iteration.
                    $row = $tail;
                    $rowHeight = $this->measureRowHeight($t, $row, $colWidths, $cols[$rowIdx]);
                    if ($ctx->cursorY - $rowHeight < $ctx->bottomY) {
                        $flowRow($tail, $rowIdx, $isLastRow);
                        continue;
                    }

                    $this->renderRow($t, $row, $colWidths, $tableLeftX, $rowHeight, $ctx, $isLastRow, $prevRowBottomByCol, $cols[$rowIdx]);
                    $ctx->cursorY -= $rowHeight;

                    continue;
                }

                $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
                $this->forcePageBreak($ctx);
                $segmentTop = $ctx->cursorY;
                $prevRowBottomByCol = [];
                if (! $row->isHeader) {
                    $repeatHeaders($prevRowBottomByCol);
                }
                // Carried over to a fresh page and still taller than what is
                // left of it: it is split here, as a row that started on
                // the page would be — drawn whole, it ran past the bottom and
                // its text was lost.
                if (! $row->isHeader && $ctx->cursorY - $rowHeight < $ctx->bottomY && $groupEnd === $rowIdx) {
                    $flowRow($row, $rowIdx, $isLastRow);

                    continue;
                }
            }

            $this->renderRow($t, $row, $colWidths, $tableLeftX, $rowHeight, $ctx, $isLastRow, $prevRowBottomByCol, $cols[$rowIdx], $spanHeights, $spanLast);
            $ctx->cursorY -= $rowHeight;
        }

        if ($placeNotes) {
            $this->reserveFootnoteRoom($ctx, $this->pageFootnotes[spl_object_id($ctx->currentPage)] ?? []);
        }
        $this->closeTableSegment($t, $ctx, $tableLeftX, $tableWidth, $segmentTop);
        $ctx->cursorY -= $t->style->spaceAfterPt;

        // End /Table struct.
        if ($taggedPdf) {
            $ctx->pdf->endStructContainer();
        }
    }

    /**
     * The table's own border (TableStyle::$borders) around the rows drawn on
     * the current page, from `$top` down to the cursor.
     */
    private function closeTableSegment(Table $t, LayoutContext $ctx, float $left, float $width, float $top): void
    {
        $height = $top - $ctx->cursorY;
        if ($t->style->borders === null || $height <= 0.01) {
            return;
        }
        $this->drawBoxBorders($ctx->currentPage, $left, $ctx->cursorY, $width, $height, $t->style->borders);
    }

    /**
     * Where each cell starts: the column index of every cell, row by row.
     * A cell merged down (rowSpan) holds its columns in the rows below, and
     * the cells of those rows start after them — as in HTML and Word.
     *
     * @return array{0: list<list<int>>, 1: int} the start columns, and the number of columns
     */
    private function tableGrid(Table $t): array
    {
        $held = []; // column → rows it is still held for
        $cols = [];
        $columnCount = 0;
        foreach ($t->rows as $row) {
            $starts = [];
            $col = 0;
            foreach ($row->cells as $cell) {
                while (($held[$col] ?? 0) > 0) {
                    $col++;
                }
                $starts[] = $col;
                if ($cell->rowSpan > 1) {
                    for ($k = 0; $k < $cell->columnSpan; $k++) {
                        $held[$col + $k] = $cell->rowSpan;
                    }
                }
                $col += $cell->columnSpan;
            }
            // Columns still held by a cell merged down from above count too —
            // once every merge has run out there are none.
            $stillHeld = array_keys(array_filter($held));
            $columnCount = max($columnCount, $col, $stillHeld === [] ? 0 : max($stillHeld) + 1);
            foreach ($held as $c => $left) {
                $held[$c] = max(0, $left - 1);
            }
            $cols[] = $starts;
        }

        return [$cols, $columnCount];
    }

    /**
     * Heights of all rows. A cell merged down spreads over the rows it
     * spans; when its content needs more, the last of them grows.
     *
     * @param  list<float>  $colWidths
     * @param  list<list<int>>  $cols
     * @return list<float>
     */
    private function rowHeights(Table $t, array $colWidths, array $cols): array
    {
        $heights = [];
        foreach ($t->rows as $i => $row) {
            $heights[] = $this->measureRowHeight($t, $row, $colWidths, $cols[$i]);
        }
        $last = count($heights) - 1;
        foreach ($t->rows as $i => $row) {
            foreach ($row->cells as $ci => $cell) {
                if ($cell->rowSpan < 2) {
                    continue;
                }
                $end = min($last, $i + $cell->rowSpan - 1);
                $need = $this->measureCellHeight($t, $cell, $this->spanWidth($colWidths, $cols[$i][$ci], $cell->columnSpan));
                $have = array_sum(array_slice($heights, $i, $end - $i + 1));
                if ($need > $have) {
                    $heights[$end] += $need - $have;
                }
            }
        }

        return $heights;
    }

    /**
     * The last row tied to `$rowIdx` by vertical merges (itself when none).
     */
    private function rowSpanGroupEnd(Table $t, int $rowIdx): int
    {
        $end = $rowIdx;
        $last = count($t->rows) - 1;
        for ($i = $rowIdx; $i <= $end && $i <= $last; $i++) {
            foreach ($t->rows[$i]->cells as $cell) {
                $end = max($end, min($last, $i + $cell->rowSpan - 1));
            }
        }

        return $end;
    }

    /**
     * For the merged cells of a row: their full height, and whether they
     * reach the last row of the table.
     *
     * @param  list<float>  $heights
     * @return array{0: array<int, float>, 1: array<int, bool>} keyed by cell index
     */
    private function rowSpanGeometry(Table $t, int $rowIdx, array $heights): array
    {
        $spanHeights = [];
        $spanLast = [];
        $last = count($heights) - 1;
        foreach ($t->rows[$rowIdx]->cells as $ci => $cell) {
            if ($cell->rowSpan < 2) {
                continue;
            }
            $end = min($last, $rowIdx + $cell->rowSpan - 1);
            $spanHeights[$ci] = array_sum(array_slice($heights, $rowIdx, $end - $rowIdx + 1));
            $spanLast[$ci] = $end === $last;
        }

        return [$spanHeights, $spanLast];
    }

    /**
     * @param  list<float>  $colWidths
     */
    private function spanWidth(array $colWidths, int $start, int $span): float
    {
        if ($span === 1) {
            return (float) ($colWidths[$start] ?? 0.0);
        }

        return (float) array_sum(array_slice($colWidths, $start, $span));
    }

    /** A cell's height with its padding, laid out in `$cellWidth`. */
    private function measureCellHeight(Table $t, Cell $cell, float $cellWidth): float
    {
        $cs = $this->effectiveCellStyle($t, $cell);
        $contentWidth = $cellWidth - $cs->paddingLeftPt - $cs->paddingRightPt;

        return max(
            $this->measureBlockListHeight($cell->children, $contentWidth) + $cs->paddingTopPt + $cs->paddingBottomPt,
            $cs->minHeightPt ?? 0.0,
        );
    }

    /**
     * Start columns of a row's cells laid side by side — for rows measured
     * outside a table grid.
     *
     * @return list<int>
     */
    private function sequentialColumns(Row $row): array
    {
        $starts = [];
        $col = 0;
        foreach ($row->cells as $cell) {
            $starts[] = $col;
            $col += $cell->columnSpan;
        }

        return $starts;
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
     * Column widths: `Table::$columnWidthsPt` when given; otherwise the
     * widths of the cells, the rest shared equally — or by content with
     * `TableStyle::$autoLayout`.
     *
     * @param  list<list<int>>  $cols  Start column of each cell (tableGrid()).
     * @return list<float>
     */
    private function computeColumnWidths(Table $t, float $tableWidth, int $columnCount, array $cols = []): array
    {
        if ($t->columnWidthsPt !== null && count($t->columnWidthsPt) === $columnCount) {
            // Scale to fit tableWidth if the sum differs.
            $sum = array_sum($t->columnWidthsPt);
            if ($sum > 0) {
                $scale = $tableWidth / $sum;

                return array_map(fn (float $w): float => $w * $scale, array_map(floatval(...), $t->columnWidthsPt));
            }
        }
        if ($columnCount === 0) {
            return [];
        }
        if ($cols === []) {
            [$cols] = $this->tableGrid($t);
        }

        // Widths the cells declare, column by column (a single-column cell
        // speaks for its column; the first one met wins).
        $declared = [];
        foreach ($t->rows as $i => $row) {
            foreach ($row->cells as $ci => $cell) {
                $col = $cols[$i][$ci] ?? null;
                if ($col === null || $cell->columnSpan !== 1 || isset($declared[$col])) {
                    continue;
                }
                $w = $cell->style->widthPt
                    ?? ($cell->style->widthPercent !== null ? $tableWidth * $cell->style->widthPercent / 100 : null);
                if ($w !== null && $w > 0) {
                    $declared[$col] = $w;
                }
            }
        }

        if (! $t->style->autoLayout) {
            if ($declared === []) {
                // Equal distribution.
                return array_fill(0, $columnCount, $tableWidth / $columnCount);
            }
            $free = $columnCount - count($declared);
            $rest = max(0.0, $tableWidth - array_sum($declared));
            $widths = [];
            for ($c = 0; $c < $columnCount; $c++) {
                $widths[] = $declared[$c] ?? ($free > 0 ? $rest / $free : 0.0);
            }

            return $this->fitWidths($widths, $tableWidth);
        }

        return $this->autoColumnWidths($t, $cols, $columnCount, $tableWidth, $declared);
    }

    /**
     * `table-layout: auto`: each column between its narrowest (the longest
     * word) and its widest (no wrapping) content, as browsers do. Declared
     * widths are kept; the rest is shared by how much each column wants.
     *
     * @param  list<list<int>>  $cols
     * @param  array<int, float>  $declared
     * @return list<float>
     */
    private function autoColumnWidths(Table $t, array $cols, int $columnCount, float $tableWidth, array $declared): array
    {
        $min = array_fill(0, $columnCount, 0.0);
        $max = array_fill(0, $columnCount, 0.0);
        foreach ($t->rows as $i => $row) {
            foreach ($row->cells as $ci => $cell) {
                $col = $cols[$i][$ci] ?? null;
                if ($col === null || $cell->columnSpan !== 1) {
                    continue;
                }
                $cs = $this->effectiveCellStyle($t, $cell);
                $pad = $cs->paddingLeftPt + $cs->paddingRightPt;
                [$cellMin, $cellMax] = $this->blockListContentWidths($cell->children, $tableWidth);
                $min[$col] = max($min[$col], $cellMin + $pad);
                $max[$col] = max($max[$col], $cellMax + $pad);
            }
        }
        foreach ($declared as $col => $w) {
            $min[$col] = $max[$col] = max($w, $min[$col]);
        }
        // A column only merged cells reach gets an equal claim.
        $known = array_filter($max, static fn (float $w): bool => $w > 0);
        $fallback = $known === [] ? $tableWidth / $columnCount : array_sum($known) / count($known);
        for ($c = 0; $c < $columnCount; $c++) {
            if ($max[$c] <= 0) {
                $max[$c] = $fallback;
            }
        }

        $sumMin = array_sum($min);
        $sumMax = array_sum($max);
        if ($sumMax <= $tableWidth) {
            // Everything fits unwrapped: the spare room goes to the columns
            // without a declared width, in proportion to their content.
            $spare = $tableWidth - $sumMax;
            $flexible = array_diff_key($max, $declared);
            $flexSum = array_sum($flexible);
            $widths = [];
            for ($c = 0; $c < $columnCount; $c++) {
                $extra = isset($flexible[$c]) && $flexSum > 0 ? $spare * $flexible[$c] / $flexSum : 0.0;
                $widths[] = $max[$c] + $extra;
            }

            return $this->fitWidths($widths, $tableWidth);
        }
        if ($sumMin >= $tableWidth) {
            return $this->fitWidths($min, $tableWidth);
        }
        // Between the two: every column gets its minimum, and the rest in
        // proportion to how much more it would like.
        $ratio = ($tableWidth - $sumMin) / ($sumMax - $sumMin);
        $widths = [];
        for ($c = 0; $c < $columnCount; $c++) {
            $widths[] = $min[$c] + ($max[$c] - $min[$c]) * $ratio;
        }

        return $widths;
    }

    /**
     * @param  list<float>  $widths
     * @return list<float>
     */
    private function fitWidths(array $widths, float $tableWidth): array
    {
        $sum = array_sum($widths);
        if ($sum <= 0) {
            return array_fill(0, count($widths), $tableWidth / max(1, count($widths)));
        }
        if (abs($sum - $tableWidth) < 0.01) {
            return $widths;
        }
        $scale = $tableWidth / $sum;

        return array_map(static fn (float $w): float => $w * $scale, $widths);
    }

    /**
     * The narrowest and the widest a list of blocks can be laid out: the
     * longest word, and every line unwrapped.
     *
     * @param  list<BlockElement>  $blocks
     * @return array{0: float, 1: float}
     */
    private function blockListContentWidths(array $blocks, float $available): array
    {
        $min = 0.0;
        $max = 0.0;
        foreach ($blocks as $block) {
            [$bMin, $bMax] = match (true) {
                $block instanceof Paragraph => $this->paragraphContentWidths($block),
                $block instanceof Image => [min($block->widthPt ?? $available, $available), min($block->widthPt ?? $available, $available)],
                $block instanceof SvgElement, $block instanceof SignatureField => [$block->widthPt, $block->widthPt],
                // A nested table, a list, a chart: it would take what it is given.
                default => [0.0, $available],
            };
            $min = max($min, $bMin);
            $max = max($max, $bMax);
        }

        return [$min, $max];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function paragraphContentWidths(Paragraph $p): array
    {
        $default = $p->defaultRunStyle->inheritFrom($this->headingStyle($p->headingLevel));
        /** @var list<array<string, mixed>> $items */
        $items = [];
        $this->tokenizeChildren($p->children, $default, $items, null);

        $min = 0.0;
        $max = 0.0;
        $line = 0.0;
        $lineHasContent = false;
        foreach ($items as $item) {
            if ($item['type'] === 'br') {
                $max = max($max, $line);
                $line = 0.0;
                $lineHasContent = false;

                continue;
            }
            $style = $item['style'] ?? $default;
            $w = match ($item['type']) {
                'word' => $this->measureWidth((string) ($item['text'] ?? ''), $style),
                'image' => (float) ($item['width'] ?? 0.0),
                default => 0.0,
            };
            $min = max($min, $w);
            $line += ($lineHasContent ? $this->measureWidth(' ', $style) : 0.0) + $w;
            $lineHasContent = true;
        }
        $max = max($max, $line);
        [, $right, , $left] = $this->paragraphInsets($p);
        $extra = $p->style->indentLeftPt + $p->style->indentRightPt + $left + $right;

        return [$min + $extra + max(0.0, $p->style->indentFirstLinePt), $max + $extra + max(0.0, $p->style->indentFirstLinePt)];
    }

    private function computeTableLeftX(Alignment $align, LayoutContext $ctx, float $tableWidth, float $indentLeft = 0.0, ?float $inner = null): float
    {
        $left = $ctx->leftX + $indentLeft;
        $inner ??= $ctx->contentWidth;

        return match ($align) {
            Alignment::Center => $left + ($inner - $tableWidth) / 2,
            Alignment::End => $left + $inner - $tableWidth,
            default => $left,
        };
    }

    /**
     * @param  list<float>  $colWidths
     * @param  ?list<int>  $starts  The start column of each cell; side by side when null.
     */
    private function measureRowHeight(Table $t, Row $row, array $colWidths, ?array $starts = null): float
    {
        if ($row->heightPt !== null) {
            return $row->heightPt;
        }
        $starts ??= $this->sequentialColumns($row);

        $maxHeight = 0;
        foreach ($row->cells as $ci => $cell) {
            // A cell merged down is spread over its rows by rowHeights().
            if ($cell->rowSpan > 1) {
                continue;
            }
            $cellHeight = $this->measureCellHeight($t, $cell, $this->spanWidth($colWidths, $starts[$ci], $cell->columnSpan));
            if ($cellHeight > $maxHeight) {
                $maxHeight = $cellHeight;
            }
        }

        // A row asking for a height gets it (or more, for its content);
        // otherwise at least one line of default text.
        if ($row->minHeightPt !== null) {
            return max($maxHeight, $row->minHeightPt);
        }

        return max($maxHeight, $this->defaultFontSizePt * $this->defaultLineHeightMult);
    }

    /**
     * @param  list<float>  $colWidths
     * @param  array<int, \Dskripchenko\PhpPdf\Style\Border>  &$prevRowBottomByCol
     *   Map column-index → bottom border of the cell occupying it in the prior row.
     *   Modified in-place: after renderRow() contains the current row's bottoms
     *   keyed by column position (accounting for column spans).
     */
    /**
     * Splits a row along the bottom edge of the page.
     *
     * A table row is a horizontal band, and breaking it means part of every
     * cell's content stays above the boundary while the rest continues on the
     * next page. Word does this by default ("Allow row to break across pages"),
     * and without it a single tall row left a half-empty sheet behind: in the
     * insurance policy we took apart, page four carried 40 words instead of
     * seven hundred.
     *
     * The split goes by blocks: a cell is a list of paragraphs, and the
     * boundary runs between them. Inside a paragraph we do not go — there is
     * nothing to break a line of text with, and the practical benefit is
     * already there: in real documents a tall row is made by a set of
     * paragraphs, not by one gigantic one.
     *
     * Returns `null` when there is nothing to split, or no point in splitting:
     *   - a header row (it is repeated whole on every page);
     *   - a vertical merge (rowSpan) — the break would drift apart from the
     *     neighbours;
     *   - not a single block of a single cell fits the space available —
     *     otherwise the empty part would be carried over forever.
     *
     * @param  list<float>  $colWidths
     * @param  ?list<int>  $starts  The start column of each cell; side by side when null.
     * @return array{0: Row, 1: Row}|null
     */
    private function splitRowForPage(Table $t, Row $row, array $colWidths, float $available, ?array $starts = null): ?array
    {
        $starts ??= $this->sequentialColumns($row);
        if ($row->isHeader || $row->cantSplit || $available <= 0.0) {
            return null;
        }

        foreach ($row->cells as $cell) {
            if ($cell->rowSpan > 1) {
                return null;
            }
        }

        $headCells = [];
        $tailCells = [];
        $anythingFits = false;
        $anythingLeft = false;
        $continued = false;

        foreach ($row->cells as $ci => $cell) {
            $cellWidth = $this->spanWidth($colWidths, $starts[$ci], $cell->columnSpan);

            $cs = $this->effectiveCellStyle($t, $cell);
            $contentWidth = $cellWidth - $cs->paddingLeftPt - $cs->paddingRightPt;
            $room = $available - $cs->paddingTopPt - $cs->paddingBottomPt;

            $head = [];
            $tail = [];
            $used = 0.0;
            foreach ($cell->children as $block) {
                $blockHeight = $this->measureBlockHeight($block, $contentWidth);
                if ($tail === [] && $used + $blockHeight <= $room) {
                    $head[] = $block;
                    $used += $blockHeight;

                    continue;
                }
                // A list splits between its items: a long list in a cell
                // would otherwise carry its whole row to the next page.
                if ($tail === [] && $block instanceof ListNode && ($parts = $this->splitListForHeight($block, $room - $used, $contentWidth)) !== null) {
                    $head[] = $parts[0];
                    $tail[] = $parts[1];
                    $used = $room;

                    continue;
                }
                $tail[] = $block;
            }

            if ($head !== []) {
                $anythingFits = true;
            }
            if ($tail !== []) {
                $anythingLeft = true;
            }
            if ($head !== [] && $tail !== []) {
                $continued = true;
            }

            $headCells[] = new Cell($head, $cell->style, $cell->columnSpan, $cell->rowSpan);
            $tailCells[] = new Cell($tail, $cell->style, $cell->columnSpan, $cell->rowSpan);
        }

        // Splitting makes sense only when at least one cell CONTINUES onto the
        // next page. If each of them landed whole on one side or the other, this
        // is not a carry-over but a severed link: the reader sees a row with an
        // empty cell, and its content as a separate row on the next page. That
        // is how the "directive on the left, its result on the right" row broke:
        // the text fitted, the picture did not, and they ended up on different
        // pages. Carrying the whole row over is strictly better in that case.
        if (! $anythingFits || ! $anythingLeft || ! $continued) {
            return null;
        }

        return [new Row($headCells, false), new Row($tailCells, false)];
    }

    /**
     * A list cut to a height: the items that fit, and the rest numbering on
     * from them. An item whose nested list does not fit is cut inside that
     * list, its rest going on under a label-less item of the same number.
     * Null when not even the first item's own text fits.
     *
     * @return array{0: ListNode, 1: ListNode}|null
     */
    private function splitListForHeight(ListNode $list, float $room, float $contentWidth, int $level = 0): ?array
    {
        $used = $list->spaceBeforePt;
        $number = $list->startAt - 1;
        $head = [];
        foreach ($list->items as $i => $item) {
            $number = $item->value ?? $number + 1;
            $single = static fn (ListItem $it, int $n): ListNode => new ListNode([$it], $list->format, $n, 0, 0, $list->indentPt);
            $height = $this->measureListNodeHeight($single($item, $number), $contentWidth, $level);
            if ($used + $height <= $room) {
                $head[] = $item;
                $used += $height;

                continue;
            }

            $rest = array_slice($list->items, $i + 1);
            $tailFirst = null;
            if ($item->nestedList !== null) {
                $own = $this->measureListNodeHeight($single(new ListItem($item->children, null, $item->marker, null, $item->indentPt), $number), $contentWidth, $level);
                $indent = ($list->indentPt ?? self::LIST_LEVEL_INDENT_PT) + $item->indentPt;
                $nested = $used + $own < $room
                    ? $this->splitListForHeight($item->nestedList, $room - $used - $own, $contentWidth - $indent, $level + 1)
                    : null;
                if ($nested !== null) {
                    $head[] = new ListItem($item->children, $nested[0], $item->marker, $item->value, $item->indentPt);
                    $tailFirst = new ListItem([], $nested[1], '', $number, $item->indentPt);
                }
            }
            if ($tailFirst === null) {
                if ($head === []) {
                    return null;
                }
                $tailFirst = new ListItem($item->children, $item->nestedList, $item->marker, $number, $item->indentPt);
            }

            return [
                new ListNode($head, $list->format, $list->startAt, $list->spaceBeforePt, 0, $list->indentPt),
                new ListNode([$tailFirst, ...$rest], $list->format, $number, 0, $list->spaceAfterPt, $list->indentPt),
            ];
        }

        return null;
    }

    /**
     * @param  list<float>  $colWidths
     * @param  array<int, \Dskripchenko\PhpPdf\Style\Border|null>  $prevRowBottomByCol
     * @param  ?list<int>  $starts  The start column of each cell; side by side when null.
     * @param  array<int, float>  $spanHeights  Full height of the cells merged down, by cell index.
     * @param  array<int, bool>  $spanLast  Whether such a cell reaches the last row.
     */
    private function renderRow(
        Table $t,
        Row $row,
        array $colWidths,
        float $tableLeftX,
        float $rowHeight,
        LayoutContext $ctx,
        bool $isLastRow = false,
        array &$prevRowBottomByCol = [],
        ?array $starts = null,
        array $spanHeights = [],
        array $spanLast = [],
    ): void {
        $starts ??= $this->sequentialColumns($row);
        // Tagged PDF — /TR is a grouping element (children: TD leaves).
        $taggedPdf = $ctx->pdf->isTagged();
        if ($taggedPdf) {
            $ctx->pdf->beginStructContainer('TR', $ctx->currentPage);
        }

        $rowTopY = $ctx->cursorY;
        $rowBottomY = $rowTopY - $rowHeight;
        $collapse = $t->style->borderCollapse;
        $columnCount = count($colWidths);
        // Border-spacing — separate mode shrinks each cell by spacing/2
        // on every side. Ignored in collapse mode.
        $spacing = ! $collapse ? $t->style->borderSpacingPt : 0;
        $gap = $spacing / 2;
        // Track previous cell's right border for "thicker wins"
        // priority resolution on shared left/right edges in collapse mode.
        $prevCellRight = null;
        // Collect this row's bottom borders by column index —
        // assigned to the caller's $prevRowBottomByCol for the next row's top compare.
        $currentRowBottoms = [];

        // 1. Backgrounds + content + borders in three passes (background below,
        //    borders above, content in between).
        foreach ($row->cells as $ci => $cell) {
            $colIdx = $starts[$ci];
            $cellX = $tableLeftX + $this->spanWidth($colWidths, 0, $colIdx);
            $cellWidth = $this->spanWidth($colWidths, $colIdx, $cell->columnSpan);
            $cellHeight = $spanHeights[$ci] ?? $rowHeight;
            $cellBottomY = $rowTopY - $cellHeight;
            $cs = $this->effectiveCellStyle($t, $cell);

            // Background fill (rounded if cornerRadius > 0).
            if ($cs->backgroundColor !== null) {
                [$r, $g, $b] = $this->hexToRgb($cs->backgroundColor);
                $drawX = $cellX + $gap;
                $drawY = $cellBottomY + $gap;
                $drawW = $cellWidth - 2 * $gap;
                $drawH = $cellHeight - 2 * $gap;
                if ($cs->cornerRadiusPt > 0) {
                    $ctx->currentPage->fillRoundedRect(
                        $drawX, $drawY, $drawW, $drawH,
                        $cs->cornerRadiusPt, $r, $g, $b,
                    );
                } else {
                    $ctx->currentPage->fillRect($drawX, $drawY, $drawW, $drawH, $r, $g, $b);
                }
            }
        }

        // Content.
        foreach ($row->cells as $ci => $cell) {
            $cellX = $tableLeftX + $this->spanWidth($colWidths, 0, $starts[$ci]);
            $cellWidth = $this->spanWidth($colWidths, $starts[$ci], $cell->columnSpan);
            $cs = $this->effectiveCellStyle($t, $cell);

            // Wrap cell in /TD struct.
            $cellMcid = null;
            if ($taggedPdf) {
                $cellMcid = $ctx->currentPage->nextMcid();
                $ctx->currentPage->beginMarkedContent('TD', $cellMcid);
            }

            // Cell context — suppress nested paragraph /P tags
            // (cell-level wrapping subsumes them).
            $savedSkip = $ctx->skipParagraphTag;
            $ctx->skipParagraphTag = true;
            $this->renderCellContent($cell, $cs, $cellX, $rowTopY, $cellWidth, $spanHeights[$ci] ?? $rowHeight, $ctx);
            $ctx->skipParagraphTag = $savedSkip;

            if ($taggedPdf && $cellMcid !== null) {
                $ctx->currentPage->endMarkedContent();
                $ctx->pdf->addStructElement('TD', $cellMcid, $ctx->currentPage);
            }
        }

        // Borders on top.
        $prevCellEnd = null;
        foreach ($row->cells as $ci => $cell) {
            $colIdx = $starts[$ci];
            $cellX = $tableLeftX + $this->spanWidth($colWidths, 0, $colIdx);
            $cellWidth = $this->spanWidth($colWidths, $colIdx, $cell->columnSpan);
            $cellHeight = $spanHeights[$ci] ?? $rowHeight;
            $cellBottomY = $rowTopY - $cellHeight;
            $cellIsLastRow = $spanLast[$ci] ?? $isLastRow;
            // The neighbour on the left shares this edge only when it ends
            // where this cell starts (a merged cell may sit in between).
            if ($prevCellEnd !== $colIdx) {
                $prevCellRight = null;
            }
            $prevCellEnd = $colIdx + $cell->columnSpan;
            $cs = $this->effectiveCellStyle($t, $cell);
            $borders = $cs->borders ?? $this->defaultBorderSet($t->style);

            if ($borders !== null) {
                $drawX = $cellX + $gap;
                $drawY = $cellBottomY + $gap;
                $drawW = $cellWidth - 2 * $gap;
                $drawH = $cellHeight - 2 * $gap;
                if ($cs->cornerRadiusPt > 0 && ! $collapse && $this->areBordersUniform($borders)) {
                    $b = $borders->top;
                    [$r, $g, $bb] = $this->hexToRgb($b->color);
                    $ctx->currentPage->strokeRoundedRect(
                        $drawX, $drawY, $drawW, $drawH,
                        $cs->cornerRadiusPt, $b->widthPt(), $r, $g, $bb,
                    );
                } elseif ($collapse) {
                    $isLastCol = ($colIdx + $cell->columnSpan) >= $columnCount;
                    // "Thicker wins" — within-row we compare
                    // current.left vs previous cell's right; cross-row we compare
                    // current.top vs previous row's bottom (by column index).
                    $leftBorder = $borders->left;
                    if ($prevCellRight !== null) {
                        $leftBorder = $this->moreProminent($leftBorder, $prevCellRight);
                    }
                    $topBorder = $borders->top;
                    if (isset($prevRowBottomByCol[$colIdx])) {
                        $topBorder = $this->moreProminent($topBorder, $prevRowBottomByCol[$colIdx]);
                    }
                    $collapsed = new BorderSet(
                        top: $topBorder,
                        left: $leftBorder,
                        bottom: $cellIsLastRow ? $borders->bottom : null,
                        right: $isLastCol ? $borders->right : null,
                    );
                    $this->drawCellBorders($ctx->currentPage, $drawX, $drawY, $drawW, $drawH, $collapsed);
                    $prevCellRight = $borders->right;
                    // Record this cell's bottom border on all spanned columns
                    // for cross-row priority comparison in the next row.
                    for ($k = $colIdx; $k < $colIdx + $cell->columnSpan; $k++) {
                        $currentRowBottoms[$k] = $borders->bottom;
                    }
                } else {
                    $this->drawCellBorders($ctx->currentPage, $drawX, $drawY, $drawW, $drawH, $borders);
                }
            }
        }

        // Pass current row's bottom borders to caller for the next row.
        $prevRowBottomByCol = $currentRowBottoms;

        // End /TR struct.
        if ($taggedPdf) {
            $ctx->pdf->endStructContainer();
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
        // Vertical alignment. Only center and bottom need the content's
        // height, so top-aligned cells (the default) skip measuring it again.
        $availableHeight = $rowHeight - $cs->paddingTopPt - $cs->paddingBottomPt;
        $vOffset = match ($cs->verticalAlign) {
            VerticalAlignment::Center => max(0, ($availableHeight - $this->measureBlockListHeight($cell->children, $contentWidth)) / 2),
            VerticalAlignment::Bottom => max(0, $availableHeight - $this->measureBlockListHeight($cell->children, $contentWidth)),
            default => 0,
        };

        $sub = new LayoutContext(
            pdf: $ctx->pdf,
            currentPage: $ctx->currentPage,
            cursorY: $cellTopY - $cs->paddingTopPt - $vOffset,
            leftX: $cellX + $cs->paddingLeftPt,
            contentWidth: $contentWidth,
            // Cell content does not trigger auto-page-break.
            bottomY: $cellTopY - $rowHeight - 10000,
            topY: $cellTopY - $cs->paddingTopPt - $vOffset,
            pageSetup: $ctx->pageSetup,
            // Propagate skipParagraphTag (table cells suppress nested /P tagging).
            skipParagraphTag: $ctx->skipParagraphTag,
            inTableCell: true,
        );

        foreach ($cell->children as $block) {
            // An absolutely placed block counts from the cell's own box, not
            // from where the vertical alignment put its content: a logo
            // anchored in a bottom-aligned cell was drawn at its bottom.
            if ($block instanceof Positioned && $block->inFlow && ! $block->fixed) {
                $savedY = $sub->cursorY;
                $sub->cursorY = $cellTopY - $cs->paddingTopPt;
                $this->renderBlock($block, $sub);
                $sub->cursorY = $savedY;

                continue;
            }
            $this->renderBlock($block, $sub);
        }

        // A footnote met inside a cell does not reach the foot of the page yet:
        // a cell has a context of its own, and whatever is collected in it goes
        // away with it.
        //
        // Tried and rejected: carrying them over into the parent context. A cell
        // is rendered more than once (measuring, splitting a row between pages),
        // and the footnote list grew on every pass — on a seven-page
        // questionnaire that ate half a gigabyte and brought the render down.
        // Before coming back to this, one has to understand how many times and
        // when a cell is rendered.
    }

    private function effectiveCellStyle(Table $t, Cell $cell): CellStyle
    {
        // Cell-style has priority. defaultCellStyle applies only when
        // cell.style is identical-equal to a bare-default CellStyle (i.e.
        // the user did not set their own style). This is a heuristic —
        // a full cascade with explicit merging is a future improvement.
        $default = CellStyle::default();
        if ($cell->style === $default || $cell->style == $default) {
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
     * CSS border priority resolution for collapse-mode shared edges.
     * Spec rules (ISO HTML 4 / CSS 2.1 §17.6.2.1):
     *   1. Style 'hidden' beats everything (null wins; no border drawn)
     *   2. Style 'none' loses to everything
     *   3. Wider border wins
     *   4. Same width: double > solid > dashed > dotted
     *   5. Same everything: first-cell-drawn wins (left/top side preferred)
     *
     * Returns the winning Border (or $a if equal).
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
     * Are all 4 sides identical (style + width + color) AND non-null?
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

    /**
     * Borders drawn INSIDE a box (x, y — bottom-left), as CSS paints a border
     * within the border box: each line is centred half its width in from the
     * edge, so the box occupies exactly its measured height.
     */
    private function drawBoxBorders(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $w, float $h, BorderSet $borders): void
    {
        if ($borders->top !== null) {
            $this->drawHorizontalBorder($page, $x, $y + $h - $borders->top->widthPt() / 2, $w, $borders->top);
        }
        if ($borders->bottom !== null) {
            $this->drawHorizontalBorder($page, $x, $y + $borders->bottom->widthPt() / 2, $w, $borders->bottom);
        }
        if ($borders->left !== null) {
            $this->drawVerticalBorder($page, $x + $borders->left->widthPt() / 2, $y, $h, $borders->left);
        }
        if ($borders->right !== null) {
            $this->drawVerticalBorder($page, $x + $w - $borders->right->widthPt() / 2, $y, $h, $borders->right);
        }
    }

    private function drawHorizontalBorder(\Dskripchenko\PhpPdf\Pdf\Page $page, float $x, float $y, float $w, Border $b): void
    {
        [$r, $g, $bb] = $this->hexToRgb($b->color);
        $totalWidth = $b->widthPt();
        if ($b->style === BorderStyle::Double) {
            // Double-line: 2 parallel strokes, each width=total/3,
            // gap between them = total/3. CSS spec: declared width = full span.
            $stroke = $totalWidth / 3;
            $offset = $totalWidth / 3 + $stroke / 2;
            $page->strokeRect($x, $y + $offset, $w, 0, $stroke, $r, $g, $bb);
            $page->strokeRect($x, $y - $offset, $w, 0, $stroke, $r, $g, $bb);

            return;
        }
        if ($this->drawPatternedBorder($page, $x, $y, $x + $w, $y, $b, $r, $g, $bb)) {
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
        if ($this->drawPatternedBorder($page, $x, $y + $h, $x, $y, $b, $r, $g, $bb)) {
            return;
        }
        $page->strokeRect($x, $y, 0, $h, $totalWidth, $r, $g, $bb);
    }

    /**
     * Dashed and dotted borders, the way browsers draw them: dashes three
     * line widths long, dots round and one width across; the gaps are
     * stretched so the line starts and ends on a dash or a dot, which keeps
     * the corners of a box closed. Returns false for the other styles.
     */
    private function drawPatternedBorder(
        \Dskripchenko\PhpPdf\Pdf\Page $page,
        float $x1, float $y1, float $x2, float $y2,
        Border $b, float $r, float $g, float $bb,
    ): bool {
        if ($b->style !== BorderStyle::Dashed && $b->style !== BorderStyle::Dotted) {
            return false;
        }
        $width = $b->widthPt();
        $length = hypot($x2 - $x1, $y2 - $y1);
        if ($width <= 0 || $length <= 0) {
            return true;
        }
        if ($b->style === BorderStyle::Dotted) {
            // A zero-length dash with a round cap is a dot; the dot centres
            // run from one end of the line to the other.
            $count = max(1, (int) round($length / (2 * $width)));
            $page->strokeDashedLine($x1, $y1, $x2, $y2, $width, $r, $g, $bb, [0.0, $length / $count], 0.0, 1);

            return true;
        }
        $dash = 3 * $width;
        $count = (int) round(($length + $dash) / (2 * $dash));
        if ($count < 2) {
            $page->strokeLine($x1, $y1, $x2, $y2, $width, $r, $g, $bb);

            return true;
        }
        $gap = ($length - $count * $dash) / ($count - 1);
        $page->strokeDashedLine($x1, $y1, $x2, $y2, $width, $r, $g, $bb, [$dash, $gap]);

        return true;
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
     * Pure measurement — no side effects on pdf/page.
     */
    private function measureBlockHeight(BlockElement $block, float $contentWidth): float
    {
        return match (true) {
            $block instanceof Paragraph => $this->measureParagraphHeight($block, $contentWidth),
            $block instanceof Heading => $this->measureParagraphHeight($this->headingParagraph($block), $contentWidth),
            $block instanceof Image => $this->measureImageHeight($block, $contentWidth),
            $block instanceof HorizontalRule => 12.0, // 6 + 0 + 6
            $block instanceof Table => $this->measureTableHeight($block, $contentWidth),
            $block instanceof ListNode => $this->measureListNodeHeight($block, $contentWidth, 0),
            // A vector block used to take no room: a table row holding a single
            // mark measured as zero height, and the drawing spilled out of it.
            $block instanceof SvgElement => $block->spaceBeforePt + $block->heightPt + $block->spaceAfterPt,
            $block instanceof SignatureField => $block->spaceBeforePt + $block->heightPt + $block->spaceAfterPt,
            default => 0,
        };
    }

    /**
     * The paragraph's outer height: margins, border and padding included —
     * a table row and the page flow reserve exactly what renderParagraph()
     * draws.
     */
    private function measureParagraphHeight(Paragraph $p, float $contentWidth): float
    {
        [$top, $right, $bottom, $left] = $this->paragraphInsets($p);

        return $p->style->spaceBeforePt + $top
            + $this->measureParagraphLines($p, $contentWidth - $left - $right)
            + $bottom + $p->style->spaceAfterPt;
    }

    /**
     * Border plus padding on each side of a paragraph's box.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} top, right, bottom, left
     */
    private function paragraphInsets(Paragraph $p): array
    {
        $s = $p->style;
        $b = $s->borders;

        return [
            $s->paddingTopPt + ($b?->top?->widthPt() ?? 0.0),
            $s->paddingRightPt + ($b?->right?->widthPt() ?? 0.0),
            $s->paddingBottomPt + ($b?->bottom?->widthPt() ?? 0.0),
            $s->paddingLeftPt + ($b?->left?->widthPt() ?? 0.0),
        ];
    }

    /** Height of the lines alone, laid out in `$contentWidth`. */
    private function measureParagraphLines(Paragraph $p, float $contentWidth): float
    {
        $headingStyle = $this->headingStyle($p->headingLevel);
        $effectiveDefault = $p->defaultRunStyle->inheritFrom($headingStyle);

        // The same items and the same line breaking as renderParagraph.
        /** @var list<array<string, mixed>> $items */
        $items = [];
        $this->tokenizeChildren($p->children, $effectiveDefault, $items, null);
        $availableWidth = $contentWidth - $p->style->indentLeftPt - $p->style->indentRightPt;
        $firstLineExtraIndent = $p->style->indentFirstLinePt + $this->leadingSpaceIndent($p, $effectiveDefault);

        $lines = $this->breakLines($items, $effectiveDefault, $availableWidth, $firstLineExtraIndent);
        if ($lines === []) {
            // An empty paragraph still takes a line.
            return $this->lineMetrics([], $p, $effectiveDefault)[0];
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $wordItems = array_values(array_filter($line['items'], static fn (array $it): bool => $it['type'] !== 'bookmark'));
            $total += $this->lineMetrics($wordItems, $p, $effectiveDefault)[0];
        }

        return $total;
    }

    private function measureImageHeight(Image $img, float $contentWidth): float
    {
        // A floating object takes no room in the flow — and none in the
        // measurement either, otherwise a cell or a page would reserve height
        // for it.
        if ($img->outOfFlow) {
            return 0.0;
        }

        $contentWidth -= $img->indentLeftPt + $img->indentRightPt;
        [$w, $h] = $img->effectiveSizePt($contentWidth);
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

        foreach ($list->items as $item) {
            $baseIndent = ($list->indentPt ?? self::LIST_LEVEL_INDENT_PT) + $item->indentPt;
            foreach ($item->children as $child) {
                $total += $this->measureBlockHeight($child, $contentWidth - $baseIndent);
            }
            if ($item->nestedList !== null) {
                $total += $this->measureListNodeHeight($item->nestedList, $contentWidth - $baseIndent, $level + 1);
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
        [$cols, $columnCount] = $this->tableGrid($t);
        $tableWidth = $this->computeTableWidth($t->style, $contentWidth - $t->style->indentLeftPt - $t->style->indentRightPt);
        $colWidths = $this->computeColumnWidths($t, $tableWidth, $columnCount, $cols);

        $total = $t->style->spaceBeforePt + array_sum($this->rowHeights($t, $colWidths, $cols));
        $total += $t->style->spaceAfterPt;

        return $total;
    }

    /**
     * Indent per nesting level (pt).
     */
    private const LIST_LEVEL_INDENT_PT = 18.0;

    /**
     * Renders a bullet/ordered list by injecting a marker into the first
     * Paragraph of each item + hanging-indent (via ParagraphStyle
     * indentLeftPt + indentFirstLinePt = -markerWidth).
     *
     * Nested ListNode (via ListItem.nestedList) is rendered recursively
     * with an increased $level.
     */
    /**
     * @param  float  $parentIndent  Where the enclosing item's text starts, from the content edge.
     */
    private function renderListNode(ListNode $list, LayoutContext $ctx, int $level, float $parentIndent): void
    {
        if ($list->isEmpty()) {
            return;
        }
        $ctx->cursorY -= $list->spaceBeforePt;

        // Tagged PDF — /L is a grouping element (children: LI leaves).
        $taggedPdf = $ctx->pdf->isTagged();
        if ($taggedPdf) {
            $ctx->pdf->beginStructContainer('L', $ctx->currentPage);
        }

        $format = $list->effectiveFormat();

        $number = $list->startAt - 1;
        foreach ($list->items as $item) {
            $number = $item->value ?? $number + 1;
            $marker = $item->marker ?? $this->formatListMarker($number, $format);
            // The list's indent (the usual one per level, or its CSS) plus
            // the item's own, from where the enclosing item's text starts.
            $baseIndent = $parentIndent + ($list->indentPt ?? self::LIST_LEVEL_INDENT_PT) + $item->indentPt;

            // An item with no label and no content only carries its nested
            // list (it stands for a parent outside the list).
            if ($marker === '' && $item->children === []) {
                if ($item->nestedList !== null) {
                    $this->renderListNode($item->nestedList, $ctx, $level + 1, $baseIndent);
                }

                continue;
            }

            // Per-item /LI wrap.
            $itemMcid = null;
            if ($taggedPdf) {
                $itemMcid = $ctx->currentPage->nextMcid();
                $ctx->currentPage->beginMarkedContent('LI', $itemMcid);
            }

            // Suppress nested /P tags inside list items (item-level /LI
            // subsumes paragraph wrapping per PDF/UA spec).
            $savedSkip = $ctx->skipParagraphTag;
            $ctx->skipParagraphTag = true;
            $this->renderListItem($item, $marker, $baseIndent, $ctx, $level);
            $ctx->skipParagraphTag = $savedSkip;

            if ($taggedPdf && $itemMcid !== null) {
                $ctx->currentPage->endMarkedContent();
                $ctx->pdf->addStructElement('LI', $itemMcid, $ctx->currentPage);
            }
        }

        $ctx->cursorY -= $list->spaceAfterPt;

        if ($taggedPdf) {
            $ctx->pdf->endStructContainer();
        }
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
            // Empty item — render only marker.
            $children = [new Paragraph([new Run('')])];
        }

        $firstChild = $children[0];
        if ($firstChild instanceof Paragraph) {
            // Prepend marker to first paragraph's first child by injecting
            // a new Run. Hanging indent via indentLeftPt+indentFirstLinePt.
            // The label and a tab: the hanging indent stops the tab, and the
            // text starts on the indent whatever the label's width.
            $newFirstChildren = [new Run($marker === '' ? '' : rtrim($marker)."\t"), ...$firstChild->children];
            // The label hangs in front of the text, never past the content edge.
            $newStyle = $firstChild->style->copy(
                indentLeftPt: $baseIndent,
                indentFirstLinePt: -min(self::LIST_LEVEL_INDENT_PT, $baseIndent),
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
                    // Non-paragraph (Image/Table/etc.) — render via sub-context
                    // with indented leftX.
                    $this->renderIndentedBlock($child, $baseIndent, $ctx);
                }
            }
        } else {
            // First child is not a paragraph. Render marker as a standalone
            // paragraph + then all children with indent.
            if ($marker !== '') {
                $markerP = new Paragraph(
                    children: [new Run(trim($marker))],
                    style: new ParagraphStyle(indentLeftPt: max(0.0, $baseIndent - self::LIST_LEVEL_INDENT_PT)),
                );
                $this->renderBlock($markerP, $ctx);
            }
            foreach ($children as $child) {
                $this->renderIndentedBlock($child, $baseIndent, $ctx);
            }
        }

        if ($item->nestedList !== null) {
            $this->renderListNode($item->nestedList, $ctx, $level + 1, $baseIndent);
        }
    }

    /**
     * Renders BlockElement (Image/Table/etc.) with increased leftX (sub-ctx).
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
        return $f === ListFormat::Bullet ? "\u{2022}  " : $f->number($n).'. ';
    }

    /**
     * Splits text into "words" by whitespace. Does NOT preserve multiple
     * spaces (single split). Empty strings filtered out.
     *
     * @return list<string>
     */
    private function splitWords(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
