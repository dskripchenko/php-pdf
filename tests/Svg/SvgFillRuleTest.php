<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `fill-rule="evenodd"` fills with f* (or B* with a stroke), so a hole
 * drawn the same way round as its outline stays empty: a donut, the letter
 * O, a five-point star. Without it the nonzero rule (f, B) applies, as SVG
 * defines.
 */
final class SvgFillRuleTest extends TestCase
{
    /** Two clockwise circles in one path: a donut under even-odd. */
    private const DONUT = 'M50 10 A40 40 0 1 1 49.99 10 Z M50 30 A20 20 0 1 1 49.99 30 Z';

    #[Test]
    public function path_without_fill_rule_fills_nonzero(): void
    {
        $bytes = self::render('<path d="'.self::DONUT.'" fill="#000"/>');

        self::assertMatchesRegularExpression('@\nf\n@', $bytes);
        self::assertStringNotContainsString("f*\n", $bytes);
    }

    #[Test]
    public function path_with_fill_rule_evenodd_fills_with_f_star(): void
    {
        $bytes = self::render('<path d="'.self::DONUT.'" fill="#000" fill-rule="evenodd"/>');

        self::assertStringContainsString("h\nf*\n", $bytes);
    }

    #[Test]
    public function path_with_fill_rule_nonzero_fills_with_f(): void
    {
        $bytes = self::render('<path d="'.self::DONUT.'" fill="#000" fill-rule="nonzero"/>');

        self::assertStringNotContainsString("f*\n", $bytes);
    }

    #[Test]
    public function evenodd_path_with_a_stroke_uses_B_star(): void
    {
        $bytes = self::render('<path d="'.self::DONUT.'" fill="#f00" stroke="#00f" fill-rule="evenodd"/>');

        self::assertStringContainsString("h\nB*\n", $bytes);
    }

    #[Test]
    public function fill_rule_is_read_from_inline_style(): void
    {
        $bytes = self::render('<path d="'.self::DONUT.'" fill="#000" style="fill-rule: evenodd"/>');

        self::assertStringContainsString("f*\n", $bytes);
    }

    #[Test]
    public function fill_rule_is_inherited_from_a_group(): void
    {
        $bytes = self::render('<g fill-rule="evenodd"><path d="'.self::DONUT.'" fill="#000"/></g>');

        self::assertStringContainsString("f*\n", $bytes);
    }

    #[Test]
    public function the_element_overrides_its_group(): void
    {
        $bytes = self::render('<g fill-rule="evenodd"><path d="'.self::DONUT.'" fill="#000" fill-rule="nonzero"/></g>');

        self::assertStringNotContainsString("f*\n", $bytes);
    }

    #[Test]
    public function fill_rule_from_a_style_sheet_class(): void
    {
        $bytes = self::render('<style>.hole { fill-rule: evenodd; }</style><path class="hole" d="'.self::DONUT.'" fill="#000"/>');

        self::assertStringContainsString("f*\n", $bytes);
    }

    #[Test]
    public function fill_rule_applies_under_a_group_transform(): void
    {
        $bytes = self::render('<g transform="translate(5 5)" fill-rule="evenodd"><path d="'.self::DONUT.'" fill="#000"/></g>');

        self::assertStringContainsString("f*\n", $bytes);
    }

    #[Test]
    public function evenodd_star_polygon_fills_with_f_star(): void
    {
        $bytes = self::render('<polygon points="50,5 79,95 2,40 98,40 21,95" fill="#000" fill-rule="evenodd"/>');

        self::assertStringContainsString("h\nf*\n", $bytes);
    }

    #[Test]
    public function star_polygon_without_fill_rule_fills_nonzero(): void
    {
        $bytes = self::render('<polygon points="50,5 79,95 2,40 98,40 21,95" fill="#000"/>');

        self::assertStringContainsString("h\nf\n", $bytes);
        self::assertStringNotContainsString("f*\n", $bytes);
    }

    #[Test]
    public function evenodd_polygon_under_a_group_transform(): void
    {
        $bytes = self::render('<g transform="rotate(10 50 50)"><polygon points="50,5 79,95 2,40 98,40 21,95" fill="#000" fill-rule="evenodd"/></g>');

        self::assertStringContainsString("h\nf*\n", $bytes);
    }

    private static function render(string $body): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'.$body.'</svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }
}
