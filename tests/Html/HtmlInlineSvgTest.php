<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 236: inline <svg> element parsing.
 */
final class HtmlInlineSvgTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    #[Test]
    public function inline_svg_as_block(): void
    {
        $blocks = $this->parse(
            '<svg width="100" height="50"><circle cx="50" cy="25" r="20" fill="red"/></svg>'
        );
        self::assertCount(1, $blocks);
        self::assertInstanceOf(SvgElement::class, $blocks[0]);
    }

    #[Test]
    public function inline_svg_width_height_parsed(): void
    {
        $blocks = $this->parse('<svg width="200" height="150"></svg>');
        $svg = $blocks[0];
        self::assertInstanceOf(SvgElement::class, $svg);
        self::assertEqualsWithDelta(150.0, $svg->widthPt, 0.1); // 200px = 150pt
        self::assertEqualsWithDelta(112.5, $svg->heightPt, 0.1); // 150px = 112.5pt
    }

    #[Test]
    public function inline_svg_default_size_when_missing(): void
    {
        $blocks = $this->parse('<svg></svg>');
        $svg = $blocks[0];
        self::assertSame(100.0, $svg->widthPt);
        self::assertSame(100.0, $svg->heightPt);
    }

    #[Test]
    public function inline_svg_preserves_content(): void
    {
        $blocks = $this->parse(
            '<svg width="100" height="100"><rect width="50" height="50" fill="blue"/></svg>'
        );
        $svg = $blocks[0];
        self::assertStringContainsString('<rect', $svg->svgXml);
        self::assertStringContainsString('fill="blue"', $svg->svgXml);
    }

    #[Test]
    public function inline_svg_within_paragraph(): void
    {
        // SVG inside <p> — parser extracts inline или wraps somehow.
        $blocks = $this->parse(
            '<p>Before <svg width="20" height="20"></svg> after</p>'
        );
        // Paragraph contains text + svg inline.
        $p = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $p);
        $hasSvg = false;
        foreach ($p->children as $c) {
            if ($c instanceof SvgElement) {
                $hasSvg = true;
                break;
            }
        }
        self::assertTrue($hasSvg);
    }

    #[Test]
    public function multiple_svgs(): void
    {
        $blocks = $this->parse(
            '<svg width="50" height="50"></svg>
             <svg width="60" height="60"></svg>'
        );
        $svgs = array_filter($blocks, fn ($b) => $b instanceof SvgElement);
        self::assertCount(2, $svgs);
    }

    #[Test]
    public function svg_in_table_cell(): void
    {
        $blocks = $this->parse(
            '<table><tr><td><svg width="30" height="30"></svg></td></tr></table>'
        );
        $cell = $blocks[0]->rows[0]->cells[0];
        // Cell content should contain SVG.
        $hasSvg = false;
        foreach ($cell->children as $b) {
            if ($b instanceof SvgElement) {
                $hasSvg = true;
                break;
            }
            // Or might be wrapped в Paragraph if treated as inline-ish.
            if ($b instanceof Paragraph) {
                foreach ($b->children as $inner) {
                    if ($inner instanceof SvgElement) {
                        $hasSvg = true;
                    }
                }
            }
        }
        self::assertTrue($hasSvg, 'SVG should appear в cell content');
    }
}
