<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 224: HTML block-level CSS — text-align, margin, padding,
 * background-color, line-height.
 */
final class HtmlBlockStyleTest extends TestCase
{
    private function parseFirst(string $html): Paragraph|Heading
    {
        $blocks = (new HtmlParser)->parse($html);
        return $blocks[0];
    }

    #[Test]
    public function text_align_center(): void
    {
        $p = $this->parseFirst('<p style="text-align: center">centered</p>');
        self::assertSame(Alignment::Center, $p->style->alignment);
    }

    #[Test]
    public function text_align_right_via_alias_end(): void
    {
        $p = $this->parseFirst('<p style="text-align: right">right</p>');
        self::assertSame(Alignment::End, $p->style->alignment);
    }

    #[Test]
    public function text_align_left(): void
    {
        $p = $this->parseFirst('<p style="text-align: left">left</p>');
        self::assertSame(Alignment::Start, $p->style->alignment);
    }

    #[Test]
    public function text_align_justify(): void
    {
        $p = $this->parseFirst('<p style="text-align: justify">justified content here</p>');
        self::assertSame(Alignment::Both, $p->style->alignment);
    }

    #[Test]
    public function background_color_named(): void
    {
        $p = $this->parseFirst('<p style="background-color: yellow">y</p>');
        self::assertSame('ffff00', $p->style->backgroundColor);
    }

    #[Test]
    public function line_height_multiplier(): void
    {
        $p = $this->parseFirst('<p style="line-height: 1.5">spaced</p>');
        self::assertSame(1.5, $p->style->lineHeightMult);
    }

    #[Test]
    public function line_height_percentage(): void
    {
        $p = $this->parseFirst('<p style="line-height: 150%">spaced</p>');
        self::assertSame(1.5, $p->style->lineHeightMult);
    }

    #[Test]
    public function padding_shorthand_one_value(): void
    {
        $p = $this->parseFirst('<p style="padding: 10pt">content</p>');
        self::assertSame(10.0, $p->style->paddingTopPt);
        self::assertSame(10.0, $p->style->paddingRightPt);
        self::assertSame(10.0, $p->style->paddingBottomPt);
        self::assertSame(10.0, $p->style->paddingLeftPt);
    }

    #[Test]
    public function padding_shorthand_two_values(): void
    {
        $p = $this->parseFirst('<p style="padding: 10pt 20pt">content</p>');
        self::assertSame(10.0, $p->style->paddingTopPt);
        self::assertSame(20.0, $p->style->paddingRightPt);
        self::assertSame(10.0, $p->style->paddingBottomPt);
        self::assertSame(20.0, $p->style->paddingLeftPt);
    }

    #[Test]
    public function padding_shorthand_four_values(): void
    {
        $p = $this->parseFirst('<p style="padding: 5pt 10pt 15pt 20pt">content</p>');
        self::assertSame(5.0, $p->style->paddingTopPt);
        self::assertSame(10.0, $p->style->paddingRightPt);
        self::assertSame(15.0, $p->style->paddingBottomPt);
        self::assertSame(20.0, $p->style->paddingLeftPt);
    }

    #[Test]
    public function padding_individual_override(): void
    {
        $p = $this->parseFirst('<p style="padding-top: 8pt; padding-left: 16pt">content</p>');
        self::assertSame(8.0, $p->style->paddingTopPt);
        self::assertSame(16.0, $p->style->paddingLeftPt);
    }

    #[Test]
    public function margin_shorthand_maps_к_space_before_after(): void
    {
        $p = $this->parseFirst('<p style="margin: 12pt 0">content</p>');
        // margin top/bottom = 12pt → spaceBefore/After
        self::assertSame(12.0, $p->style->spaceBeforePt);
        self::assertSame(12.0, $p->style->spaceAfterPt);
        // margin left/right = 0 → indentLeft/Right
        self::assertSame(0.0, $p->style->indentLeftPt);
        self::assertSame(0.0, $p->style->indentRightPt);
    }

    #[Test]
    public function margin_individual_sides(): void
    {
        $p = $this->parseFirst('<p style="margin-top: 6pt; margin-left: 18pt">content</p>');
        self::assertSame(6.0, $p->style->spaceBeforePt);
        self::assertSame(18.0, $p->style->indentLeftPt);
    }

    #[Test]
    public function multiple_css_properties_combined(): void
    {
        $p = $this->parseFirst(
            '<p style="text-align: center; padding: 8pt; background-color: #eee; line-height: 1.6">'
            .'Combined styling</p>'
        );
        self::assertSame(Alignment::Center, $p->style->alignment);
        self::assertSame(8.0, $p->style->paddingTopPt);
        self::assertSame(8.0, $p->style->paddingLeftPt);
        self::assertSame('eeeeee', $p->style->backgroundColor);
        self::assertSame(1.6, $p->style->lineHeightMult);
    }

    #[Test]
    public function heading_supports_block_style(): void
    {
        $h = $this->parseFirst('<h1 style="text-align: center">Title</h1>');
        self::assertInstanceOf(Heading::class, $h);
        self::assertSame(Alignment::Center, $h->style?->alignment);
    }

    #[Test]
    public function no_style_attribute_uses_defaults(): void
    {
        $p = $this->parseFirst('<p>plain</p>');
        self::assertSame(Alignment::Start, $p->style->alignment);
        self::assertNull($p->style->backgroundColor);
        self::assertSame(0.0, $p->style->paddingTopPt);
    }

    #[Test]
    public function padding_in_pixels(): void
    {
        // 16px → 12pt
        $p = $this->parseFirst('<p style="padding: 16px">content</p>');
        self::assertEqualsWithDelta(12.0, $p->style->paddingTopPt, 0.01);
    }
}
