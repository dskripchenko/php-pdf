<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 232: HTML CSS extensions — text-indent, border (shorthand + per-side).
 */
final class HtmlBorderIndentTest extends TestCase
{
    private function parseFirst(string $html): Paragraph
    {
        $blocks = (new HtmlParser)->parse($html);
        return $blocks[0];
    }

    // ---- text-indent ----

    #[Test]
    public function text_indent_pt(): void
    {
        $p = $this->parseFirst('<p style="text-indent: 24pt">First line indented</p>');
        self::assertSame(24.0, $p->style->indentFirstLinePt);
    }

    #[Test]
    public function text_indent_px(): void
    {
        // 16px → 12pt
        $p = $this->parseFirst('<p style="text-indent: 16px">indented</p>');
        self::assertEqualsWithDelta(12.0, $p->style->indentFirstLinePt, 0.01);
    }

    #[Test]
    public function text_indent_em(): void
    {
        // 2em ≈ 24pt
        $p = $this->parseFirst('<p style="text-indent: 2em">indented</p>');
        self::assertSame(24.0, $p->style->indentFirstLinePt);
    }

    // ---- border shorthand ----

    #[Test]
    public function border_all_sides_via_shorthand(): void
    {
        $p = $this->parseFirst('<p style="border: 1pt solid black">bordered</p>');
        $borders = $p->style->borders;
        self::assertNotNull($borders);
        self::assertNotNull($borders->top);
        self::assertNotNull($borders->right);
        self::assertNotNull($borders->bottom);
        self::assertNotNull($borders->left);
    }

    #[Test]
    public function border_color_parsed(): void
    {
        $p = $this->parseFirst('<p style="border: 1pt solid #ff0000">red border</p>');
        self::assertSame('ff0000', $p->style->borders->top->color);
    }

    #[Test]
    public function border_width(): void
    {
        $p = $this->parseFirst('<p style="border: 2pt solid black">thicker</p>');
        // 2pt = 16 eighths
        self::assertSame(16, $p->style->borders->top->sizeEighthsOfPoint);
    }

    #[Test]
    public function border_style_double(): void
    {
        $p = $this->parseFirst('<p style="border: 1pt double black">double</p>');
        self::assertSame(BorderStyle::Double, $p->style->borders->top->style);
    }

    #[Test]
    public function border_style_dashed(): void
    {
        $p = $this->parseFirst('<p style="border: 1pt dashed black">dashed</p>');
        self::assertSame(BorderStyle::Dashed, $p->style->borders->top->style);
    }

    #[Test]
    public function border_style_dotted(): void
    {
        $p = $this->parseFirst('<p style="border: 1pt dotted black">dotted</p>');
        self::assertSame(BorderStyle::Dotted, $p->style->borders->top->style);
    }

    #[Test]
    public function border_none_returns_null(): void
    {
        $p = $this->parseFirst('<p style="border: none">unbordered</p>');
        self::assertNull($p->style->borders);
    }

    // ---- per-side borders ----

    #[Test]
    public function border_top_only(): void
    {
        $p = $this->parseFirst('<p style="border-top: 2pt solid red">top border</p>');
        self::assertNotNull($p->style->borders->top);
        self::assertNull($p->style->borders->right);
        self::assertNull($p->style->borders->bottom);
        self::assertNull($p->style->borders->left);
        self::assertSame('ff0000', $p->style->borders->top->color);
    }

    #[Test]
    public function border_bottom_only(): void
    {
        $p = $this->parseFirst('<p style="border-bottom: 1pt solid #00ff00">bottom</p>');
        self::assertNull($p->style->borders->top);
        self::assertNotNull($p->style->borders->bottom);
    }

    #[Test]
    public function border_mixed_shorthand_plus_override(): void
    {
        // border: 1pt + border-top: 3pt → top=3pt, others=1pt.
        $p = $this->parseFirst(
            '<p style="border: 1pt solid black; border-top: 3pt solid red">mixed</p>'
        );
        $borders = $p->style->borders;
        // CSS specificity: longhand wins.
        self::assertSame(24, $borders->top->sizeEighthsOfPoint); // 3pt = 24/8
        self::assertSame('ff0000', $borders->top->color);
        // Other sides from shorthand: 1pt = 8/8
        self::assertSame(8, $borders->bottom->sizeEighthsOfPoint);
        self::assertSame('000000', $borders->bottom->color);
    }

    #[Test]
    public function no_border_no_attribute(): void
    {
        $p = $this->parseFirst('<p>no border</p>');
        self::assertNull($p->style->borders);
    }
}
