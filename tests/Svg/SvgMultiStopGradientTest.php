<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgMultiStopGradientTest extends TestCase
{
    #[Test]
    public function three_stop_gradient_emits_type3_stitching(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="50%" stop-color="#00ff00"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Type 3 stitching function.
        self::assertStringContainsString('/FunctionType 3', $bytes);
        // 2 sub Type 2 functions.
        self::assertSame(2, substr_count($bytes, '/FunctionType 2'));
        // Bounds at 0.5 (middle stop).
        self::assertMatchesRegularExpression('@/Bounds \[0\.5\]@', $bytes);
    }

    #[Test]
    public function four_stop_gradient(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="33%" stop-color="#ffff00"/>
      <stop offset="66%" stop-color="#00ff00"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // 3 sub functions, 2 bounds.
        self::assertSame(3, substr_count($bytes, '/FunctionType 2'));
        self::assertStringContainsString('/FunctionType 3', $bytes);
    }

    #[Test]
    public function two_stop_gradient_still_uses_type2_directly(): void
    {
        // Phase 82 regression — 2-stop should NOT use Type 3.
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/FunctionType 3', $bytes);
        self::assertSame(1, substr_count($bytes, '/FunctionType 2'));
    }

    #[Test]
    public function stitching_function_includes_encode_entries(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#000"/>
      <stop offset="50%" stop-color="#888"/>
      <stop offset="100%" stop-color="#fff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Encode array — для 2 sub funcs = 4 entries: [0 1 0 1].
        self::assertMatchesRegularExpression('@/Encode \[0\s+1\s+0\s+1\]@', $bytes);
    }
}
