<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Svg\SvgRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a group passes to its children: gradients it can refer to, its
 * transform composed with theirs, and the inherited fill and stroke.
 *
 * Each SVG is drawn into a 100 x 100 pt box at the page origin, so a user
 * unit is a point and SVG (x, y) lands on PDF (x, 100 - y).
 */
final class SvgGroupsTest extends TestCase
{
    private const GRADIENT = '<defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>';

    #[Test]
    public function gradient_fills_a_rect_inside_a_group(): void
    {
        $stream = self::stream(self::GRADIENT.'<g><rect width="100" height="100" fill="url(#g)"/></g>');

        self::assertStringContainsString('/Pattern cs', $stream);
        self::assertStringNotContainsString('0 0 0 rg', $stream);
    }

    #[Test]
    public function gradient_fills_a_rect_in_nested_groups(): void
    {
        $stream = self::stream(self::GRADIENT.'<g><g><rect width="100" height="100" fill="url(#g)"/></g></g>');

        self::assertStringContainsString('/Pattern cs', $stream);
    }

    #[Test]
    public function shapes_inherit_fill_from_their_group(): void
    {
        foreach (['<rect width="10" height="10"/>', '<circle cx="5" cy="5" r="5"/>', '<path d="M0 0 H10 V10 Z"/>', '<polygon points="0,0 10,0 10,10"/>'] as $shape) {
            self::assertStringContainsString('1 0 0 rg', self::stream('<g fill="#f00">'.$shape.'</g>'), $shape);
        }
    }

    #[Test]
    public function transformed_shapes_inherit_fill_too(): void
    {
        foreach (['<rect width="10" height="10"/>', '<circle cx="5" cy="5" r="5"/>', '<path d="M0 0 H10 V10 Z"/>'] as $shape) {
            self::assertStringContainsString('1 0 0 rg', self::stream('<g fill="#f00" transform="translate(5 5)">'.$shape.'</g>'), $shape);
        }
    }

    #[Test]
    public function the_nearest_fill_wins(): void
    {
        $stream = self::stream('<g fill="#f00"><g fill="#0f0"><rect width="10" height="10" style="fill: #00f"/><rect y="20" width="10" height="10"/></g></g>');

        self::assertStringContainsString('0 0 1 rg', $stream);
        self::assertStringContainsString('0 1 0 rg', $stream);
        self::assertStringNotContainsString('1 0 0 rg', $stream);
    }

    #[Test]
    public function fill_none_on_a_group_leaves_only_the_stroke(): void
    {
        $stream = self::stream('<g fill="none" stroke="#00f" stroke-width="3"><path d="M0 0 H10 V10 Z"/></g>');

        self::assertStringContainsString('0 0 1 RG', $stream);
        self::assertStringContainsString('3 w', $stream);
        self::assertStringNotContainsString(' rg', $stream);
        self::assertMatchesRegularExpression('@\nS\n@', $stream);
    }

    #[Test]
    public function fill_opacity_is_inherited(): void
    {
        $page = PdfDocument::new()->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><g fill-opacity="0.5"><rect width="10" height="10"/></g></svg>', $page, 0, 0, 100, 100);

        self::assertMatchesRegularExpression('@/Gs\d+ gs@', $page->rawContentStream()->toString());
    }

    #[Test]
    public function nested_group_transforms_compose(): void
    {
        // translate(50 0) then translate(0 50): the square covers user (50..100, 50..100).
        $stream = self::stream('<g transform="translate(50 0)"><g transform="translate(0 50)"><rect width="50" height="50" fill="#000"/></g></g>');

        self::assertStringContainsString("50 50 m\n100 50 l\n100 0 l\n50 0 l\n", $stream);
    }

    #[Test]
    public function scale_then_translate_as_qr_generators_nest_them(): void
    {
        // scale(2) of translate(10 10): user (5, 5) lands on (30, 30), PDF (30, 70).
        $stream = self::stream('<g transform="scale(2)"><g transform="translate(10 10)"><path d="M0 0 H5 V5 H0 Z" fill="#000"/></g></g>');

        self::assertStringContainsString("20 80 m\n30 80 l\n30 70 l\n20 70 l\nh\n", $stream);
    }

    #[Test]
    public function a_transform_on_a_shape_in_a_transformed_group_applies(): void
    {
        $stream = self::stream('<g transform="translate(10 0)"><rect width="10" height="10" fill="#000" transform="translate(0 20)"/></g>');

        self::assertStringContainsString("10 80 m\n20 80 l\n20 70 l\n10 70 l\n", $stream);
    }

    #[Test]
    public function stroke_width_follows_the_composed_scale(): void
    {
        $stream = self::stream('<g transform="scale(2)"><g transform="scale(2)"><path d="M0 0 H5" stroke="#000" fill="none"/></g></g>');

        self::assertStringContainsString("4 w\n", $stream);
    }

    #[Test]
    public function each_render_resolves_its_own_groups(): void
    {
        // Back-to-back renders, the same shape in different groups: nothing carries over.
        self::assertStringContainsString('1 0 0 rg', self::stream('<g fill="#f00"><rect width="10" height="10"/></g>'));
        self::assertStringContainsString('0 0 1 rg', self::stream('<g fill="#00f"><rect width="10" height="10"/></g>'));
        $both = self::stream('<g fill="#f00"><rect width="10" height="10"/></g><g fill="#0f0"><rect width="10" height="10"/></g>');
        self::assertStringContainsString('1 0 0 rg', $both);
        self::assertStringContainsString('0 1 0 rg', $both);
    }

    private static function stream(string $body): string
    {
        $page = PdfDocument::new()->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'.$body.'</svg>', $page, 0, 0, 100, 100);

        return $page->rawContentStream()->toString();
    }
}
