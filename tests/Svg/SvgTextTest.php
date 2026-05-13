<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgTextTest extends TestCase
{
    #[Test]
    public function text_emits_tj_operator(): void
    {
        $svg = '<svg width="100" height="100"><text x="10" y="50" font-size="14" fill="#000000">Hello SVG</text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Hello SVG) Tj', $bytes);
    }

    #[Test]
    public function text_color_emitted(): void
    {
        $svg = '<svg width="100" height="100"><text x="0" y="20" fill="#ff0000">Red text</text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Red fill (1 0 0 rg) before text.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertStringContainsString('(Red text) Tj', $bytes);
    }

    #[Test]
    public function text_font_size_applied(): void
    {
        $svg = '<svg width="100" height="100"><text x="0" y="20" font-size="24">Big</text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Font size emitted в Tf operator: /F1 24 Tf (scaled by box width).
        self::assertMatchesRegularExpression('@/F1\s+\d+(?:\.\d+)?\s+Tf@', $bytes);
        self::assertStringContainsString('(Big) Tj', $bytes);
    }

    #[Test]
    public function text_with_no_fill_skipped(): void
    {
        $svg = '<svg width="100" height="100"><text x="0" y="20" fill="none">Hidden</text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('(Hidden) Tj', $bytes);
    }

    #[Test]
    public function empty_text_skipped(): void
    {
        $svg = '<svg width="100" height="100"><text x="0" y="20"></text></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertNotEmpty($bytes);
        // No empty parentheses Tj.
        self::assertStringNotContainsString('() Tj', $bytes);
    }

    #[Test]
    public function text_combined_with_shapes(): void
    {
        $svg = <<<'SVG'
<svg width="200" height="100">
  <rect x="0" y="0" width="200" height="100" fill="#cccccc"/>
  <text x="10" y="50" fill="#000">Annotation</text>
</svg>
SVG;
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Both shape fill + text emitted.
        self::assertStringContainsString('(Annotation) Tj', $bytes);
        // Gray rect: 0.8 0.8 0.8 rg.
        self::assertMatchesRegularExpression('@0\.8\s+0\.8\s+0\.8\s+rg@', $bytes);
    }

    #[Test]
    public function text_inside_group(): void
    {
        $svg = '<svg width="100" height="100"><g><text x="10" y="20" fill="#000">In group</text></g></svg>';
        $doc = new Document(new Section([new SvgElement($svg)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(In group) Tj', $bytes);
    }
}
