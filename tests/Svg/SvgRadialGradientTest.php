<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgRadialGradientTest extends TestCase
{
    #[Test]
    public function radial_gradient_emits_shading_type_3(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <radialGradient id="g" cx="50%" cy="50%" r="50%">
      <stop offset="0%" stop-color="#ffffff"/>
      <stop offset="100%" stop-color="#000000"/>
    </radialGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ShadingType 3', $bytes);
        // 6-element coords for radial.
        self::assertMatchesRegularExpression('@/Coords \[[\d\.\s\-]+\s+[\d\.\s\-]+\s+0\s+[\d\.\s\-]+\s+[\d\.\s\-]+\s+[\d\.\s\-]+\]@', $bytes);
    }

    #[Test]
    public function radial_gradient_with_focal_point(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <radialGradient id="g" cx="50%" cy="50%" r="50%" fx="30%" fy="30%">
      <stop offset="0%" stop-color="#ffffff"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </radialGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ShadingType 3', $bytes);
        // C0 white, C1 blue.
        self::assertMatchesRegularExpression('@/C0 \[1\s+1\s+1\]@', $bytes);
        self::assertMatchesRegularExpression('@/C1 \[0\s+0\s+1\]@', $bytes);
    }

    #[Test]
    public function radial_multi_stop(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <radialGradient id="g" cx="50%" cy="50%" r="50%">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="50%" stop-color="#00ff00"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </radialGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Both radial shading + stitching function.
        self::assertStringContainsString('/ShadingType 3', $bytes);
        self::assertStringContainsString('/FunctionType 3', $bytes);
    }

    #[Test]
    public function linear_still_uses_shading_type_2(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#000"/>
      <stop offset="100%" stop-color="#fff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ShadingType 2', $bytes);
        self::assertStringNotContainsString('/ShadingType 3', $bytes);
    }
}
