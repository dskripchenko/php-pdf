<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgArcTest extends TestCase
{
    #[Test]
    public function arc_emits_cubic_beziers(): void
    {
        // Quarter circle от (50, 50) до (100, 100) — large-arc=0, sweep=1.
        $svg = '<svg width="100" height="100"><path d="M50 50 A 50 50 0 0 1 100 100" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // ≥1 cubic c operator (≤90° arc = 1 segment).
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
        // m + S finish.
        self::assertMatchesRegularExpression('@\sm\n@', $bytes);
        self::assertStringContainsString("\nS\n", $bytes);
    }

    #[Test]
    public function full_arc_splits_into_multiple_cubics(): void
    {
        // Large arc (>90°) → multiple segments.
        $svg = '<svg width="100" height="100"><path d="M50 0 A 50 50 0 1 1 0 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // >180° arc → >= 3 cubic segments (split by 90°).
        $cubicCount = preg_match_all('@\sc\n@', $bytes);
        self::assertGreaterThanOrEqual(3, $cubicCount);
    }

    #[Test]
    public function arc_filled_with_closepath(): void
    {
        // Pie slice = filled arc + Z.
        $svg = '<svg width="100" height="100"><path d="M50 50 L 90 50 A 40 40 0 0 1 50 90 Z" fill="#ff0000"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Cubic (arc) + h (close) + f (fill).
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
        self::assertMatchesRegularExpression('@\sh\nf\n@', $bytes);
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function arc_with_rotation(): void
    {
        // Arc с x-axis rotation 45°.
        $svg = '<svg width="100" height="100"><path d="M20 50 A 30 15 45 0 1 80 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Should emit cubic curves.
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
    }

    #[Test]
    public function arc_with_zero_radii_falls_back(): void
    {
        // rx=0 OR ry=0 — per spec treat as line.
        $svg = '<svg width="100" height="100"><path d="M0 0 A 0 50 0 0 1 50 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        // Should not throw, may emit minimal path.
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function arc_relative_lowercase_a(): void
    {
        $svg = '<svg width="100" height="100"><path d="M50 50 a 30 30 0 0 1 30 30" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
    }

    #[Test]
    public function large_arc_flag_changes_content_stream(): void
    {
        // Same endpoints + radii, different large-arc-flag → different path.
        // Even если segment count same, output bytes должны отличаться.
        $svgSmall = '<svg width="100" height="100"><path d="M30 50 A 25 25 0 0 1 70 50" stroke="#000" fill="none"/></svg>';
        $svgLarge = '<svg width="100" height="100"><path d="M30 50 A 25 25 0 1 1 70 50" stroke="#000" fill="none"/></svg>';

        $b1 = (new Document(new Section([new SvgElement($svgSmall)])))->toBytes(new Engine(compressStreams: false));
        $b2 = (new Document(new Section([new SvgElement($svgLarge)])))->toBytes(new Engine(compressStreams: false));

        // Extract content streams.
        preg_match('@stream\n(.*?)\nendstream@s', $b1, $m1);
        preg_match('@stream\n(.*?)\nendstream@s', $b2, $m2);
        self::assertNotSame($m1[1] ?? '', $m2[1] ?? '');
    }
}
