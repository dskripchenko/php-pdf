<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\MsiPlesseyEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 206: MSI Plessey barcode encoder tests.
 */
final class MsiPlesseyEncoderTest extends TestCase
{
    #[Test]
    public function encodes_single_digit(): void
    {
        $enc = new MsiPlesseyEncoder('1');
        // 3 (start) + 12 (1 digit) + 4 (stop) = 19 modules.
        self::assertSame(19, $enc->moduleCount());
        self::assertSame('1', $enc->canonical);
    }

    #[Test]
    public function encodes_multi_digit_input(): void
    {
        $enc = new MsiPlesseyEncoder('1234');
        // 3 + 12*4 + 4 = 55 modules.
        self::assertSame(55, $enc->moduleCount());
    }

    #[Test]
    public function module_count_formula(): void
    {
        foreach ([1, 5, 10, 20] as $n) {
            $enc = new MsiPlesseyEncoder(str_repeat('5', $n));
            self::assertSame(12 * $n + 7, $enc->moduleCount(), "n=$n");
        }
    }

    #[Test]
    public function rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MsiPlesseyEncoder('');
    }

    #[Test]
    public function rejects_non_digit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MsiPlesseyEncoder('12a4');
    }

    #[Test]
    public function rejects_letters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MsiPlesseyEncoder('ABC');
    }

    #[Test]
    public function start_pattern_is_110(): void
    {
        $enc = new MsiPlesseyEncoder('5');
        $m = $enc->modules();
        self::assertTrue($m[0]);   // bar wide [0]
        self::assertTrue($m[1]);   // bar wide [1]
        self::assertFalse($m[2]);  // space narrow
    }

    #[Test]
    public function stop_pattern_is_1001(): void
    {
        // Last 4 modules = "1001".
        $enc = new MsiPlesseyEncoder('5');
        $m = $enc->modules();
        $n = count($m);
        self::assertTrue($m[$n - 4]);
        self::assertFalse($m[$n - 3]);
        self::assertFalse($m[$n - 2]);
        self::assertTrue($m[$n - 1]);
    }

    #[Test]
    public function digit_zero_pattern(): void
    {
        // '0' = "0000" → 100·100·100·100 = "100100100100".
        $enc = new MsiPlesseyEncoder('0');
        $m = $enc->modules();
        // Pattern at offset 3 (after start "110").
        $expected = '100100100100';
        $actual = '';
        for ($i = 0; $i < 12; $i++) {
            $actual .= $m[3 + $i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function digit_nine_pattern(): void
    {
        // '9' = "1001" → 110·100·100·110 = "110100100110".
        $enc = new MsiPlesseyEncoder('9');
        $m = $enc->modules();
        $expected = '110100100110';
        $actual = '';
        for ($i = 0; $i < 12; $i++) {
            $actual .= $m[3 + $i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function check_digit_known_example_1234(): void
    {
        // "1234" → Mod-10 check = 4.
        // Right-to-left: 4,3,2,1; odd-pos digits 4,2 → "24" × 2 = 48 → sum 12.
        // Even-pos digits 3,1 → sum 4. Total 16 → check (10-6)%10 = 4.
        self::assertSame(4, MsiPlesseyEncoder::computeCheckDigit('1234'));
    }

    #[Test]
    public function check_digit_known_example_1234567(): void
    {
        // "1234567" → check = 4.
        self::assertSame(4, MsiPlesseyEncoder::computeCheckDigit('1234567'));
    }

    #[Test]
    public function check_digit_all_zeros(): void
    {
        self::assertSame(0, MsiPlesseyEncoder::computeCheckDigit('00000'));
    }

    #[Test]
    public function check_digit_single_digit(): void
    {
        // "5": odd-pos="5", "5"*2=10, sum=1, even=0, total=1, check=(10-1)%10=9.
        self::assertSame(9, MsiPlesseyEncoder::computeCheckDigit('5'));
    }

    #[Test]
    public function with_check_digit_appends_to_canonical(): void
    {
        $enc = new MsiPlesseyEncoder('1234', withCheckDigit: true);
        self::assertSame('12344', $enc->canonical);
        self::assertSame(12 * 5 + 7, $enc->moduleCount());
    }

    #[Test]
    public function check_digit_helper_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MsiPlesseyEncoder::computeCheckDigit('');
    }

    #[Test]
    public function check_digit_helper_rejects_non_digit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MsiPlesseyEncoder::computeCheckDigit('12a4');
    }

    #[Test]
    public function quiet_zone_default_12(): void
    {
        $enc = new MsiPlesseyEncoder('1');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(19 + 24, $padded);
    }

    #[Test]
    public function quiet_zone_custom(): void
    {
        $enc = new MsiPlesseyEncoder('1');
        $padded = $enc->modulesWithQuietZone(20);
        self::assertCount(19 + 40, $padded);
    }

    #[Test]
    public function each_digit_consumes_12_modules(): void
    {
        // For "01234567890", check each digit produces exactly 12 modules
        // (after start, before next digit / stop).
        $enc = new MsiPlesseyEncoder('0123456789');
        // 3 (start) + 10*12 + 4 (stop) = 127.
        self::assertSame(127, $enc->moduleCount());
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('1234567', BarcodeFormat::MsiPlessey, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(1234567) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function msi_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::MsiPlessey->is2D());
        self::assertFalse(BarcodeFormat::MsiPlessey->isStacked());
    }
}
