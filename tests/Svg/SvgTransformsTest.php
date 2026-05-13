<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgTransformsTest extends TestCase
{
    #[Test]
    public function translate_shifts_rect_position(): void
    {
        // Без transform — rect at (0, 0).
        $svg1 = '<svg width="100" height="100"><rect x="0" y="0" width="20" height="20" fill="#000"/></svg>';
        // С translate(50, 50) — rect at (50, 50).
        $svg2 = '<svg width="100" height="100"><rect x="0" y="0" width="20" height="20" fill="#000" transform="translate(50,50)"/></svg>';

        $b1 = (new Document(new Section([new SvgElement($svg1, widthPt: 100, heightPt: 100)])))->toBytes(new Engine(compressStreams: false));
        $b2 = (new Document(new Section([new SvgElement($svg2, widthPt: 100, heightPt: 100)])))->toBytes(new Engine(compressStreams: false));

        // Both render rect (fillPolygon — h + f from transformed path).
        // Output content streams должны отличаться.
        self::assertNotEquals(
            substr($b1, strpos($b1, 'stream'), 200),
            substr($b2, strpos($b2, 'stream'), 200),
        );
    }

    #[Test]
    public function scale_changes_dimensions(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" transform="scale(2)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 100, heightPt: 100)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Transformed rect → polygon (4 corners).
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
        // Red color preserved.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function rotate_emits_polygon_not_rect(): void
    {
        // Rotated rect → polygon (no longer axis-aligned).
        $svg = '<svg width="100" height="100"><rect x="40" y="40" width="20" height="20" fill="#0f0" transform="rotate(45)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Polygon emit (h + f), not re operator.
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function rotate_about_point(): void
    {
        // rotate(angle, cx, cy) syntax.
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="20" height="20" fill="#00f" transform="rotate(90,50,50)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function matrix_transform(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="20" height="20" fill="#000" transform="matrix(1 0 0 1 30 30)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Matrix = translate(30, 30); rect rendered как polygon (transformed).
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
    }

    #[Test]
    public function composed_transforms(): void
    {
        // translate then scale — applied left-to-right per spec.
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="10" height="10" fill="#000" transform="translate(20,20) scale(2)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
    }

    #[Test]
    public function transform_on_circle(): void
    {
        $svg = '<svg width="100" height="100"><circle cx="0" cy="0" r="10" fill="#000" transform="translate(50, 50)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Circle становится polygon (36 segments).
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
    }

    #[Test]
    public function transform_on_path(): void
    {
        $svg = '<svg width="100" height="100"><path d="M0 0 L 20 0 L 10 20 Z" fill="#000" transform="translate(40,40)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Path operations preserved + transformed.
        self::assertMatchesRegularExpression('@\sm\n@', $bytes);
        self::assertMatchesRegularExpression('@\sl\n@', $bytes);
    }

    #[Test]
    public function transform_on_text(): void
    {
        $svg = '<svg width="200" height="100"><text x="0" y="20" fill="#000" transform="translate(50,30)">Shifted</text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Shifted) Tj', $bytes);
    }

    #[Test]
    public function transform_on_polygon(): void
    {
        $svg = '<svg width="100" height="100"><polygon points="0,0 10,0 5,10" fill="#000" transform="translate(50,50)"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
    }
}
