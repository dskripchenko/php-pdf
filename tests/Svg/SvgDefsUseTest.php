<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgDefsUseTest extends TestCase
{
    #[Test]
    public function use_clones_defs_element(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100" xmlns:xlink="http://www.w3.org/1999/xlink">
  <defs>
    <rect id="square" width="20" height="20" fill="#ff0000"/>
  </defs>
  <use xlink:href="#square" x="10" y="10"/>
  <use xlink:href="#square" x="60" y="60"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Red fill emitted twice (two use references).
        $count = preg_match_all('@1\s+0\s+0\s+rg@', $bytes);
        self::assertSame(2, $count);
    }

    #[Test]
    public function defs_content_not_rendered_directly(): void
    {
        // Defs alone (no use) — content shouldn't render.
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <rect id="hidden" width="50" height="50" fill="#ff0000"/>
  </defs>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // No red fill rendered.
        self::assertDoesNotMatchRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function use_with_modern_href_attribute(): void
    {
        // SVG 2 syntax — `href` без xlink: prefix.
        $svg = <<<'SVG'
<svg width="100" height="100">
  <defs>
    <circle id="dot" cx="0" cy="0" r="10" fill="#00ff00"/>
  </defs>
  <use href="#dot"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function unresolved_use_skipped(): void
    {
        $svg = '<svg width="100" height="100"><use href="#nonexistent"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Не throws — пустой output OK.
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function style_inside_defs_applied_к_use(): void
    {
        // <style> at top + <defs> с element — class style should apply.
        $svg = <<<'SVG'
<svg width="100" height="100" xmlns:xlink="http://www.w3.org/1999/xlink">
  <style>.dot { fill: #0000ff; }</style>
  <defs>
    <circle id="d" class="dot" cx="10" cy="10" r="5"/>
  </defs>
  <use xlink:href="#d"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }
}
