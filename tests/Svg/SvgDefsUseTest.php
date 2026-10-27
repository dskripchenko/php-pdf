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
        // The SVG 2 syntax — `href` without the xlink: prefix.
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
        // It does not throw — an empty output is fine.
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function style_inside_defs_applied_к_use(): void
    {
        // A <style> at the top plus <defs> with an element — the class style should apply.
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

    #[Test]
    public function use_copies_stop_at_the_limit(): void
    {
        // Each group holds ten copies of the one before: 10^5 rects in all.
        $defs = '<g id="l0"><rect width="1" height="1" fill="#f00"/></g>';
        for ($i = 1; $i <= 5; $i++) {
            $defs .= sprintf('<g id="l%d">%s</g>', $i, str_repeat(sprintf('<use href="#l%d"/>', $i - 1), 10));
        }
        $page = \Dskripchenko\PhpPdf\Pdf\Document::new()->addPage(customDimensionsPt: [100, 100]);
        \Dskripchenko\PhpPdf\Svg\SvgRenderer::render('<svg viewBox="0 0 100 100"><defs>'.$defs.'</defs><use href="#l5"/></svg>', $page, 0, 0, 100, 100);

        $rects = substr_count($page->rawContentStream()->toString(), "1 0 0 rg\n");
        self::assertLessThanOrEqual(\Dskripchenko\PhpPdf\Svg\SvgRenderer::MAX_USE_COPIES, $rects);
    }

    #[Test]
    public function a_group_that_uses_itself_stays_within_the_limit(): void
    {
        // Each resolved <use> copies the group with the copies made so far: 2^n without the cap.
        $page = \Dskripchenko\PhpPdf\Pdf\Document::new()->addPage(customDimensionsPt: [100, 100]);
        \Dskripchenko\PhpPdf\Svg\SvgRenderer::render('<svg viewBox="0 0 100 100"><g id="a"><rect width="1" height="1" fill="#f00"/>'.str_repeat('<use href="#a"/>', 40).'</g></svg>', $page, 0, 0, 100, 100);

        $rects = substr_count($page->rawContentStream()->toString(), "1 0 0 rg\n");
        self::assertGreaterThan(0, $rects);
        self::assertLessThanOrEqual(\Dskripchenko\PhpPdf\Svg\SvgRenderer::MAX_USE_COPIES, $rects);
    }

    #[Test]
    public function a_small_use_after_one_over_the_limit_is_still_drawn(): void
    {
        $big = '<g id="big">'.str_repeat('<rect width="1" height="1"/>', \Dskripchenko\PhpPdf\Svg\SvgRenderer::MAX_USE_COPIES).'</g>';
        $page = \Dskripchenko\PhpPdf\Pdf\Document::new()->addPage(customDimensionsPt: [100, 100]);
        \Dskripchenko\PhpPdf\Svg\SvgRenderer::render('<svg viewBox="0 0 100 100"><defs>'.$big.'<rect id="dot" width="1" height="1" fill="#f00"/></defs><use href="#big"/><use href="#dot"/></svg>', $page, 0, 0, 100, 100);

        self::assertSame(1, substr_count($page->rawContentStream()->toString(), "1 0 0 rg\n"));
    }

    #[Test]
    public function many_uses_of_a_group_over_the_limit_are_refused_at_once(): void
    {
        // Each <use> of the oversized group is refused; counting its
        // elements again for every one of them took seconds.
        $big = '<g id="big">'.str_repeat('<a/>', \Dskripchenko\PhpPdf\Svg\SvgRenderer::MAX_USE_COPIES + 10_000).'</g>';
        $svg = '<svg viewBox="0 0 100 100"><defs>'.$big.'<rect id="dot" width="1" height="1" fill="#f00"/></defs>'
            .str_repeat('<use href="#big"/>', 20_000).'<use href="#dot"/></svg>';
        $page = \Dskripchenko\PhpPdf\Pdf\Document::new()->addPage(customDimensionsPt: [100, 100]);

        $start = microtime(true);
        \Dskripchenko\PhpPdf\Svg\SvgRenderer::render($svg, $page, 0, 0, 100, 100);
        $elapsed = microtime(true) - $start;

        self::assertSame(1, substr_count($page->rawContentStream()->toString(), "1 0 0 rg\n"));
        self::assertLessThan(1.0, $elapsed, sprintf('%.2fs', $elapsed));
    }
}
