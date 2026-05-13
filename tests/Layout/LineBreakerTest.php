<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\LineBreaker;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineBreakerTest extends TestCase
{
    private PdfFont $font;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->font = new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function short_text_stays_on_one_line(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 500); // широкая column
        $lines = $b->wrap('Hello world');
        self::assertSame(['Hello world'], $lines);
    }

    #[Test]
    public function long_text_wraps_to_multiple_lines(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 100); // узкая column
        $text = 'The quick brown fox jumps over the lazy dog and runs into the forest.';
        $lines = $b->wrap($text);
        self::assertGreaterThan(2, count($lines));
        // Каждая line должна fit'ить в 100 pt.
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(100, $m->widthPt($line));
        }
    }

    #[Test]
    public function smaller_size_fits_more_words_per_line(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog and runs into the forest fast.';

        $m10 = new TextMeasurer($this->font, 10);
        $m20 = new TextMeasurer($this->font, 20);

        $b10 = new LineBreaker($m10, 200);
        $b20 = new LineBreaker($m20, 200);

        $lines10 = $b10->wrap($text);
        $lines20 = $b20->wrap($text);

        // 20pt текст должен использовать больше строк.
        self::assertGreaterThan(count($lines10), count($lines20));
    }

    #[Test]
    public function explicit_newline_forces_break(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 500);

        $lines = $b->wrap("First line\nSecond line\nThird");
        self::assertCount(3, $lines);
        self::assertSame('First line', $lines[0]);
        self::assertSame('Second line', $lines[1]);
        self::assertSame('Third', $lines[2]);
    }

    #[Test]
    public function very_long_word_breaks_character_wise(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 50);

        // 30-char "word" длиннее, чем 50pt при 12pt size.
        $lines = $b->wrap('abcdefghijklmnopqrstuvwxyz123456');
        self::assertGreaterThan(1, count($lines));
        // Re-joined should give back the original (без spaces).
        self::assertSame('abcdefghijklmnopqrstuvwxyz123456', implode('', $lines));
    }

    #[Test]
    public function empty_paragraph_yields_one_empty_line(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 200);
        self::assertSame([''], $b->wrap(''));
    }

    #[Test]
    public function cyrillic_wraps_correctly(): void
    {
        $m = new TextMeasurer($this->font, 12);
        $b = new LineBreaker($m, 100);
        $text = 'Съешь же ещё этих мягких французских булок, да выпей чаю.';
        $lines = $b->wrap($text);
        self::assertGreaterThan(2, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(100, $m->widthPt($line));
        }
        // Текст должен полностью присутствовать.
        self::assertSame($text, implode(' ', $lines));
    }
}
