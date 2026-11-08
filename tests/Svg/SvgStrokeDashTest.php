<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `stroke-dasharray` and `stroke-dashoffset` give a stroke its dashes:
 * `[on off …] phase d` in a graphics state of the shape's own, so the next
 * shape strokes solid again. The lengths scale to the page as the stroke
 * width does. The SVG is drawn 1:1 here (a 100×100 viewBox in a 100×100
 * box), so user units are page units.
 */
final class SvgStrokeDashTest extends TestCase
{
    #[Test]
    public function dashed_line_sets_the_pattern_before_its_stroke(): void
    {
        $bytes = self::render('<line x1="0" y1="50" x2="100" y2="50" stroke="#000" stroke-dasharray="4 4"/>');

        self::assertMatchesRegularExpression('@q\n\[4 4\] 0 d\n.*?\nS\nQ\nQ\n@s', $bytes);
    }

    #[Test]
    public function the_pattern_does_not_reach_the_next_shape(): void
    {
        $bytes = self::render(
            '<line x1="0" y1="10" x2="100" y2="10" stroke="#000" stroke-dasharray="4 4"/>'
            .'<line x1="0" y1="90" x2="100" y2="90" stroke="#f00"/>',
        );

        self::assertSame(1, substr_count($bytes, ' d'."\n"));
        // The dashed line's state is closed before the solid line opens.
        self::assertMatchesRegularExpression('@\[4 4\] 0 d\n(?:(?!\nq\n).)*?S\nQ\nQ\nq\n[^\n]+ w\n1 0 0 RG@s', $bytes);
        self::assertStatesBalanced($bytes);
    }

    #[Test]
    public function the_pattern_is_scaled_as_the_stroke_width(): void
    {
        // A 50-unit viewBox in the 100-unit box: everything doubles.
        $bytes = self::render(
            '<line x1="0" y1="25" x2="50" y2="25" stroke="#000" stroke-width="1" stroke-dasharray="4 2" stroke-dashoffset="1"/>',
            '0 0 50 50',
        );

        self::assertStringContainsString("[8 4] 2 d\n", $bytes);
        self::assertStringContainsString("\n2 w\n", $bytes);
    }

    #[Test]
    public function dasharray_is_inherited_from_a_group(): void
    {
        $bytes = self::render('<g stroke-dasharray="5,3"><polyline points="0,0 50,50 100,0" fill="none" stroke="#000"/></g>');

        self::assertStringContainsString("[5 3] 0 d\n", $bytes);
    }

    #[Test]
    public function the_element_overrides_its_group(): void
    {
        $bytes = self::render('<g stroke-dasharray="5 3"><line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="none"/></g>');

        self::assertStringNotContainsString(' d'."\n", $bytes);
    }

    #[Test]
    public function dasharray_from_a_style_attribute(): void
    {
        $bytes = self::render('<path d="M0 0 L100 100" fill="none" stroke="#000" style="stroke-dasharray: 6 2; stroke-dashoffset: 3"/>');

        self::assertStringContainsString("[6 2] 3 d\n", $bytes);
    }

    #[Test]
    public function dasharray_from_a_style_sheet(): void
    {
        $bytes = self::render('<style>.ideal { stroke-dasharray: 4 4; }</style><polyline class="ideal" points="0,0 100,100" fill="none" stroke="#000"/>');

        self::assertStringContainsString("[4 4] 0 d\n", $bytes);
    }

    #[Test]
    public function an_odd_count_is_repeated_once(): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="5 3 2"/>');

        self::assertStringContainsString("[5 3 2 5 3 2] 0 d\n", $bytes);
    }

    #[Test]
    public function a_single_length_is_on_and_off(): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="3"/>');

        self::assertStringContainsString("[3 3] 0 d\n", $bytes);
    }

    #[Test]
    #[DataProvider('solidValues')]
    public function none_and_invalid_values_stroke_solid(string $value): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="'.$value.'"/>');

        self::assertStringNotContainsString(' d'."\n", $bytes);
        self::assertStringContainsString("\nS\n", $bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function solidValues(): iterable
    {
        yield 'none' => ['none'];
        yield 'empty' => [''];
        yield 'negative' => ['4 -2'];
        yield 'all zero' => ['0 0'];
        yield 'not a length' => ['4 abc'];
        yield 'relative unit' => ['4 10%'];
    }

    #[Test]
    public function the_offset_becomes_the_phase(): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="4 4" stroke-dashoffset="2"/>');

        self::assertStringContainsString("[4 4] 2 d\n", $bytes);
    }

    #[Test]
    public function a_negative_offset_wraps_into_the_period(): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="4 4" stroke-dashoffset="-3"/>');

        self::assertStringContainsString("[4 4] 5 d\n", $bytes);
    }

    #[Test]
    public function dashes_apply_to_rect_circle_ellipse_and_polygon(): void
    {
        $bytes = self::render(
            '<rect x="1" y="1" width="20" height="20" fill="none" stroke="#000" stroke-dasharray="1 1"/>'
            .'<circle cx="50" cy="50" r="10" fill="none" stroke="#000" stroke-dasharray="2 2"/>'
            .'<ellipse cx="50" cy="80" rx="10" ry="5" fill="none" stroke="#000" stroke-dasharray="3 3"/>'
            .'<polygon points="80,0 100,20 80,20" fill="none" stroke="#000" stroke-dasharray="4 4"/>'
            .'<rect x="1" y="80" width="10" height="10" rx="2" fill="none" stroke="#000" stroke-dasharray="5 5"/>',
        );

        foreach (['1 1', '2 2', '3 3', '4 4', '5 5'] as $pattern) {
            self::assertStringContainsString('['.$pattern."] 0 d\n", $bytes);
        }
        self::assertStatesBalanced($bytes);
    }

    #[Test]
    public function a_shape_without_a_stroke_gets_no_pattern(): void
    {
        $bytes = self::render('<rect x="0" y="0" width="10" height="10" fill="#000" stroke-dasharray="4 4"/>');

        self::assertStringNotContainsString(' d'."\n", $bytes);
    }

    #[Test]
    public function dashes_apply_inside_a_transformed_group(): void
    {
        // scale(2) doubles the stroke width and the dashes alike.
        $bytes = self::render(
            '<g transform="scale(2)" stroke-dasharray="4 2" stroke-dashoffset="1">'
            .'<line x1="0" y1="10" x2="40" y2="10" stroke="#000"/>'
            .'<path d="M0 20 L40 20" fill="none" stroke="#000"/>'
            .'<g transform="translate(1 1)"><rect x="0" y="0" width="5" height="5" fill="none" stroke="#000"/></g>'
            .'</g>'
            .'<line x1="0" y1="90" x2="100" y2="90" stroke="#000"/>',
        );

        self::assertSame(3, substr_count($bytes, "[8 4] 2 d\n"));
        self::assertSame(3, substr_count($bytes, ' d'."\n"));
        self::assertStatesBalanced($bytes);
    }

    #[Test]
    public function a_transformed_shape_scales_its_own_dashes(): void
    {
        $bytes = self::render('<line transform="scale(3)" x1="0" y1="10" x2="30" y2="10" stroke="#000" stroke-dasharray="1 2"/>');

        self::assertStringContainsString("[3 6] 0 d\n", $bytes);
    }

    #[Test]
    public function absolute_units_are_converted_to_user_units(): void
    {
        $bytes = self::render('<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-dasharray="3pt, 4px"/>');

        self::assertStringContainsString("[4 4] 0 d\n", $bytes);
    }

    private static function assertStatesBalanced(string $bytes): void
    {
        self::assertSame(preg_match_all('@^q$@m', $bytes), preg_match_all('@^Q$@m', $bytes));
    }

    private static function render(string $body, string $viewBox = '0 0 100 100'): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="'.$viewBox.'">'.$body.'</svg>';
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 100, heightPt: 100)]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }
}
