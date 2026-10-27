<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Svg\SvgRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dense charts and maps carry paths with hundreds of thousands of points.
 * The path data is parsed and written as it is read, so memory follows the
 * size of the output rather than a PHP array per command.
 */
final class SvgLargePathTest extends TestCase
{
    #[Test]
    public function a_long_path_draws_within_a_small_memory_budget(): void
    {
        $segments = 200_000;
        $svg = '<svg viewBox="0 0 100 100"><path fill="none" stroke="#000" d="M0 0'.str_repeat(' L1 1', $segments).'"/></svg>';
        $page = PdfDocument::new()->addPage(customDimensionsPt: [100, 100]);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        SvgRenderer::render($svg, $page, 0, 0, 100, 100);
        $peak = memory_get_peak_usage() - $before;

        $stream = $page->rawContentStream()->toString();
        self::assertSame($segments, substr_count($stream, " l\n"));
        // The content stream alone is about 1.2 MB; a PHP array per command took about 100 MB.
        self::assertLessThan(16 * 1024 * 1024, $peak, sprintf('peak %.1f MB', $peak / 1048576));
    }

    #[Test]
    public function emitPath_takes_any_iterable(): void
    {
        $commands = (static function (): \Generator {
            yield ['M', 0.0, 0.0];
            yield ['L', 10.0, 0.0];
            yield 'Z';
        })();

        self::assertStringContainsString("0 0 m\n10 0 l\nh\nf\n", (new ContentStream)->emitPath($commands)->toString());
        self::assertTrue((new ContentStream)->emitPath((static fn (): \Generator => yield from [])())->isEmpty());
    }

    #[Test]
    public function a_gradient_fill_still_sees_the_whole_path(): void
    {
        $doc = PdfDocument::new();
        $page = $doc->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg viewBox="0 0 100 100"><defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>'
            .'<path d="M10 10 H50 V30 H10 Z" fill="url(#g)" stroke="#0f0"/></svg>', $page, 0, 0, 100, 100);

        $stream = $page->rawContentStream()->toString();
        self::assertSame(2, substr_count($stream, "10 90 m\n50 90 l\n50 70 l\n10 70 l\nh\n"));
        self::assertStringContainsString('/Matrix [40 0 0 -20 10 90]', $doc->toBytes());
    }
}
