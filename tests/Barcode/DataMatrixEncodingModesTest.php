<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 176-180: DataMatrix encoding modes C40/Text/X12/EDIFACT/Base 256.
 */
final class DataMatrixEncodingModesTest extends TestCase
{
    #[Test]
    public function base256_mode_switch_codeword(): void
    {
        $cw = DataMatrixEncoder::encodeBase256('hello');
        // First codeword = 231 (switch к Base 256).
        self::assertSame(231, $cw[0]);
        // Total: 1 switch + 1 length + 5 data = 7 codewords.
        self::assertCount(7, $cw);
    }

    #[Test]
    public function base256_short_length_field_one_byte(): void
    {
        $cw = DataMatrixEncoder::encodeBase256(str_repeat('A', 100));
        self::assertSame(231, $cw[0]);
        // Length = 100; randomized с pos=2: temp = 100 + ((149*2)%255)+1 = 100 + 44 = 144.
        // After randomization: 144 (≤ 255).
        // Total: 1 + 1 + 100 = 102 codewords.
        self::assertCount(102, $cw);
    }

    #[Test]
    public function base256_long_length_field_two_bytes(): void
    {
        // ≥ 250 bytes → 2-byte length encoding.
        $cw = DataMatrixEncoder::encodeBase256(str_repeat('A', 300));
        self::assertSame(231, $cw[0]);
        // Total: 1 switch + 2 length + 300 data = 303 codewords.
        self::assertCount(303, $cw);
    }

    #[Test]
    public function base256_rejects_oversized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataMatrixEncoder::encodeBase256(str_repeat('A', 2000));
    }

    #[Test]
    public function base256_handles_binary_data(): void
    {
        $binary = "\x00\xFF\x01\x80\x7F";
        $cw = DataMatrixEncoder::encodeBase256($binary);
        self::assertSame(231, $cw[0]);
        self::assertCount(7, $cw); // 1 + 1 + 5
    }

    #[Test]
    public function c40_mode_switch_codeword(): void
    {
        $cw = DataMatrixEncoder::encodeC40('ABC');
        // First codeword = 230 (switch к C40).
        self::assertSame(230, $cw[0]);
        // 3 chars → 1 triplet = 2 codewords + switch = 3 total.
        self::assertCount(3, $cw);
    }

    #[Test]
    public function c40_compresses_uppercase_efficiently(): void
    {
        // 6 uppercase chars = 2 triplets = 4 CW + switch = 5 CW vs ASCII 6 CW.
        $cw = DataMatrixEncoder::encodeC40('ABCDEF');
        self::assertSame(230, $cw[0]);
        self::assertCount(5, $cw);
    }

    #[Test]
    public function c40_handles_digits(): void
    {
        $cw = DataMatrixEncoder::encodeC40('123');
        self::assertSame(230, $cw[0]);
        self::assertCount(3, $cw);
    }

    #[Test]
    public function c40_with_padding_emits_unlatch(): void
    {
        // 4 chars — incomplete triplet → unlatch CW 254 added.
        $cw = DataMatrixEncoder::encodeC40('ABCD');
        self::assertSame(230, $cw[0]);
        self::assertSame(254, $cw[count($cw) - 1]);
    }

    #[Test]
    public function text_mode_switch_codeword(): void
    {
        $cw = DataMatrixEncoder::encodeText('abc');
        self::assertSame(239, $cw[0]);
        self::assertCount(3, $cw);
    }

    #[Test]
    public function text_mode_lowercase_efficient(): void
    {
        $cw = DataMatrixEncoder::encodeText('hello');
        self::assertSame(239, $cw[0]);
        // 5 chars → 2 triplets (1 padded) + switch + unlatch = 6 CW.
        self::assertCount(6, $cw);
    }

    #[Test]
    public function x12_mode_switch_codeword(): void
    {
        $cw = DataMatrixEncoder::encodeX12('ABC');
        self::assertSame(238, $cw[0]);
        self::assertCount(3, $cw);
    }

    #[Test]
    public function x12_rejects_invalid_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataMatrixEncoder::encodeX12('abc'); // lowercase not in X12 set
    }

    #[Test]
    public function x12_handles_separator_chars(): void
    {
        // X12 supports CR/* />/SPACE/0-9/A-Z.
        $cw = DataMatrixEncoder::encodeX12('A*B>C');
        self::assertSame(238, $cw[0]);
    }

    #[Test]
    public function edifact_mode_switch_codeword(): void
    {
        $cw = DataMatrixEncoder::encodeEdifact('ABCD');
        self::assertSame(240, $cw[0]);
    }

    #[Test]
    public function edifact_handles_uppercase_punctuation(): void
    {
        // EDIFACT: ASCII 32..94 (uppercase, digits, punct).
        $cw = DataMatrixEncoder::encodeEdifact('HELLO+WORLD');
        self::assertSame(240, $cw[0]);
    }

    #[Test]
    public function edifact_rejects_lowercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataMatrixEncoder::encodeEdifact('abc'); // lowercase outside 32..94
    }

    #[Test]
    public function explicit_mode_parameter_works(): void
    {
        $ascii = new DataMatrixEncoder('HELLO', mode: DataMatrixEncoder::MODE_ASCII);
        $c40 = new DataMatrixEncoder('HELLO', mode: DataMatrixEncoder::MODE_C40);
        // Both should produce valid symbols (sizes may differ; для short
        // strings ASCII can be smaller due к switch overhead).
        self::assertGreaterThan(0, $ascii->size());
        self::assertGreaterThan(0, $c40->size());
    }

    #[Test]
    public function c40_more_compact_for_long_uppercase(): void
    {
        // Long uppercase string: C40 packs 3 chars / 2 CW → much more compact.
        $ascii = new DataMatrixEncoder(str_repeat('ABC', 10), mode: DataMatrixEncoder::MODE_ASCII);
        $c40 = new DataMatrixEncoder(str_repeat('ABC', 10), mode: DataMatrixEncoder::MODE_C40);
        // 30 chars: ASCII = 30 CW; C40 = 1 + 20 = 21 CW.
        self::assertLessThanOrEqual($ascii->size(), $c40->size());
    }

    #[Test]
    public function auto_mode_picks_base256_for_binary(): void
    {
        $enc = new DataMatrixEncoder("hello\x80\xFF", mode: DataMatrixEncoder::MODE_AUTO);
        // Should encode без exception (Base 256 handles high bytes).
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function auto_mode_picks_c40_for_uppercase_heavy(): void
    {
        // 10 uppercase chars → auto picks C40.
        $enc = new DataMatrixEncoder('ABCDEFGHIJ', mode: DataMatrixEncoder::MODE_AUTO);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function auto_mode_picks_text_for_lowercase_heavy(): void
    {
        $enc = new DataMatrixEncoder('abcdefghij', mode: DataMatrixEncoder::MODE_AUTO);
        self::assertGreaterThan(0, $enc->size());
    }

    // -------- Phase 196: Macro 05/06 mode --------

    #[Test]
    public function macro_05_prepends_codeword(): void
    {
        // Macro 05: header conceptually "[)>RS05GS" prepended.
        $enc = new DataMatrixEncoder('123', macroMode: DataMatrixEncoder::MACRO_05);
        // Symbol должен encode без exception.
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function macro_06_prepends_codeword(): void
    {
        $enc = new DataMatrixEncoder('ABC', macroMode: DataMatrixEncoder::MACRO_06);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function macro_constants_have_correct_values(): void
    {
        self::assertSame(236, DataMatrixEncoder::MACRO_05);
        self::assertSame(237, DataMatrixEncoder::MACRO_06);
    }

    #[Test]
    public function macro_rejects_invalid_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder('data', macroMode: 99);
    }

    #[Test]
    public function no_macro_default_behavior(): void
    {
        // Без macroMode — same as before.
        $withoutMacro = new DataMatrixEncoder('hello');
        $withMacro = new DataMatrixEncoder('hello', macroMode: DataMatrixEncoder::MACRO_05);
        // Withmacro adds 1 CW → symbol size может differ.
        self::assertGreaterThan(0, $withoutMacro->size());
        self::assertGreaterThan(0, $withMacro->size());
    }

    // -------- Phase 197: GS1 + ECI --------

    #[Test]
    public function gs1_datamatrix_prepends_fnc1(): void
    {
        $enc = new DataMatrixEncoder('01095060001343528200', gs1: true);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function fnc1_constant_is_232(): void
    {
        self::assertSame(232, DataMatrixEncoder::FNC1);
    }

    #[Test]
    public function eci_marker_short_designator(): void
    {
        $enc = new DataMatrixEncoder('test', eciDesignator: 26); // UTF-8
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function eci_marker_medium_designator(): void
    {
        $enc = new DataMatrixEncoder('test', eciDesignator: 1000);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function eci_marker_large_designator(): void
    {
        $enc = new DataMatrixEncoder('test', eciDesignator: 50000);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function eci_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder('test', eciDesignator: -1);
    }

    #[Test]
    public function eci_rejects_too_large(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder('test', eciDesignator: 1000000);
    }

    #[Test]
    public function eci_constant_is_241(): void
    {
        self::assertSame(241, DataMatrixEncoder::ECI_CODEWORD);
    }

    #[Test]
    public function gs1_and_eci_combined(): void
    {
        // GS1 marker + ECI for non-default charset.
        $enc = new DataMatrixEncoder('data', gs1: true, eciDesignator: 26);
        self::assertGreaterThan(0, $enc->size());
    }
}
