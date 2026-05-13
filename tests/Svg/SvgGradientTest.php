<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgGradientTest extends TestCase
{
    #[Test]
    public function linear_gradient_emits_pattern(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g1" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g1)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // PDF Pattern + Shading + Function objects emitted.
        self::assertStringContainsString('/Type /Pattern', $bytes);
        self::assertStringContainsString('/PatternType 2', $bytes);
        self::assertStringContainsString('/ShadingType 2', $bytes);
        self::assertStringContainsString('/FunctionType 2', $bytes);
        // Pattern resource в Page Resources.
        self::assertMatchesRegularExpression('@/Pattern <<\s*/P1\s+\d+\s+0\s+R@', $bytes);
        // Pattern fill operator в content stream.
        self::assertStringContainsString('/Pattern cs', $bytes);
        self::assertStringContainsString('/P1 scn', $bytes);
    }

    #[Test]
    public function gradient_color_stops_emitted(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g1">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="100%" stop-color="#00ff00"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g1)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Function /C0 [1 0 0] + /C1 [0 1 0] (red → green).
        self::assertMatchesRegularExpression('@/C0 \[1\s+0\s+0\]@', $bytes);
        self::assertMatchesRegularExpression('@/C1 \[0\s+1\s+0\]@', $bytes);
    }

    #[Test]
    public function stop_style_attribute_parsed(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g1">
      <stop offset="0%" style="stop-color: #ff0000; stop-opacity: 1"/>
      <stop offset="100%" style="stop-color: #0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="50" height="50" fill="url(#g1)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Red → blue.
        self::assertMatchesRegularExpression('@/C0 \[1\s+0\s+0\]@', $bytes);
        self::assertMatchesRegularExpression('@/C1 \[0\s+0\s+1\]@', $bytes);
    }

    #[Test]
    public function unresolved_gradient_falls_back_к_color(): void
    {
        // url(#nonexistent) → no pattern; rect renders solid fill.
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="url(#missing)"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // No pattern emitted.
        self::assertStringNotContainsString('/Type /Pattern', $bytes);
        // Fill='url(#missing)' parsed как color "unknown" → falls к black.
        self::assertMatchesRegularExpression('@\b0\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function two_gradients_emit_two_patterns(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="a"><stop offset="0%" stop-color="#f00"/><stop offset="100%" stop-color="#0f0"/></linearGradient>
    <linearGradient id="b"><stop offset="0%" stop-color="#00f"/><stop offset="100%" stop-color="#ff0"/></linearGradient>
  </defs>
  <rect x="0" y="0" width="40" height="40" fill="url(#a)"/>
  <rect x="50" y="0" width="40" height="40" fill="url(#b)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertSame(2, substr_count($bytes, '/Type /Pattern'));
        self::assertStringContainsString('/P1 scn', $bytes);
        self::assertStringContainsString('/P2 scn', $bytes);
    }
}
