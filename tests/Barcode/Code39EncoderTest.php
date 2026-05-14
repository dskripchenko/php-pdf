<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code39Encoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 202: Code 39 alphanumeric barcode encoder tests.
 */
final class Code39EncoderTest extends TestCase
{
    #[Test]
    public function encodes_single_char(): void
    {
        $enc = new Code39Encoder('A');
        // "*A*" = 3 chars × 15 modules + 2 gaps = 47 modules.
        self::assertSame(47, $enc->moduleCount());
        self::assertSame('A', $enc->canonical);
    }

    #[Test]
    public function encodes_multi_char_string(): void
    {
        $enc = new Code39Encoder('CODE39');
        // "*CODE39*" = 8 chars × 15 + 7 gaps = 127.
        self::assertSame(127, $enc->moduleCount());
    }

    #[Test]
    public function auto_uppercases_lowercase_input(): void
    {
        $enc = new Code39Encoder('hello');
        self::assertSame('HELLO', $enc->canonical);
    }

    #[Test]
    public function accepts_digits(): void
    {
        $enc = new Code39Encoder('1234567890');
        self::assertSame('1234567890', $enc->canonical);
    }

    #[Test]
    public function accepts_special_symbols(): void
    {
        $enc = new Code39Encoder('A-B.C $D/E+F%G');
        self::assertSame('A-B.C $D/E+F%G', $enc->canonical);
    }

    #[Test]
    public function rejects_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code39Encoder('');
    }

    #[Test]
    public function rejects_asterisk_in_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code39Encoder('A*B');
    }

    #[Test]
    public function rejects_unsupported_character(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code39Encoder('hello!'); // '!' not in Code 39 set
    }

    #[Test]
    public function rejects_at_symbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code39Encoder('user@domain');
    }

    #[Test]
    public function starts_with_asterisk_pattern_bar(): void
    {
        // '*' pattern = '010010100'. First element = wide bar? Position 0 = bar, val '0' = narrow.
        // So first module = bar (true), 1 module wide.
        $enc = new Code39Encoder('A');
        $modules = $enc->modules();
        // First element of '*' = narrow bar → 1 true module.
        self::assertTrue($modules[0]);
        // Position 1 = first space (narrow, val 1 in '010010100' → narrow space).
        // Wait, pattern[1]='1' for '*' = wide space → 3 false modules at 1..3.
        // Let me recompute: '*' = '010010100'.
        //   pos 0 (bar)   = 0 → narrow, 1 true module    @ 0
        //   pos 1 (space) = 1 → wide,   3 false modules  @ 1,2,3
        //   pos 2 (bar)   = 0 → narrow, 1 true module    @ 4
        //   pos 3 (space) = 0 → narrow, 1 false module   @ 5
        //   pos 4 (bar)   = 1 → wide,   3 true modules   @ 6,7,8
        //   pos 5 (space) = 0 → narrow, 1 false module   @ 9
        //   pos 6 (bar)   = 1 → wide,   3 true modules   @ 10,11,12
        //   pos 7 (space) = 0 → narrow, 1 false module   @ 13
        //   pos 8 (bar)   = 0 → narrow, 1 true module    @ 14
        // Total = 15 modules ✓
        self::assertFalse($modules[1]);
        self::assertFalse($modules[2]);
        self::assertFalse($modules[3]);
        self::assertTrue($modules[4]);
        self::assertFalse($modules[5]);
        self::assertTrue($modules[6]);
        self::assertTrue($modules[7]);
        self::assertTrue($modules[8]);
    }

    #[Test]
    public function ends_with_asterisk_pattern(): void
    {
        // Last 15 modules = '*' pattern (no trailing gap).
        $enc = new Code39Encoder('A');
        $modules = $enc->modules();
        $n = count($modules);
        // Last module = bar narrow = true.
        self::assertTrue($modules[$n - 1]);
    }

    #[Test]
    public function quiet_zone_default_10(): void
    {
        $enc = new Code39Encoder('A');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(47 + 20, $padded);
    }

    #[Test]
    public function check_digit_for_known_input(): void
    {
        // CODE39: C=12, O=24, D=13, E=14, 3=3, 9=9 → sum 75 → 75%43=32 → 'W'.
        self::assertSame('W', Code39Encoder::checkDigitChar('CODE39'));
        // HELLO: H=17, E=14, L=21, L=21, O=24 → 97 → 97%43=11 → 'B'.
        self::assertSame('B', Code39Encoder::checkDigitChar('HELLO'));
        // 12345: sum=15 → 'F'.
        self::assertSame('F', Code39Encoder::checkDigitChar('12345'));
    }

    #[Test]
    public function check_digit_for_special_chars(): void
    {
        // 'A%' = 10 + 42 = 52 → 52%43=9 → '9'.
        self::assertSame('9', Code39Encoder::checkDigitChar('A%'));
        // '-' alone = 36 → '-'.
        self::assertSame('-', Code39Encoder::checkDigitChar('-'));
    }

    #[Test]
    public function with_check_digit_appends_to_canonical(): void
    {
        $enc = new Code39Encoder('CODE39', withCheckDigit: true);
        self::assertSame('CODE39W', $enc->canonical);
        // 7 data chars + 2 delimiters = 9 chars × 15 + 8 gaps = 143.
        self::assertSame(143, $enc->moduleCount());
    }

    #[Test]
    public function verifies_check_digit_correct(): void
    {
        self::assertTrue(Code39Encoder::verifyCheckDigit('CODE39W'));
        self::assertTrue(Code39Encoder::verifyCheckDigit('HELLOB'));
    }

    #[Test]
    public function verifies_check_digit_incorrect(): void
    {
        self::assertFalse(Code39Encoder::verifyCheckDigit('CODE39X'));
        self::assertFalse(Code39Encoder::verifyCheckDigit('HELLOZ'));
    }

    #[Test]
    public function verify_too_short_returns_false(): void
    {
        self::assertFalse(Code39Encoder::verifyCheckDigit('A'));
        self::assertFalse(Code39Encoder::verifyCheckDigit(''));
    }

    #[Test]
    public function inter_char_gaps_present(): void
    {
        // Between two chars, there's 1 narrow space (0 module).
        // After first char's last bar (true module), we expect a single false module.
        $enc = new Code39Encoder('AB');
        $modules = $enc->modules();
        // First char '*' = 15 modules at 0..14. Gap = module 15 (false).
        self::assertFalse($modules[15]);
        // Then char 'A' = 15 modules at 16..30. Gap at 31.
        self::assertFalse($modules[31]);
    }

    #[Test]
    public function digit_zero_pattern_correct(): void
    {
        // '0' pattern = '000110100'.
        //   pos 0 bar  narrow → true @ offset 0
        //   pos 1 spc  narrow → false @ 1
        //   pos 2 bar  narrow → true @ 2
        //   pos 3 spc  wide  → false @ 3,4,5
        //   pos 4 bar  wide  → true @ 6,7,8
        //   pos 5 spc  narrow → false @ 9
        //   pos 6 bar  narrow → true @ 10
        //   pos 7 spc  wide  → false @ 11,12,13
        //   pos 8 bar  narrow → true @ 14
        // Encode "*0*" — '0' starts at offset 16.
        $enc = new Code39Encoder('0');
        $modules = $enc->modules();
        $base = 16; // after '*' (15) + 1 gap.
        self::assertTrue($modules[$base + 0]);
        self::assertFalse($modules[$base + 1]);
        self::assertTrue($modules[$base + 2]);
        self::assertFalse($modules[$base + 3]);
        self::assertFalse($modules[$base + 4]);
        self::assertFalse($modules[$base + 5]);
        self::assertTrue($modules[$base + 6]);
        self::assertTrue($modules[$base + 7]);
        self::assertTrue($modules[$base + 8]);
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('CODE39', BarcodeFormat::Code39, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(CODE39) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(10, $count);
    }

    #[Test]
    public function code39_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Code39->is2D());
        self::assertFalse(BarcodeFormat::Code39->isStacked());
    }

    #[Test]
    public function unicode_input_rejected_cleanly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code39Encoder('Кириллица');
    }
}
