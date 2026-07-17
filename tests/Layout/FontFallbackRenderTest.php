<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end fallbackFonts coverage. The draw-call batcher used to merge
 * equal-styled words BEFORE font resolution, so a mixed-script paragraph
 * rendered .notdef boxes for every non-Latin word — the fallback chain
 * never applied. Fonts are now resolved per word and participate in batch
 * compatibility, so Latin words draw with the default font and CJK words
 * with the fallback, even inside a single Run.
 */
final class FontFallbackRenderTest extends TestCase
{
    private function engine(): Engine
    {
        $lib = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        $droid = __DIR__.'/../../.cache/fonts/DroidSansFallback.ttf';
        if (! is_readable($lib) || ! is_readable($droid)) {
            self::markTestSkipped('Fonts not cached — run scripts/fetch-fonts.sh.');
        }

        return new Engine(
            defaultFont: new PdfFont(TtfFile::fromFile($lib)),
            fallbackFonts: [new PdfFont(TtfFile::fromFile($droid))],
            compressStreams: false,
        );
    }

    private function render(array $runs): string
    {
        return (new Document(new Section([new Paragraph($runs)])))
            ->toBytes($this->engine());
    }

    #[Test]
    public function mixed_script_runs_draw_with_two_fonts(): void
    {
        $bytes = $this->render([
            new Run('Latin start '),
            new Run('你好世界'),
            new Run(' latin end'),
        ]);

        preg_match_all('@/(F\d+) [\d.]+ Tf@', $bytes, $m);
        self::assertGreaterThanOrEqual(
            2,
            count(array_unique($m[1])),
            'Latin and CJK words must be drawn with different fonts',
        );
        // No .notdef glyphs (gid 0) in the CJK segment.
        self::assertStringNotContainsString('<00000000', $bytes);
    }

    #[Test]
    public function fallback_applies_per_word_inside_a_single_run(): void
    {
        $bytes = $this->render([new Run('hello 你好 world')]);

        preg_match_all('@/(F\d+) [\d.]+ Tf@', $bytes, $m);
        self::assertGreaterThanOrEqual(2, count(array_unique($m[1])));
    }

    #[Test]
    public function extracted_text_round_trips_through_poppler(): void
    {
        exec('pdftotext -v 2>/dev/null', $out, $code);
        if ($code !== 0 && $code !== 99) {
            self::markTestSkipped('pdftotext not available');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-fallback-');
        file_put_contents($tmp, $this->render([
            new Run('Latin start '),
            new Run('你好世界'),
            new Run(' latin end. Кириллица.'),
        ]));
        $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        unlink($tmp);

        self::assertStringContainsString('Latin start 你好世界 latin end. Кириллица.', $text);
    }

    #[Test]
    public function latin_only_output_is_unchanged_by_the_fallback_chain(): void
    {
        $latinOnly = $this->render([new Run('Pure latin text, no fallback needed.')]);

        preg_match_all('@/(F\d+) [\d.]+ Tf@', $latinOnly, $m);
        self::assertCount(1, array_unique($m[1]), 'A single font must be used');
    }
}
