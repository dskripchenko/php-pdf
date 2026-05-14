<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\ItfEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 203: ITF (Interleaved 2 of 5) barcode encoder tests.
 */
final class ItfEncoderTest extends TestCase
{
    #[Test]
    public function encodes_2_digit_input(): void
    {
        $enc = new ItfEncoder('12');
        // 4 (start) + 14 (1 pair) + 4 (stop) = 22 modules.
        self::assertSame(22, $enc->moduleCount());
        self::assertSame('12', $enc->canonical);
    }

    #[Test]
    public function encodes_itf14_length(): void
    {
        $enc = new ItfEncoder('12345678901231');
        // 4 + 7*14 + 4 = 106 modules.
        self::assertSame(106, $enc->moduleCount());
    }

    #[Test]
    public function rejects_odd_length_without_check(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItfEncoder('123');
    }

    #[Test]
    public function rejects_non_digit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItfEncoder('12a4');
    }

    #[Test]
    public function rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItfEncoder('');
    }

    #[Test]
    public function odd_length_with_check_digit_works(): void
    {
        // 13 digits + check = 14, even → OK.
        $enc = new ItfEncoder('1234567890123', withCheckDigit: true);
        self::assertSame('12345678901231', $enc->canonical);
        self::assertSame(106, $enc->moduleCount());
    }

    #[Test]
    public function check_digit_gtin13_example(): void
    {
        // Wikipedia GTIN-13: 590123412345 → check 7.
        self::assertSame(7, ItfEncoder::computeCheckDigit('590123412345'));
    }

    #[Test]
    public function check_digit_gtin14_example(): void
    {
        // 1234567890123 → check 1.
        self::assertSame(1, ItfEncoder::computeCheckDigit('1234567890123'));
    }

    #[Test]
    public function check_digit_gtin8_example(): void
    {
        // 9638507 → check 4 (same as EAN-8).
        self::assertSame(4, ItfEncoder::computeCheckDigit('9638507'));
    }

    #[Test]
    public function check_digit_all_zeros(): void
    {
        self::assertSame(0, ItfEncoder::computeCheckDigit('0000000'));
    }

    #[Test]
    public function check_digit_helper_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItfEncoder::computeCheckDigit('');
    }

    #[Test]
    public function check_digit_helper_rejects_non_digit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItfEncoder::computeCheckDigit('12a4');
    }

    #[Test]
    public function start_pattern_is_1010(): void
    {
        $enc = new ItfEncoder('12');
        $m = $enc->modules();
        self::assertTrue($m[0]);   // narrow bar
        self::assertFalse($m[1]);  // narrow space
        self::assertTrue($m[2]);   // narrow bar
        self::assertFalse($m[3]);  // narrow space
    }

    #[Test]
    public function stop_pattern_is_wide_bar_narrow_space_narrow_bar(): void
    {
        // For ratio 2:1: stop = "11" + "0" + "1" = "1101".
        $enc = new ItfEncoder('12');
        $m = $enc->modules();
        $n = count($m);
        self::assertTrue($m[$n - 4]);   // wide bar [0]
        self::assertTrue($m[$n - 3]);   // wide bar [1]
        self::assertFalse($m[$n - 2]);  // narrow space
        self::assertTrue($m[$n - 1]);   // narrow bar
    }

    #[Test]
    public function pair_encoding_interleaves_correctly(): void
    {
        // For "12": digit 1 = '10001' (bars), digit 2 = '01001' (spaces).
        // Interleaved: B(1=w) S(0=n) B(0=n) S(1=w) B(0=n) S(0=n) B(0=n) S(0=n) B(1=w) S(1=w)
        // Widths (2:1): bar2 + space1 + bar1 + space2 + bar1 + space1 + bar1 + space1 + bar2 + space2
        // Total = 2+1+1+2+1+1+1+1+2+2 = 14 modules ✓
        // Modules after start (offset 4):
        //   '11' (wide bar) + '0' (narrow space) + '1' (narrow bar)
        //   + '00' (wide space) + '1' (narrow bar) + '0' (narrow space)
        //   + '1' (narrow bar) + '0' (narrow space) + '11' (wide bar) + '00' (wide space)
        //   = "11" + "0" + "1" + "00" + "1" + "0" + "1" + "0" + "11" + "00"
        //   = "1101001010" + "1100"
        //   = "11010010101100"
        $enc = new ItfEncoder('12');
        $m = $enc->modules();
        $expected = '11010010101100';
        $actual = '';
        for ($i = 0; $i < 14; $i++) {
            $actual .= $m[4 + $i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function quiet_zone_default_10(): void
    {
        $enc = new ItfEncoder('12');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(22 + 20, $padded);
    }

    #[Test]
    public function quiet_zone_custom_size(): void
    {
        $enc = new ItfEncoder('12');
        $padded = $enc->modulesWithQuietZone(15);
        self::assertCount(22 + 30, $padded);
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('12345678901231', BarcodeFormat::Itf, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(12345678901231) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(10, $count);
    }

    #[Test]
    public function itf_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Itf->is2D());
        self::assertFalse(BarcodeFormat::Itf->isStacked());
    }

    #[Test]
    public function long_input_module_count_formula(): void
    {
        // 20 digits → 8 + 7*20 = 148.
        $enc = new ItfEncoder('12345678901234567890');
        self::assertSame(148, $enc->moduleCount());
    }
}
