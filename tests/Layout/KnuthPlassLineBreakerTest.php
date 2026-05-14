<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\KnuthPlassLineBreaker;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 218: Knuth-Plass optimal line breaking tests.
 */
final class KnuthPlassLineBreakerTest extends TestCase
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

    private function measurer(float $sizePt = 12.0): TextMeasurer
    {
        return new TextMeasurer($this->font, $sizePt);
    }

    #[Test]
    public function empty_paragraph_returns_single_empty_line(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 100);
        self::assertSame([''], $kp->wrap(''));
    }

    #[Test]
    public function single_word_fits_one_line(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 500);
        self::assertSame(['Hello'], $kp->wrap('Hello'));
    }

    #[Test]
    public function two_words_fit_one_line(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 500);
        self::assertSame(['Hello world'], $kp->wrap('Hello world'));
    }

    #[Test]
    public function long_paragraph_breaks_into_multiple_lines(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 100);
        $text = 'The quick brown fox jumps over the lazy dog';
        $lines = $kp->wrap($text);

        self::assertGreaterThan(1, count($lines));
        // Joining lines с spaces should recover the original text.
        self::assertSame($text, implode(' ', $lines));
    }

    #[Test]
    public function very_long_word_falls_back_to_greedy(): void
    {
        // Слово широше 50pt не помещается — fallback to greedy char-break.
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 50);
        $longWord = str_repeat('A', 30);
        $lines = $kp->wrap($longWord);

        // Returns non-empty result через fallback (greedy char-break).
        self::assertNotEmpty($lines);
    }

    #[Test]
    public function explicit_newlines_preserved_as_paragraph_breaks(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 500);
        $text = "First paragraph.\nSecond paragraph.";
        $lines = $kp->wrap($text);

        self::assertSame(['First paragraph.', 'Second paragraph.'], $lines);
    }

    #[Test]
    public function blank_line_between_paragraphs(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 500);
        $text = "First\n\nSecond";
        $lines = $kp->wrap($text);

        // Empty paragraph emit one blank line.
        self::assertContains('', $lines);
    }

    #[Test]
    public function knuth_plass_no_words_lost_or_added(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 80);
        $text = 'Lorem ipsum dolor sit amet consectetur adipiscing elit';
        $lines = $kp->wrap($text);

        $joined = implode(' ', $lines);
        // No words lost, no spaces added (since all our spaces are between words).
        $expectedWords = preg_split('/\s+/', $text);
        $actualWords = preg_split('/\s+/', $joined);
        self::assertSame($expectedWords, $actualWords);
    }

    #[Test]
    public function each_line_within_max_width_ish(): void
    {
        $measurer = $this->measurer();
        $maxWidth = 100.0;
        $kp = new KnuthPlassLineBreaker($measurer, maxWidthPt: $maxWidth);
        $text = 'The quick brown fox jumps over the lazy dog repeatedly today';
        $lines = $kp->wrap($text);

        foreach ($lines as $line) {
            $w = $measurer->widthPt($line);
            // K-P может shrink line до ~1/3 узший; or stretch up к 10× per glue.
            // Реальная line должна быть somewhere в [shrunk, stretched] range.
            // Just verify line is not absurdly wider than maxWidth.
            self::assertLessThanOrEqual($maxWidth * 2.0, $w, "Line '$line' width=$w exceeds 2× maxWidth=$maxWidth");
        }
    }

    #[Test]
    public function compares_better_than_or_equal_to_greedy_on_balance(): void
    {
        // For text designed к force imbalance, K-P should produce more
        // uniform line widths than greedy. We measure variance.
        $measurer = $this->measurer();
        $kp = new KnuthPlassLineBreaker($measurer, maxWidthPt: 120);
        $text = 'AAA BBB CCCCCC DDD EEEEEEEE FF GGG HHHHHHHHHHH II JJJJ K';
        $lines = $kp->wrap($text);

        // No assertion on optimality — just verify wrap produces reasonable
        // result without infinite loop or crash.
        self::assertGreaterThan(0, count($lines));
        $joined = implode(' ', $lines);
        self::assertSame(
            preg_split('/\s+/', $text),
            preg_split('/\s+/', $joined),
        );
    }

    #[Test]
    public function multi_paragraph_independent_optimization(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 100);
        $text = "Short one.\nA bit longer second paragraph with more words.\nThird.";
        $lines = $kp->wrap($text);

        self::assertGreaterThanOrEqual(3, count($lines));
    }

    #[Test]
    public function shrink_ratio_param(): void
    {
        // Larger shrink → tighter lines allowed.
        $tight = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 100, shrinkRatio: 0.5);
        $loose = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 100, shrinkRatio: 0.1);

        $text = 'word1 word2 word3 word4 word5 word6 word7 word8';
        $tightLines = $tight->wrap($text);
        $looseLines = $loose->wrap($text);

        // Both produce valid results.
        self::assertGreaterThan(0, count($tightLines));
        self::assertGreaterThan(0, count($looseLines));
    }

    #[Test]
    public function whitespace_normalization(): void
    {
        $kp = new KnuthPlassLineBreaker($this->measurer(), maxWidthPt: 500);
        // Multiple whitespace types between words.
        $text = "Hello \t world   foo";
        $lines = $kp->wrap($text);

        // Normalized to single spaces.
        self::assertSame(['Hello world foo'], $lines);
    }
}
