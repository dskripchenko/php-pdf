<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgPathCurvesTest extends TestCase
{
    #[Test]
    public function cubic_bezier_emits_c_operator(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 10 C 20 20 80 20 90 10" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // c operator (cubic Bezier).
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+c@', $bytes);
    }

    #[Test]
    public function smooth_cubic_s_command(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 50 C 30 20 70 20 90 50 S 130 80 170 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 200, heightPt: 100)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // S converts к cubic — 2 c operators expected (one для каждого segment).
        $count = preg_match_all('@\sc\n@', $bytes);
        self::assertGreaterThanOrEqual(2, $count);
    }

    #[Test]
    public function quadratic_bezier_converted_to_cubic(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 50 Q 50 10 90 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Quadratic → 1 cubic c operator.
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
    }

    #[Test]
    public function smooth_quadratic_t_command(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 50 Q 30 10 50 50 T 90 50" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 c operators (Q + T → both → cubic).
        $count = preg_match_all('@\sc\n@', $bytes);
        self::assertGreaterThanOrEqual(2, $count);
    }

    #[Test]
    public function horizontal_lineto_h_command(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 50 H 90" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // H converts к L — m + l + S.
        self::assertMatchesRegularExpression('@\sm\n@', $bytes);
        self::assertMatchesRegularExpression('@\sl\n@', $bytes);
    }

    #[Test]
    public function vertical_lineto_v_command(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 10 V 90" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\sl\n@', $bytes);
    }

    #[Test]
    public function relative_commands_lowercase(): void
    {
        // M start + relative l + relative c.
        $svg = '<svg width="100" height="100"><path d="M10 10 l 20 20 c 0 -10 10 -10 10 0" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // m + l + c операторы.
        self::assertMatchesRegularExpression('@\sm\n@', $bytes);
        self::assertMatchesRegularExpression('@\sl\n@', $bytes);
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
    }

    #[Test]
    public function closed_filled_path_with_curves(): void
    {
        // Heart-like shape: closed path с cubic curves + Z.
        $svg = '<svg width="100" height="100"><path d="M50 30 C 50 10 30 10 30 30 C 30 50 50 70 50 70 C 50 70 70 50 70 30 C 70 10 50 10 50 30 Z" fill="#ff0000"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Multiple c + h + f (closed fill).
        self::assertMatchesRegularExpression('@\sh\nf\n@', $bytes);
        $count = preg_match_all('@\sc\n@', $bytes);
        self::assertGreaterThanOrEqual(4, $count);
    }

    #[Test]
    public function fill_and_stroke_combination_uses_b_operator(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 10 L 90 10 L 90 90 Z" fill="#ff0000" stroke="#000000"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // B operator = fill + stroke.
        self::assertMatchesRegularExpression('@\sB\n@', $bytes);
    }

    #[Test]
    public function multiple_subpaths(): void
    {
        // Two disjoint subpaths in одной path.
        $svg = '<svg width="100" height="100"><path d="M10 10 L 30 10 L 30 30 Z M 60 60 L 80 60 L 80 80 Z" fill="#0000ff"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 m operators (start of каждой subpath) + multiple l + 2 h (close).
        $mCount = preg_match_all('@\sm\n@', $bytes);
        $hCount = preg_match_all('@\sh\n@', $bytes);
        self::assertSame(2, $mCount);
        self::assertSame(2, $hCount);
    }

    #[Test]
    public function arc_not_supported_silent_skip(): void
    {
        // A/a commands not implemented — should silently skip.
        $svg = '<svg width="100" height="100"><path d="M10 10 A 20 20 0 0 1 90 90" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Arc — no exception, possibly empty path.
        self::assertNotEmpty($bytes);
    }
}
