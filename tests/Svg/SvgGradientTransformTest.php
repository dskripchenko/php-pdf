<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgGradientTransformTest extends TestCase
{
    #[Test]
    public function rotate_gradient_transform_emits_pattern_matrix(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g" gradientTransform="rotate(45)">
      <stop offset="0%" stop-color="#ff0000"/>
      <stop offset="100%" stop-color="#0000ff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Pattern dict has /Matrix entry.
        self::assertMatchesRegularExpression('@/Pattern.*?/Matrix \[[\d\.\s\-]+\]@s', $bytes);
    }

    #[Test]
    public function translate_transform(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g" gradientTransform="translate(10, 20)">
      <stop offset="0%" stop-color="#000"/>
      <stop offset="100%" stop-color="#fff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // Matrix [1 0 0 1 10 20].
        self::assertMatchesRegularExpression('@/Matrix \[1\s+0\s+0\s+1\s+10\s+20\]@', $bytes);
    }

    #[Test]
    public function no_transform_no_matrix_entry(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g">
      <stop offset="0%" stop-color="#000"/>
      <stop offset="100%" stop-color="#fff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        // No /Matrix entry в pattern.
        self::assertDoesNotMatchRegularExpression('@/Pattern[^/]*/Matrix@', $bytes);
    }

    #[Test]
    public function radial_gradient_with_transform(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <radialGradient id="g" cx="50%" cy="50%" r="50%" gradientTransform="scale(1.5)">
      <stop offset="0%" stop-color="#fff"/>
      <stop offset="100%" stop-color="#000"/>
    </radialGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Matrix [1.5 0 0 1.5 0 0]', $bytes);
    }

    #[Test]
    public function composed_transforms(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <linearGradient id="g" gradientTransform="translate(10,0) rotate(45)">
      <stop offset="0%" stop-color="#000"/>
      <stop offset="100%" stop-color="#fff"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="100" height="100" fill="url(#g)"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Matrix', $bytes);
    }
}
