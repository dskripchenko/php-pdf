<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\CodabarEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 204: Codabar (NW-7 / USS Codabar) barcode encoder tests.
 */
final class CodabarEncoderTest extends TestCase
{
    #[Test]
    public function encodes_single_digit_with_default_start_stop(): void
    {
        $enc = new CodabarEncoder('0');
        // "A0A": A(10) + gap(1) + 0(9) + gap(1) + A(10) = 31 modules.
        self::assertSame(31, $enc->moduleCount());
        self::assertSame('A0A', $enc->canonical);
    }

    #[Test]
    public function encodes_multi_digit_input(): void
    {
        $enc = new CodabarEncoder('123');
        // "A123A": A(10) + 1(9) + 2(9) + 3(9) + A(10) + 4 gaps = 51 modules.
        self::assertSame(51, $enc->moduleCount());
        self::assertSame('A123A', $enc->canonical);
    }

    #[Test]
    public function uses_custom_start_stop(): void
    {
        $enc = new CodabarEncoder('1234', start: 'B', stop: 'D');
        self::assertSame('B1234D', $enc->canonical);
    }

    #[Test]
    public function auto_uppercases_start_stop(): void
    {
        $enc = new CodabarEncoder('1', start: 'b', stop: 'c');
        self::assertSame('B1C', $enc->canonical);
    }

    #[Test]
    public function rejects_invalid_start_char(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodabarEncoder('1', start: 'E');
    }

    #[Test]
    public function rejects_invalid_stop_char(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodabarEncoder('1', stop: '1');
    }

    #[Test]
    public function rejects_empty_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodabarEncoder('');
    }

    #[Test]
    public function rejects_start_stop_char_in_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodabarEncoder('12A34');
    }

    #[Test]
    public function rejects_unsupported_character(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodabarEncoder('hello'); // letters не в data set
    }

    #[Test]
    public function accepts_all_punctuation_chars(): void
    {
        // All 6 punctuation chars: - $ : / . +
        $enc = new CodabarEncoder('-$:/.+');
        self::assertSame('A-$:/.+A', $enc->canonical);
    }

    #[Test]
    public function digit_zero_module_pattern(): void
    {
        // '0' pattern = '0000011' (W/N).
        // Element widths (2:1): 1,1,1,1,1,2,2.
        // Bars at positions 0,2,4,6; spaces at 1,3,5.
        // bits = "1" + "0" + "1" + "0" + "1" + "00" + "11" = "101010011" (9 mod).
        $enc = new CodabarEncoder('0');
        $modules = $enc->modules();
        // '0' starts after A (10 mod) + gap (1) = offset 11.
        $expected = '101010011';
        $actual = '';
        for ($i = 0; $i < 9; $i++) {
            $actual .= $modules[11 + $i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function start_char_a_module_pattern(): void
    {
        // 'A' pattern = '0011010' (W/N).
        // Bars: positions 0,2,4,6; spaces: 1,3,5.
        // pos 0 bar  narrow  → '1'
        // pos 1 spc  narrow  → '0'
        // pos 2 bar  wide    → '11'
        // pos 3 spc  wide    → '00'
        // pos 4 bar  narrow  → '1'
        // pos 5 spc  wide    → '00'
        // pos 6 bar  narrow  → '1'
        // = "10" + "1100" + "1001" = "1011001001" (10 mod).
        $enc = new CodabarEncoder('0');
        $modules = $enc->modules();
        $expected = '1011001001';
        $actual = '';
        for ($i = 0; $i < 10; $i++) {
            $actual .= $modules[$i] ? '1' : '0';
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function inter_character_gap_present(): void
    {
        $enc = new CodabarEncoder('1');
        $modules = $enc->modules();
        // After 'A' (10 mod) → gap module at offset 10.
        self::assertFalse($modules[10]);
        // After '1' (9 mod) → gap at offset 20.
        self::assertFalse($modules[20]);
    }

    #[Test]
    public function quiet_zone_default_10(): void
    {
        $enc = new CodabarEncoder('1');
        $padded = $enc->modulesWithQuietZone();
        // A(10) + gap + 1(9) + gap + A(10) = 31 mod.
        self::assertCount(31 + 20, $padded);
    }

    #[Test]
    public function quiet_zone_custom(): void
    {
        $enc = new CodabarEncoder('1');
        $padded = $enc->modulesWithQuietZone(20);
        self::assertCount(31 + 40, $padded);
    }

    #[Test]
    public function lowercase_data_auto_uppercased(): void
    {
        // Even though Codabar data has no letters by default, this tests the
        // strtoupper path doesn't break punctuation/digits.
        $enc = new CodabarEncoder('1.2');
        self::assertSame('A1.2A', $enc->canonical);
    }

    #[Test]
    public function all_four_start_stop_options(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $delim) {
            $enc = new CodabarEncoder('1', start: $delim, stop: $delim);
            self::assertSame("{$delim}1{$delim}", $enc->canonical);
        }
    }

    #[Test]
    public function start_b_stop_d_different_patterns_from_a_a(): void
    {
        // B and D have different patterns from A, so module sequences differ.
        $aA = new CodabarEncoder('1');
        $bD = new CodabarEncoder('1', start: 'B', stop: 'D');
        self::assertNotSame($aA->modules(), $bD->modules());
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('1234567', BarcodeFormat::Codabar, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(A1234567A) Tj', $bytes);
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function codabar_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Codabar->is2D());
        self::assertFalse(BarcodeFormat::Codabar->isStacked());
    }

    #[Test]
    public function colon_char_three_wide_elements(): void
    {
        // ':' pattern = '1000101' — 3 wide → 10 modules.
        // 'A:A' = 10 + gap + 10 + gap + 10 = 32 modules.
        $enc = new CodabarEncoder(':');
        self::assertSame(32, $enc->moduleCount());
    }
}
