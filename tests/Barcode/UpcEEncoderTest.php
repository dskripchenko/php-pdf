<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\UpcEEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 201: UPC-E zero-suppressed barcode encoder tests.
 */
final class UpcEEncoderTest extends TestCase
{
    #[Test]
    public function expansion_d6_le_2_inserts_d6_then_four_zeros(): void
    {
        // body "425261", d6=1 → UPC-A "04210000526" + check.
        $enc = new UpcEEncoder('425261');
        self::assertSame('04210000526', $enc->upcA);
        self::assertSame(4, (int) $enc->canonical[7]);
        self::assertSame('04252614', $enc->canonical);
    }

    #[Test]
    public function expansion_d6_3_replaces_d3_position_with_5_zeros(): void
    {
        // body "ABC3DE", e.g. "123345": d6=3 → "0123300045".
        // Test simpler: "100003" → NSD 0, D1=1,D2=0,D3=0,D4=0,D5=0,D6=3
        // → "0" + "1" + "0" + "0" + "00000" + "0" + "0" = "01000000000".
        $enc = new UpcEEncoder('100003');
        self::assertSame('01000000000', $enc->upcA);
    }

    #[Test]
    public function expansion_d6_4_inserts_5_zeros_before_d5(): void
    {
        // Для D6=4 формула: NSD D1 D2 D3 D4 + "00000" + D5.
        // body "123454" → "0" "1" "2" "3" "4" "00000" "5" = "01234000005".
        $enc = new UpcEEncoder('123454');
        self::assertSame('01234000005', $enc->upcA);
    }

    #[Test]
    public function expansion_d6_ge_5_appends_four_zeros_then_d6(): void
    {
        // body "123456", d6=6 → "01234500006".
        $enc = new UpcEEncoder('123456');
        self::assertSame('01234500006', $enc->upcA);
        self::assertSame(5, (int) $enc->canonical[7]);
        self::assertSame('01234565', $enc->canonical);
    }

    #[Test]
    public function computes_check_via_static_helper(): void
    {
        // body "425261", NSD=0 → check 4.
        self::assertSame(4, UpcEEncoder::computeCheckDigit('425261'));
        // body "123456", NSD=0 → check 5.
        self::assertSame(5, UpcEEncoder::computeCheckDigit('123456'));
    }

    #[Test]
    public function accepts_6_digit_body_prepends_nsd_appends_check(): void
    {
        $enc = new UpcEEncoder('425261');
        self::assertSame('04252614', $enc->canonical);
        self::assertSame(0, $enc->numberSystem);
    }

    #[Test]
    public function accepts_7_digit_input_with_nsd(): void
    {
        // "0425261" — NSD + 6 body, no check.
        $enc = new UpcEEncoder('0425261');
        self::assertSame('04252614', $enc->canonical);
    }

    #[Test]
    public function accepts_8_digit_full_canonical(): void
    {
        $enc = new UpcEEncoder('04252614');
        self::assertSame('04252614', $enc->canonical);
        self::assertSame(0, $enc->numberSystem);
    }

    #[Test]
    public function rejects_8_digit_with_wrong_check(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcEEncoder('04252613');
    }

    #[Test]
    public function rejects_invalid_nsd_in_7_digit_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcEEncoder('2425261'); // NSD=2 not allowed
    }

    #[Test]
    public function rejects_invalid_nsd_param(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcEEncoder('425261', numberSystem: 2);
    }

    #[Test]
    public function rejects_non_digit_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcEEncoder('42526a');
    }

    #[Test]
    public function rejects_wrong_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcEEncoder('12345');
    }

    #[Test]
    public function check_digit_helper_rejects_wrong_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpcEEncoder::computeCheckDigit('12345');
    }

    #[Test]
    public function check_digit_helper_rejects_invalid_nsd(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpcEEncoder::computeCheckDigit('425261', numberSystem: 5);
    }

    #[Test]
    public function module_count_is_51(): void
    {
        $enc = new UpcEEncoder('425261');
        // 3 (start) + 6*7 (digits) + 6 (end "010101") = 51.
        self::assertSame(51, $enc->moduleCount());
    }

    #[Test]
    public function quiet_zone_padding(): void
    {
        $enc = new UpcEEncoder('425261');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(51 + 18, $padded);
    }

    #[Test]
    public function start_guard_is_101(): void
    {
        $enc = new UpcEEncoder('425261');
        $m = $enc->modules();
        self::assertTrue($m[0]);
        self::assertFalse($m[1]);
        self::assertTrue($m[2]);
    }

    #[Test]
    public function end_guard_is_010101(): void
    {
        // Last 6 modules.
        $enc = new UpcEEncoder('425261');
        $m = $enc->modules();
        $n = count($m);
        $expected = [false, true, false, true, false, true];
        for ($i = 0; $i < 6; $i++) {
            self::assertSame($expected[$i], $m[$n - 6 + $i], "end guard module $i");
        }
    }

    #[Test]
    public function nsd_1_inverts_parity_pattern(): void
    {
        // Same body different NSD → different module sequence.
        $nsd0 = new UpcEEncoder('425261', numberSystem: 0);
        $nsd1 = new UpcEEncoder('425261', numberSystem: 1);
        self::assertSame(0, $nsd0->numberSystem);
        self::assertSame(1, $nsd1->numberSystem);
        // Modules must differ (parity pattern swapped).
        self::assertNotSame(
            $nsd0->modules(),
            $nsd1->modules(),
            'NSD=0 vs NSD=1 must produce different patterns',
        );
    }

    #[Test]
    public function upc_a_check_digit_known_example(): void
    {
        // Known UPC-A: 042100005264 — check digit 4.
        // Test via UPC-E expansion: body "425261" expands to "04210000526"
        // and computeCheckDigit returns 4.
        self::assertSame(4, UpcEEncoder::computeCheckDigit('425261'));
    }

    #[Test]
    public function upce_renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('425261', BarcodeFormat::UpcE, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Caption = canonical 8-digit form.
        self::assertStringContainsString('(04252614) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function upce_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::UpcE->is2D());
        self::assertFalse(BarcodeFormat::UpcE->isStacked());
    }
}
