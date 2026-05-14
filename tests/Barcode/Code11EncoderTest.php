<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code11Encoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 209: Code 11 (USS Code 11) numeric+dash barcode tests.
 */
final class Code11EncoderTest extends TestCase
{
    #[Test]
    public function encodes_single_digit(): void
    {
        $enc = new Code11Encoder('1');
        // "*1*": *=7 + gap=1 + 1=7 + gap=1 + *=7 = 23 modules.
        self::assertSame(23, $enc->moduleCount());
        self::assertSame('1', $enc->canonical);
    }

    #[Test]
    public function encodes_digit_0(): void
    {
        // '0' pattern "00001": widths 1+1+1+1+2 = 6 modules.
        // Total "*0*": 7+1+6+1+7 = 22.
        $enc = new Code11Encoder('0');
        self::assertSame(22, $enc->moduleCount());
    }

    #[Test]
    public function encodes_dash(): void
    {
        // '-' pattern "00010": widths 1+1+1+2+1 = 6 modules.
        $enc = new Code11Encoder('-');
        self::assertSame(22, $enc->moduleCount());
    }

    #[Test]
    public function encodes_multi_char(): void
    {
        $enc = new Code11Encoder('123-45');
        self::assertSame('123-45', $enc->canonical);
    }

    #[Test]
    public function rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code11Encoder('');
    }

    #[Test]
    public function rejects_asterisk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code11Encoder('1*2');
    }

    #[Test]
    public function rejects_letters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code11Encoder('abc');
    }

    #[Test]
    public function rejects_punctuation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code11Encoder('1.2');
    }

    #[Test]
    public function check_digit_for_1234(): void
    {
        // "1234": sum = 4*1 + 3*2 + 2*3 + 1*4 = 20 → 20%11 = 9.
        self::assertSame(9, Code11Encoder::computeCheckDigit('1234'));
    }

    #[Test]
    public function check_digit_for_dash_input(): void
    {
        // "123-45": 5,4,10,3,2,1 × 1,2,3,4,5,6 = 5+8+30+12+10+6 = 71; 71%11 = 5.
        self::assertSame(5, Code11Encoder::computeCheckDigit('123-45'));
    }

    #[Test]
    public function check_digit_weight_cycles(): void
    {
        // 11+ char input — weight cycles back к 1.
        $val = str_repeat('1', 11);
        $c = Code11Encoder::computeCheckDigit($val, 10);
        self::assertGreaterThanOrEqual(0, $c);
        self::assertLessThan(11, $c);
    }

    #[Test]
    public function with_check_digit_appends_c(): void
    {
        $enc = new Code11Encoder('1234', withCheckDigit: true);
        self::assertSame('12349', $enc->canonical);
    }

    #[Test]
    public function with_double_check_appends_c_and_k(): void
    {
        // "1234" → C=9, "12349" → K computed на maxWeight 9.
        // "12349": 9,4,3,2,1 × 1,2,3,4,5 = 9+8+9+8+5 = 39; 39%11 = 6.
        $enc = new Code11Encoder('1234', doubleCheck: true);
        self::assertSame('123496', $enc->canonical);
    }

    #[Test]
    public function start_pattern_first_7_modules(): void
    {
        // '*' pattern "00110": narrow bar(1) + narrow space(1) + wide bar(11) + wide space(00) + narrow bar(1).
        // = "1" + "0" + "11" + "00" + "1" = "1011001" (7 modules).
        $enc = new Code11Encoder('1');
        $m = $enc->modules();
        $expected = '1011001';
        $actual = '';
        for ($i = 0; $i < 7; $i++) {
            $actual .= $m[$i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function inter_character_gap(): void
    {
        $enc = new Code11Encoder('1');
        $m = $enc->modules();
        // After first '*' (7 mod) → gap at index 7 (false).
        self::assertFalse($m[7]);
    }

    #[Test]
    public function quiet_zone_default(): void
    {
        $enc = new Code11Encoder('1');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(23 + 20, $padded);
    }

    #[Test]
    public function check_digit_helper_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code11Encoder::computeCheckDigit('1A2');
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('123-456', BarcodeFormat::Code11, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(123-456) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function code11_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Code11->is2D());
        self::assertFalse(BarcodeFormat::Code11->isStacked());
    }

    #[Test]
    public function all_digits_acceptable(): void
    {
        $enc = new Code11Encoder('0123456789');
        self::assertSame('0123456789', $enc->canonical);
    }
}
