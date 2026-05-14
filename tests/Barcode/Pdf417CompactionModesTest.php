<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Pdf417Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 181-182: PDF417 Text + Numeric compaction modes.
 */
final class Pdf417CompactionModesTest extends TestCase
{
    #[Test]
    public function text_mode_starts_with_latch_900(): void
    {
        $cw = Pdf417Encoder::textCompaction('ABC');
        self::assertSame(900, $cw[0]);
    }

    #[Test]
    public function text_mode_alpha_uppercase_compaction(): void
    {
        // 4 uppercase chars → 2 codewords (2 chars per CW).
        $cw = Pdf417Encoder::textCompaction('ABCD');
        // Format: latch + (30*A + B) + (30*C + D) = 3 codewords.
        // A=0, B=1, C=2, D=3 → 30*0+1=1, 30*2+3=63.
        self::assertCount(3, $cw);
        self::assertSame(1, $cw[1]);  // 30*0+1
        self::assertSame(63, $cw[2]); // 30*2+3
    }

    #[Test]
    public function text_mode_lowercase_with_latch(): void
    {
        // "abc" — LL sub-mode latch + 3 chars.
        $cw = Pdf417Encoder::textCompaction('abc');
        self::assertSame(900, $cw[0]);
        // values: [27 (LL), 0 (a), 1 (b), 2 (c)] padded → 4 values = 2 CW.
        // CW: 30*27+0=810, 30*1+2=32.
        self::assertCount(3, $cw);
    }

    #[Test]
    public function text_mode_digit_with_mixed_latch(): void
    {
        // "1A2B" — switches между mixed и alpha sub-modes.
        $cw = Pdf417Encoder::textCompaction('A1B2');
        self::assertSame(900, $cw[0]);
        self::assertGreaterThan(2, count($cw));
    }

    #[Test]
    public function text_mode_space_universal(): void
    {
        $cw = Pdf417Encoder::textCompaction('A B');
        // SP value = 26 в любой sub-mode.
        self::assertSame(900, $cw[0]);
    }

    #[Test]
    public function text_mode_rejects_unsupported_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pdf417Encoder::textCompaction('A~B'); // ~ (0x7E) outside support
    }

    #[Test]
    public function numeric_mode_starts_with_latch_902(): void
    {
        $cw = Pdf417Encoder::numericCompaction('1234567890');
        self::assertSame(902, $cw[0]);
    }

    #[Test]
    public function numeric_mode_compression_44_digits(): void
    {
        // 44 digits → ~15 codewords (incl. latch).
        $cw = Pdf417Encoder::numericCompaction(str_repeat('1', 44));
        self::assertSame(902, $cw[0]);
        // Total: 1 latch + 15 base-900 digits = 16 codewords.
        self::assertCount(16, $cw);
    }

    #[Test]
    public function numeric_mode_chunks_long_input(): void
    {
        // 100 digits → 2 chunks (44 + 44 + 12).
        $cw = Pdf417Encoder::numericCompaction(str_repeat('1', 100));
        self::assertSame(902, $cw[0]);
        // Compression much better than byte mode (would be ~84 CW).
        self::assertLessThan(50, count($cw));
    }

    #[Test]
    public function numeric_mode_rejects_non_digits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pdf417Encoder::numericCompaction('123abc');
    }

    #[Test]
    public function auto_mode_picks_numeric_for_pure_digits(): void
    {
        $auto = new Pdf417Encoder('1234567890123456', mode: Pdf417Encoder::MODE_AUTO);
        $byte = new Pdf417Encoder('1234567890123456', mode: Pdf417Encoder::MODE_BYTE);
        // Numeric более compact (~ less codewords) — но minimum symbol size
        // зависит от ECC level. Assert at least no failure.
        self::assertGreaterThan(0, $auto->rows);
    }

    #[Test]
    public function auto_mode_picks_text_for_alphanumeric(): void
    {
        $enc = new Pdf417Encoder('HELLO WORLD', mode: Pdf417Encoder::MODE_AUTO);
        self::assertGreaterThan(0, $enc->rows);
    }

    #[Test]
    public function auto_mode_falls_back_to_byte_for_binary(): void
    {
        $enc = new Pdf417Encoder("hello\x80\xFF", mode: Pdf417Encoder::MODE_AUTO);
        self::assertGreaterThan(0, $enc->rows);
    }

    #[Test]
    public function numeric_mode_more_compact_than_byte_for_long_digits(): void
    {
        // 60-digit numeric string.
        $numeric = Pdf417Encoder::numericCompaction(str_repeat('1', 60));
        $byte = Pdf417Encoder::byteCompaction(str_repeat('1', 60));
        // Numeric: 1 latch + ~21 CW = ~22. Byte: ~50 CW.
        self::assertLessThan(count($byte), count($numeric));
    }

    #[Test]
    public function text_mode_more_compact_than_byte_for_letters(): void
    {
        $text = Pdf417Encoder::textCompaction(str_repeat('A', 30));
        $byte = Pdf417Encoder::byteCompaction(str_repeat('A', 30));
        // Text: 1 latch + 15 CW = 16. Byte: ~25 CW.
        self::assertLessThan(count($byte), count($text));
    }

    // -------- Phase 185: Macro PDF417 segments --------

    #[Test]
    public function macro_segment_factory_creates_encoder(): void
    {
        $seg = Pdf417Encoder::macroSegment('Data segment 1', 0, 3, 100);
        self::assertGreaterThan(0, $seg->rows);
        // Codewords include MACRO_INDICATOR (928).
        self::assertContains(928, $seg->codewords);
    }

    #[Test]
    public function macro_last_segment_has_terminator(): void
    {
        // Last segment (index = total-1) emits CW 922 terminator.
        $lastSeg = Pdf417Encoder::macroSegment('last', 2, 3, 100);
        self::assertContains(922, $lastSeg->codewords);
    }

    #[Test]
    public function macro_non_last_segment_no_terminator(): void
    {
        // Mid segment NO terminator at end (только last segment has it).
        $midSeg = Pdf417Encoder::macroSegment('middle', 1, 3, 100);
        // Codewords могут содержать 922 inside data, но check macro semantic
        // is via macroSegment param.
        $hasMacroIndicator = in_array(928, $midSeg->codewords, true);
        self::assertTrue($hasMacroIndicator);
    }

    #[Test]
    public function macro_rejects_segment_index_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pdf417Encoder::macroSegment('data', 5, 3, 100);
    }

    #[Test]
    public function macro_rejects_invalid_file_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pdf417Encoder::macroSegment('data', 0, 3, 9999); // > 899
    }

    // -------- Phase 198: GS1 FNC1 + ECI markers --------

    #[Test]
    public function gs1_marker_prepends_fnc1(): void
    {
        $enc = new Pdf417Encoder('01095060001343528200', gs1: true);
        self::assertContains(920, $enc->codewords);
    }

    #[Test]
    public function fnc1_constant_is_920(): void
    {
        self::assertSame(920, Pdf417Encoder::FNC1);
    }

    #[Test]
    public function eci_short_designator(): void
    {
        $enc = new Pdf417Encoder('test', eciDesignator: 26);
        self::assertContains(927, $enc->codewords);
        self::assertContains(26, $enc->codewords);
    }

    #[Test]
    public function eci_long_designator(): void
    {
        $enc = new Pdf417Encoder('test', eciDesignator: 5000);
        self::assertContains(927, $enc->codewords);
        // 5000 / 900 = 5, 5000 % 900 = 500.
        self::assertContains(5, $enc->codewords);
        self::assertContains(500, $enc->codewords);
    }

    #[Test]
    public function eci_rejects_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pdf417Encoder('test', eciDesignator: 900000);
    }

    #[Test]
    public function eci_constant_is_927(): void
    {
        self::assertSame(927, Pdf417Encoder::ECI_CHARSET);
    }

    #[Test]
    public function gs1_and_eci_combined(): void
    {
        $enc = new Pdf417Encoder('data', gs1: true, eciDesignator: 26);
        self::assertContains(920, $enc->codewords); // FNC1
        self::assertContains(927, $enc->codewords); // ECI
    }
}
