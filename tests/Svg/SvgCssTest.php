<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgCssTest extends TestCase
{
    #[Test]
    public function tag_selector_applies_fill(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>rect { fill: #ff0000; }</style>
  <rect x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Red fill: 1 0 0 rg.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function class_selector_applies(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>.blue { fill: #0000ff; }</style>
  <rect class="blue" x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function id_selector_applies(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>#unique { fill: #00ff00; }</style>
  <rect id="unique" x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function inline_attribute_overrides_css(): void
    {
        // CSS rect { fill: red } но inline fill="green" — inline wins.
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>rect { fill: #ff0000; }</style>
  <rect fill="#00ff00" x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Green wins.
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
        // No red emitted.
        self::assertDoesNotMatchRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function multiple_selectors_comma_separated(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>rect, circle { fill: #ff00ff; }</style>
  <rect x="0" y="0" width="30" height="30"/>
  <circle cx="60" cy="60" r="20"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Magenta fill appears (rect + circle).
        $count = preg_match_all('@1\s+0\s+1\s+rg@', $bytes);
        self::assertGreaterThanOrEqual(2, $count);
    }

    #[Test]
    public function multiple_declarations_in_rule(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>rect { fill: #000; stroke: #f00; stroke-width: 2; }</style>
  <rect x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        // Both fill (0 0 0 rg) и stroke (1 0 0 RG) applied.
        self::assertMatchesRegularExpression('@0\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+RG@', $bytes);
    }

    #[Test]
    public function comments_stripped(): void
    {
        $svg = <<<'SVG'
<svg width="100" height="100">
  <style>/* comment */ rect { fill: #f00; } /* another */</style>
  <rect x="0" y="0" width="50" height="50"/>
</svg>
SVG;
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
    }
}
