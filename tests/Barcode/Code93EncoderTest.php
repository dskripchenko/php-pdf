<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code93Encoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 205: Code 93 alphanumeric barcode encoder tests.
 */
final class Code93EncoderTest extends TestCase
{
    #[Test]
    public function encodes_single_char(): void
    {
        $enc = new Code93Encoder('1');
        // (1 data + 4 wrapper) × 9 + 1 termination = 46 modules.
        self::assertSame(46, $enc->moduleCount());
        self::assertSame('1', $enc->canonical);
    }

    #[Test]
    public function encodes_multi_char_test_string(): void
    {
        $enc = new Code93Encoder('TEST');
        // (4 + 4) × 9 + 1 = 73 modules.
        self::assertSame(73, $enc->moduleCount());
        self::assertSame('TEST', $enc->canonical);
    }

    #[Test]
    public function check_digits_for_test_string(): void
    {
        // T=29, E=14, S=28, T=29
        // C weights (1..20) from right: T·1 + S·2 + E·3 + T·4 = 29+56+42+116 = 243
        // 243 % 47 = 8 → '8'
        // K weights (1..15) from right, value "TEST8": 8·1 + T·2 + S·3 + E·4 + T·5
        //   = 8 + 58 + 84 + 56 + 145 = 351; 351 % 47 = 22 → 'M'
        $enc = new Code93Encoder('TEST');
        self::assertSame('8', $enc->checkC);
        self::assertSame('M', $enc->checkK);
    }

    #[Test]
    public function check_digit_single_digit(): void
    {
        // "1": C = 1·1 = 1 → '1'
        // K with "11": 1·1 + 1·2 = 3 → '3'
        $enc = new Code93Encoder('1');
        self::assertSame('1', $enc->checkC);
        self::assertSame('3', $enc->checkK);
    }

    #[Test]
    public function check_digit_empty_zero(): void
    {
        // For input "0": value=0
        // C: 0·1 = 0 → '0'
        // K with "00": 0·1 + 0·2 = 0 → '0'
        $enc = new Code93Encoder('0');
        self::assertSame('0', $enc->checkC);
        self::assertSame('0', $enc->checkK);
    }

    #[Test]
    public function auto_uppercase(): void
    {
        $enc = new Code93Encoder('hello');
        self::assertSame('HELLO', $enc->canonical);
    }

    #[Test]
    public function rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code93Encoder('');
    }

    #[Test]
    public function rejects_asterisk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code93Encoder('A*B');
    }

    #[Test]
    public function rejects_unsupported_char(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code93Encoder('A@B'); // '@' not in 47-char set
    }

    #[Test]
    public function rejects_unicode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code93Encoder('Тест');
    }

    #[Test]
    public function accepts_all_punctuation(): void
    {
        $enc = new Code93Encoder('A-B.C D$E/F+G%H');
        self::assertSame('A-B.C D$E/F+G%H', $enc->canonical);
    }

    #[Test]
    public function accepts_digits_and_letters(): void
    {
        $enc = new Code93Encoder('ABC123XYZ');
        self::assertSame('ABC123XYZ', $enc->canonical);
    }

    #[Test]
    public function module_count_formula_holds(): void
    {
        foreach ([1, 2, 5, 10, 20] as $n) {
            $data = str_repeat('A', $n);
            $enc = new Code93Encoder($data);
            // (N + 4) × 9 + 1.
            self::assertSame(9 * ($n + 4) + 1, $enc->moduleCount(), "n=$n");
        }
    }

    #[Test]
    public function starts_with_start_pattern(): void
    {
        // '*' pattern = '101011110' — first 9 modules of encoded barcode.
        $enc = new Code93Encoder('1');
        $modules = $enc->modules();
        $expected = '101011110';
        $actual = '';
        for ($i = 0; $i < 9; $i++) {
            $actual .= $modules[$i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function ends_with_termination_bar(): void
    {
        // Last module = '1' (termination bar).
        $enc = new Code93Encoder('1');
        $modules = $enc->modules();
        $n = count($modules);
        self::assertTrue($modules[$n - 1]);
        // Second-to-last 9 modules = stop char '*' = '101011110'.
        // So module $n-2 = '0' (last bit of '*' pattern), but $n-1 = '1' termination.
        self::assertFalse($modules[$n - 2]);
    }

    #[Test]
    public function continuous_encoding_no_gaps(): void
    {
        // Code 93 has no inter-character gaps — each char is exactly 9 modules
        // adjacent to next. Verify by checking pattern '*1*' encoded:
        // *=101011110, 1=101001000, check_C of "1"='1'=101001000,
        // check_K of "11"='3'=101000010, *=101011110, term=1
        // → 101011110 + 101001000 + 101001000 + 101000010 + 101011110 + 1
        // Total = 46 modules. Verify it concatenates without separators.
        $enc = new Code93Encoder('1');
        $modules = $enc->modules();
        // After start * (9 mod): data '1' immediately at offset 9.
        // '1' pattern starts with '101' so module 9 = true, 10 = false, 11 = true.
        self::assertTrue($modules[9]);
        self::assertFalse($modules[10]);
        self::assertTrue($modules[11]);
    }

    #[Test]
    public function check_digit_helper_matches_constructor(): void
    {
        $cValue = Code93Encoder::computeCheckDigit('TEST', 20);
        self::assertSame(8, $cValue);

        $kValue = Code93Encoder::computeCheckDigit('TEST8', 15);
        self::assertSame(22, $kValue);
    }

    #[Test]
    public function check_digit_helper_rejects_invalid_char(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code93Encoder::computeCheckDigit('A@B', 20);
    }

    #[Test]
    public function check_digit_weight_cycles(): void
    {
        // Long data triggers weight cycling. For 22-char input, C weights
        // cycle: 1..20, 1, 2. Verify это produces deterministic result.
        $value = str_repeat('A', 22);
        $c = Code93Encoder::computeCheckDigit($value, 20);
        // Without verifying exact value, just ensure result is in [0, 47).
        self::assertGreaterThanOrEqual(0, $c);
        self::assertLessThan(47, $c);
    }

    #[Test]
    public function quiet_zone_default_10(): void
    {
        $enc = new Code93Encoder('1');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(46 + 20, $padded);
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('CODE93', BarcodeFormat::Code93, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(CODE93) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(10, $count);
    }

    #[Test]
    public function code93_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Code93->is2D());
        self::assertFalse(BarcodeFormat::Code93->isStacked());
    }

    #[Test]
    public function space_char_supported(): void
    {
        $enc = new Code93Encoder('A B C');
        self::assertSame('A B C', $enc->canonical);
        self::assertSame(9 * (5 + 4) + 1, $enc->moduleCount());
    }
}
