<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgRendererTest extends TestCase
{
    #[Test]
    public function rect_emits_fillrect_when_filled(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">
  <rect x="10" y="10" width="50" height="30" fill="#ff0000"/>
</svg>
SVG;
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 100, heightPt: 100)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Red fill: 1 0 0 rg.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        // Filled rectangle (re + f).
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+re@', $bytes);
    }

    #[Test]
    public function stroked_rect_emits_strokerect(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="100" height="100" fill="none" stroke="#000000" stroke-width="2"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // RG (stroke color) operator.
        self::assertStringContainsString(' RG', $bytes);
        // S operator (stroke without fill).
        self::assertStringContainsString("\nS\n", $bytes);
    }

    #[Test]
    public function line_emits_m_l_s(): void
    {
        $svg = '<svg width="100" height="100"><line x1="10" y1="10" x2="90" y2="90" stroke="#0000ff"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+m@', $bytes);
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+l@', $bytes);
        // Blue stroke.
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+RG@', $bytes);
    }

    #[Test]
    public function circle_emits_polygon_approximation(): void
    {
        $svg = '<svg width="100" height="100"><circle cx="50" cy="50" r="20" fill="#00ff00"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Polygon fill: m + l+ + h + f.
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
        // Green fill.
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function polygon_emits_filled_path(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <polygon points="10,10 90,10 50,90" fill="#ffff00"/>
</svg>
SVG;
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Yellow fill: 1 1 0 rg.
        self::assertMatchesRegularExpression('@1\s+1\s+0\s+rg@', $bytes);
        // Closed polygon path (h + f).
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
    }

    #[Test]
    public function polyline_strokes_without_fill(): void
    {
        $svg = '<svg><polyline points="0,0 10,20 20,10" stroke="#000" fill="none"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // S operator (stroke).
        self::assertStringContainsString("\nS\n", $bytes);
    }

    #[Test]
    public function path_with_moveto_lineto_close(): void
    {
        $svg = '<svg width="100" height="100"><path d="M10 10 L 90 10 L 90 90 Z" fill="#ff00ff"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Magenta fill.
        self::assertMatchesRegularExpression('@1\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function svg_with_named_color(): void
    {
        $svg = '<svg width="50" height="50"><rect x="0" y="0" width="50" height="50" fill="red"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function viewbox_scaling_applied(): void
    {
        // viewBox 0 0 10 10 — coords scaled 10× к фактическому widthPt=100.
        $svg = '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#000"/></svg>';
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 100, heightPt: 100)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Rect должен занимать весь 100×100 block. Width 100 в PDF coords.
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+100\s+100\s+re@', $bytes);
    }

    #[Test]
    public function empty_svg_does_not_throw(): void
    {
        $svg = '<svg width="100" height="100"></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function malformed_svg_silently_skipped(): void
    {
        $svg = '<svg width="100"><rect this is not valid xml';
        $doc = new Document(new Section([new SvgElement($svg)]));
        // Should not throw — invalid XML просто skipped.
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function empty_svg_xml_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SvgElement('');
    }

    #[Test]
    public function group_element_children_processed(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <g>
    <rect x="0" y="0" width="50" height="50" fill="red"/>
    <circle cx="75" cy="75" r="20" fill="blue"/>
  </g>
</svg>
SVG;
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Red rect (1 0 0 rg) + Blue circle (0 0 1 rg) — оба rendered.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }
}
