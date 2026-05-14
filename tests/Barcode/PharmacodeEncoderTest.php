<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\PharmacodeEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 207: Pharmacode (Laetus) barcode encoder tests.
 */
final class PharmacodeEncoderTest extends TestCase
{
    #[Test]
    public function min_value_3_produces_two_narrow_bars(): void
    {
        $enc = new PharmacodeEncoder(3);
        // 3 odd → narrow, N=1; 1 odd → narrow, N=0.
        // bars = [false, false] (both narrow).
        self::assertSame([false, false], $enc->bars);
        // Modules: "1" + "00" + "1" = "1001" = 4 modules.
        self::assertSame(4, $enc->moduleCount());
        self::assertSame(3, $enc->value);
    }

    #[Test]
    public function value_4_produces_wide_narrow(): void
    {
        $enc = new PharmacodeEncoder(4);
        // 4 even → wide, N=1; 1 odd → narrow, N=0.
        self::assertSame([true, false], $enc->bars);
        // "111" + "00" + "1" = 6 modules.
        self::assertSame(6, $enc->moduleCount());
    }

    #[Test]
    public function value_5_produces_narrow_wide(): void
    {
        $enc = new PharmacodeEncoder(5);
        // 5 odd → narrow, N=2; 2 even → wide, N=0.
        self::assertSame([false, true], $enc->bars);
        // "1" + "00" + "111" = 6 modules.
        self::assertSame(6, $enc->moduleCount());
    }

    #[Test]
    public function value_6_produces_two_wide(): void
    {
        $enc = new PharmacodeEncoder(6);
        // 6 even → wide, N=2; 2 even → wide, N=0.
        self::assertSame([true, true], $enc->bars);
        // "111" + "00" + "111" = 8 modules.
        self::assertSame(8, $enc->moduleCount());
    }

    #[Test]
    public function value_7_produces_three_narrow(): void
    {
        $enc = new PharmacodeEncoder(7);
        self::assertSame([false, false, false], $enc->bars);
        // 1+2+1+2+1 = 7 modules.
        self::assertSame(7, $enc->moduleCount());
    }

    #[Test]
    public function value_8_produces_wide_narrow_narrow(): void
    {
        $enc = new PharmacodeEncoder(8);
        // 8 even → wide, N=3; 3 odd → narrow, N=1; 1 odd → narrow.
        self::assertSame([true, false, false], $enc->bars);
        // 3+2+1+2+1 = 9 modules.
        self::assertSame(9, $enc->moduleCount());
    }

    #[Test]
    public function max_value_131070_produces_16_wide_bars(): void
    {
        $enc = new PharmacodeEncoder(131070);
        // All wide.
        self::assertCount(16, $enc->bars);
        foreach ($enc->bars as $bar) {
            self::assertTrue($bar);
        }
        // 16 wide × 3 + 15 spaces × 2 = 48 + 30 = 78 modules.
        self::assertSame(78, $enc->moduleCount());
    }

    #[Test]
    public function rejects_value_below_min(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PharmacodeEncoder(2);
    }

    #[Test]
    public function rejects_value_above_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PharmacodeEncoder(131071);
    }

    #[Test]
    public function rejects_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PharmacodeEncoder(0);
    }

    #[Test]
    public function rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PharmacodeEncoder(-5);
    }

    #[Test]
    public function build_bars_helper_consistent_with_encoder(): void
    {
        // Static helper should produce same bar sequence as instance.
        foreach ([3, 4, 5, 10, 100, 1000, 131070] as $value) {
            $enc = new PharmacodeEncoder($value);
            self::assertSame($enc->bars, PharmacodeEncoder::buildBars($value), "value=$value");
        }
    }

    #[Test]
    public function build_bars_helper_rejects_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PharmacodeEncoder::buildBars(2);
    }

    #[Test]
    public function module_pattern_for_value_3(): void
    {
        $enc = new PharmacodeEncoder(3);
        $m = $enc->modules();
        // Expected "1001".
        self::assertTrue($m[0]);   // narrow bar
        self::assertFalse($m[1]);  // space
        self::assertFalse($m[2]);  // space
        self::assertTrue($m[3]);   // narrow bar
    }

    #[Test]
    public function module_pattern_for_value_4(): void
    {
        $enc = new PharmacodeEncoder(4);
        $m = $enc->modules();
        // Expected "111" (wide) + "00" + "1" (narrow) = "111001".
        $expected = [true, true, true, false, false, true];
        self::assertSame($expected, $m);
    }

    #[Test]
    public function constants_exposed(): void
    {
        self::assertSame(3, PharmacodeEncoder::MIN_VALUE);
        self::assertSame(131070, PharmacodeEncoder::MAX_VALUE);
    }

    #[Test]
    public function quiet_zone_default_6(): void
    {
        $enc = new PharmacodeEncoder(3);
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(4 + 12, $padded);
    }

    #[Test]
    public function quiet_zone_custom(): void
    {
        $enc = new PharmacodeEncoder(3);
        $padded = $enc->modulesWithQuietZone(15);
        self::assertCount(4 + 30, $padded);
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('12345', BarcodeFormat::Pharmacode, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Caption — numeric value string.
        self::assertStringContainsString('(12345) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(0, $count);
    }

    #[Test]
    public function engine_rejects_non_numeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $doc = new Document(new Section([
            new Barcode('abc', BarcodeFormat::Pharmacode, widthPt: 100),
        ]));
        $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function pharmacode_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Pharmacode->is2D());
        self::assertFalse(BarcodeFormat::Pharmacode->isStacked());
    }

    #[Test]
    public function bar_count_grows_logarithmically(): void
    {
        // Each additional bit ~doubles the value range:
        // 3-6: 2 bars; 7-14: 3 bars; 15-30: 4 bars; etc.
        self::assertCount(2, (new PharmacodeEncoder(3))->bars);
        self::assertCount(2, (new PharmacodeEncoder(6))->bars);
        self::assertCount(3, (new PharmacodeEncoder(7))->bars);
        self::assertCount(3, (new PharmacodeEncoder(14))->bars);
        self::assertCount(4, (new PharmacodeEncoder(15))->bars);
        self::assertCount(4, (new PharmacodeEncoder(30))->bars);
    }
}
