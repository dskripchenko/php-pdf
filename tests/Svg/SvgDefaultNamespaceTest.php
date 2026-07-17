<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real-world SVGs declare xmlns="http://www.w3.org/2000/svg". SimpleXML's
 * xpath() cannot address the default namespace without a prefix, so every
 * //linearGradient, //style and //use lookup silently returned nothing —
 * a gradient-filled rect rendered opaque black. The renderer now strips
 * the default-namespace declaration before parsing.
 */
final class SvgDefaultNamespaceTest extends TestCase
{
    private const GRADIENT_SVG = <<<'SVG'
        <svg %s viewBox="0 0 100 50">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#4a90d9"/>
              <stop offset="1" stop-color="#153a5f"/>
            </linearGradient>
          </defs>
          <rect x="0" y="0" width="100" height="50" fill="url(#g)"/>
        </svg>
        SVG;

    private function render(string $svg): string
    {
        $doc = new Document(new Section([new SvgElement($svg, widthPt: 200, heightPt: 100)]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function namespaced_svg_gradient_emits_a_shading_pattern(): void
    {
        $bytes = $this->render(sprintf(self::GRADIENT_SVG, 'xmlns="http://www.w3.org/2000/svg"'));

        self::assertStringContainsString('/ShadingType 2', $bytes);
        self::assertStringContainsString('/Pattern', $bytes);
    }

    #[Test]
    public function namespaced_and_plain_svg_render_identically(): void
    {
        $namespaced = $this->render(sprintf(self::GRADIENT_SVG, 'xmlns="http://www.w3.org/2000/svg"'));
        $plain = $this->render(sprintf(self::GRADIENT_SVG, ''));

        self::assertSame($plain, $namespaced);
    }
}
