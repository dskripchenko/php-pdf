<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Svg\SvgRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Circles and ellipses are four cubic Béziers: round at any size, and a
 * fraction of the content a 36-sided polygon took.
 *
 * Each SVG is drawn into a 100 x 100 pt box at the page origin, so SVG
 * (x, y) lands on PDF (x, 100 - y).
 */
final class SvgCirclesTest extends TestCase
{
    #[Test]
    public function a_circle_is_four_cubic_curves(): void
    {
        $stream = self::stream('<circle cx="50" cy="50" r="40" fill="#000"/>');

        self::assertStringContainsString("90 50 m\n", $stream);
        // The first quarter, to the bottom of the circle: control points 40 * 0.5523 out.
        self::assertStringContainsString("90 27.9086 72.0914 10 50 10 c\n", $stream);
        self::assertSame(4, substr_count($stream, " c\n"));
        self::assertSame(0, substr_count($stream, " l\n"));
        self::assertStringContainsString("h\nf\n", $stream);
    }

    #[Test]
    public function an_ellipse_uses_both_radii(): void
    {
        $stream = self::stream('<ellipse cx="50" cy="50" rx="40" ry="20" fill="#000"/>');

        self::assertStringContainsString("90 50 m\n90 38.9543 72.0914 30 50 30 c\n", $stream);
        self::assertSame(4, substr_count($stream, " c\n"));
    }

    #[Test]
    public function fill_and_stroke_are_one_path(): void
    {
        $stream = self::stream('<circle cx="50" cy="50" r="40" fill="#f00" stroke="#00f" stroke-width="2"/>');

        self::assertSame(4, substr_count($stream, " c\n"));
        self::assertStringContainsString("h\nB\n", $stream);
        self::assertStringContainsString('2 w', $stream);
    }

    #[Test]
    public function a_zero_radius_draws_nothing(): void
    {
        self::assertStringNotContainsString(' c', self::stream('<circle cx="50" cy="50" r="0" fill="#000"/><ellipse cx="5" cy="5" rx="3" ry="0" fill="#000"/>'));
    }

    #[Test]
    public function a_transformed_circle_stays_curved(): void
    {
        // translate(10 0): the start point moves from (90, 50) to (100, 50).
        $stream = self::stream('<g transform="translate(10 0)"><circle cx="50" cy="50" r="40" fill="#000"/></g>');

        self::assertStringContainsString("100 50 m\n", $stream);
        self::assertSame(4, substr_count($stream, " c\n"));
    }

    #[Test]
    public function a_scatter_plot_stays_small(): void
    {
        $dots = '';
        for ($i = 0; $i < 1000; $i++) {
            $dots .= sprintf('<circle cx="%.2f" cy="%.2f" r="0.4" fill="#4f46e5"/>', fmod($i * 7.31, 100), fmod($i * 3.17, 100));
        }
        $stream = self::stream($dots);

        // A 36-sided outline took about 600 bytes a dot.
        self::assertLessThan(250 * 1000, strlen($stream));
    }

    private static function stream(string $body): string
    {
        $page = PdfDocument::new()->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'.$body.'</svg>', $page, 0, 0, 100, 100);

        return $page->rawContentStream()->toString();
    }
}
