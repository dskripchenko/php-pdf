<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Svg\SvgRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gradient fills on shapes other than a plain <rect>: the shape is filled
 * with a shading pattern whose matrix maps gradient space onto the page.
 *
 * Each SVG is drawn into a 100 x 100 pt box at the page origin, so user
 * space maps onto the page by [1 0 0 -1 0 100].
 */
final class SvgGradientShapesTest extends TestCase
{
    private const STOPS = '<stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/>';

    #[Test]
    public function path_is_filled_with_its_bounding_box_gradient(): void
    {
        // Bounding box (10, 10, 40, 20): gradient space [0..1] covers it.
        [$stream, $bytes] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs><path d="M10 10 H50 V30 H10 Z" fill="url(#g)"/>');

        self::assertStringContainsString("/Pattern cs\n/P1 scn\n10 90 m\n50 90 l\n50 70 l\n10 70 l\nh\nf\n", $stream);
        self::assertStringNotContainsString(' rg', $stream);
        self::assertStringContainsString('/ShadingType 2', $bytes);
        self::assertMatchesRegularExpression('@/Coords \[0 0 1 0\]@', $bytes);
        self::assertStringContainsString('/Matrix [40 0 0 -20 10 90]', $bytes);
    }

    #[Test]
    public function user_space_gradients_keep_their_coordinates(): void
    {
        [, $bytes] = self::render('<defs><linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="25" y2="25">'.self::STOPS.'</linearGradient></defs><path d="M0 0 H50 V50 H0 Z" fill="url(#g)"/>');

        self::assertMatchesRegularExpression('@/Coords \[0 0 25 25\]@', $bytes);
        self::assertStringContainsString('/Matrix [1 0 0 -1 0 100]', $bytes);
    }

    #[Test]
    public function the_gradient_follows_group_transforms(): void
    {
        // As BaconQrCode writes it: a scaled group, a translated group, a user-space gradient.
        [$stream, $bytes] = self::render('<g transform="scale(2)"><g transform="translate(2 2)"><defs><linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="25" y2="25">'.self::STOPS.'</linearGradient></defs>'
            .'<path fill-rule="evenodd" d="M0 0 H10 V10 H0 Z M2 2 H8 V8 H2 Z" fill="url(#g)"/></g></g>');

        self::assertStringContainsString("/P1 scn\n4 96 m\n", $stream);
        self::assertStringContainsString("h\nf*\n", $stream);
        self::assertStringContainsString('/Matrix [2 0 0 -2 4 96]', $bytes);
    }

    #[Test]
    public function gradient_transform_applies_inside_the_bounding_box(): void
    {
        [, $bytes] = self::render('<defs><linearGradient id="g" gradientTransform="translate(0.5 0)">'.self::STOPS.'</linearGradient></defs><path d="M10 10 H50 V30 H10 Z" fill="url(#g)"/>');

        self::assertStringContainsString('/Matrix [40 0 0 -20 30 90]', $bytes);
    }

    #[Test]
    public function radial_gradient_fills_a_circle(): void
    {
        [$stream, $bytes] = self::render('<defs><radialGradient id="r">'.self::STOPS.'</radialGradient></defs><circle cx="50" cy="50" r="40" fill="url(#r)"/>');

        self::assertStringContainsString('/P1 scn', $stream);
        self::assertStringNotContainsString(' rg', $stream);
        self::assertStringContainsString('/ShadingType 3', $bytes);
        self::assertMatchesRegularExpression('@/Coords \[0\.5 0\.5 0 0\.5 0\.5 0\.5\]@', $bytes);
        self::assertStringContainsString('/Matrix [80 0 0 -80 10 90]', $bytes);
    }

    #[Test]
    public function polygon_and_ellipse_take_gradients(): void
    {
        $defs = '<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs>';
        foreach (['<polygon points="0,0 50,0 50,50" fill="url(#g)"/>', '<ellipse cx="50" cy="50" rx="40" ry="20" fill="url(#g)"/>'] as $shape) {
            [$stream] = self::render($defs.$shape);
            self::assertStringContainsString('/P1 scn', $stream, $shape);
            self::assertStringNotContainsString(' rg', $stream, $shape);
        }
    }

    #[Test]
    public function transformed_rect_takes_a_gradient(): void
    {
        [$stream] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs><rect width="10" height="10" fill="url(#g)" transform="rotate(10)"/>');

        self::assertStringContainsString('/P1 scn', $stream);
        self::assertStringNotContainsString(' rg', $stream);
    }

    #[Test]
    public function a_gradient_fill_keeps_its_stroke(): void
    {
        [$stream] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs><path d="M10 10 H50 V30 H10 Z" fill="url(#g)" stroke="#0f0" stroke-width="2"/>');

        self::assertStringContainsString('/P1 scn', $stream);
        self::assertStringContainsString('0 1 0 RG', $stream);
        self::assertMatchesRegularExpression('@\nS\n@', $stream);
    }

    #[Test]
    public function an_unknown_gradient_falls_back_to_black_as_before(): void
    {
        [$stream] = self::render('<path d="M10 10 H50 V30 H10 Z" fill="url(#missing)"/>');

        self::assertStringContainsString('0 0 0 rg', $stream);
        self::assertStringNotContainsString('/Pattern', $stream);
    }

    /** @return array{0: string, 1: string} the page's content stream and the whole document */
    #[Test]
    public function the_bounding_box_hugs_a_curve_not_its_control_points(): void
    {
        // The curve's control points reach y = 10, the curve itself only y = 20:
        // the box is 80 x 30 from (10, 20), not 80 x 40 from (10, 10).
        [, $bytes] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs>'.'<path d="M10 50 C10 10 90 10 90 50 Z" fill="url(#g)"/>');

        self::assertStringContainsString('/Matrix [80 0 0 -30 10 80]', $bytes);
    }

    #[Test]
    public function a_quadratic_curve_and_an_arc_bound_tightly_too(): void
    {
        // Q's control point is at y = 10; the curve peaks at y = 30.
        [, $quad] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs>'.'<path d="M10 50 Q50 10 90 50 Z" fill="url(#g)"/>');
        self::assertStringContainsString('/Matrix [80 0 0 -20 10 70]', $quad);

        // A half circle of radius 40 above y = 50.
        [, $arc] = self::render('<defs><linearGradient id="g">'.self::STOPS.'</linearGradient></defs>'.'<path d="M10 50 A40 40 0 0 1 90 50 Z" fill="url(#g)"/>');
        self::assertStringContainsString('/Matrix [80 0 0 -40 10 90]', $arc);
    }

    #[Test]
    public function a_path_with_no_commands_registers_no_pattern(): void
    {
        // userSpaceOnUse: no bounding box to find empty first.
        [$stream, $bytes] = self::render('<defs><linearGradient id="g" gradientUnits="userSpaceOnUse">'.self::STOPS.'</linearGradient></defs>'
            .'<g transform="translate(1 1)"><path d="10 10" fill="url(#g)"/></g>');

        self::assertStringNotContainsString('/Pattern', $stream);
        self::assertStringNotContainsString('/PatternType', $bytes);
    }

    private static function render(string $body): array
    {
        $doc = PdfDocument::new();
        $page = $doc->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'.$body.'</svg>', $page, 0, 0, 100, 100);

        return [$page->rawContentStream()->toString(), $doc->toBytes()];
    }
}
