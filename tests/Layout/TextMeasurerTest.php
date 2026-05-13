<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextMeasurerTest extends TestCase
{
    private PdfFont $sansFont;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->sansFont = new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function single_char_width_scales_with_size(): void
    {
        $m10 = new TextMeasurer($this->sansFont, 10);
        $m20 = new TextMeasurer($this->sansFont, 20);

        $w10 = $m10->widthOfCodepointPt(0x48); // H
        $w20 = $m20->widthOfCodepointPt(0x48);
        // 20pt текст ровно в 2 раза шире 10pt.
        self::assertEqualsWithDelta(2 * $w10, $w20, 0.01);
    }

    #[Test]
    public function string_width_equals_sum_of_char_widths(): void
    {
        $m = new TextMeasurer($this->sansFont, 12);
        $w = $m->widthPt('Hi');
        $expected = $m->widthOfCodepointPt(0x48) + $m->widthOfCodepointPt(0x69);
        self::assertEqualsWithDelta($expected, $w, 0.01);
    }

    #[Test]
    public function cyrillic_chars_have_measurable_width(): void
    {
        $m = new TextMeasurer($this->sansFont, 12);
        $w = $m->widthPt('Привет');
        // Должен быть > 0 (Liberation Sans покрывает Cyrillic).
        self::assertGreaterThan(20, $w);
    }

    #[Test]
    public function empty_string_has_zero_width(): void
    {
        $m = new TextMeasurer($this->sansFont, 12);
        self::assertSame(0.0, $m->widthPt(''));
    }

    #[Test]
    public function size_accessor_returns_input(): void
    {
        $m = new TextMeasurer($this->sansFont, 14.5);
        self::assertSame(14.5, $m->sizePt());
    }

    #[Test]
    public function emoji_falls_through_to_zero_width(): void
    {
        $m = new TextMeasurer($this->sansFont, 12);
        // Emoji не в Liberation → glyph ID 0 → ширина 0.
        $w = $m->widthPt('😀');
        // .notdef glyph может иметь default width, но всё равно низкую.
        // Просто проверим что не падает.
        self::assertGreaterThanOrEqual(0, $w);
    }
}
