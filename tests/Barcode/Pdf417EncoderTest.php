<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Pdf417Encoder;
use Dskripchenko\PhpPdf\Barcode\Pdf417Patterns;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 124: PDF417 encoder structural correctness tests.
 *
 * Pattern table values are facts из ISO/IEC 15438:2006 Annex 3 — verified
 * by independent extraction from public reference data. RS arithmetic
 * over GF(929) — verified algorithmically.
 */
final class Pdf417EncoderTest extends TestCase
{
    #[Test]
    public function pattern_table_has_2787_unique_17bit_patterns(): void
    {
        $all = [];
        foreach (Pdf417Patterns::PATTERNS as $cluster) {
            self::assertCount(929, $cluster, '929 codewords per cluster');
            foreach ($cluster as $p) {
                self::assertGreaterThanOrEqual(0, $p);
                self::assertLessThanOrEqual(0x1FFFF, $p, '17-bit max');
                $all[] = $p;
            }
        }
        self::assertCount(2787, $all);
        self::assertCount(2787, array_unique($all), 'all patterns unique');
    }

    #[Test]
    public function each_pattern_has_4_bars_4_spaces_widths_1_to_6(): void
    {
        foreach (Pdf417Patterns::PATTERNS as $cluster) {
            foreach ($cluster as $p) {
                $bits = [];
                for ($i = 16; $i >= 0; $i--) {
                    $bits[] = ($p >> $i) & 1;
                }
                self::assertSame(1, $bits[0], 'starts with bar');
                self::assertSame(0, $bits[16], 'ends with space');
                // Run-length encode.
                $runs = [];
                $cur = $bits[0];
                $len = 1;
                for ($i = 1; $i < 17; $i++) {
                    if ($bits[$i] === $cur) {
                        $len++;
                    } else {
                        $runs[] = $len;
                        $cur = $bits[$i];
                        $len = 1;
                    }
                }
                $runs[] = $len;
                self::assertCount(8, $runs);
                foreach ($runs as $r) {
                    self::assertGreaterThanOrEqual(1, $r);
                    self::assertLessThanOrEqual(6, $r);
                }
                self::assertSame(17, array_sum($runs));
            }
        }
    }

    #[Test]
    public function rs_factor_tables_correct_count(): void
    {
        foreach (range(0, 8) as $ecl) {
            $expected = 2 << $ecl;
            self::assertCount($expected, Pdf417Patterns::RS_FACTORS[$ecl]);
            foreach (Pdf417Patterns::RS_FACTORS[$ecl] as $f) {
                self::assertLessThan(929, $f, 'factor < 929 (GF(929))');
                self::assertGreaterThanOrEqual(0, $f);
            }
        }
    }

    #[Test]
    public function start_and_stop_constants(): void
    {
        self::assertSame(0x1FEA8, Pdf417Patterns::START_PATTERN);
        self::assertSame(0x3FA29, Pdf417Patterns::STOP_PATTERN);
        self::assertSame(17, Pdf417Patterns::START_PATTERN_BITS);
        self::assertSame(18, Pdf417Patterns::STOP_PATTERN_BITS);
    }

    #[Test]
    public function encode_short_string(): void
    {
        $enc = new Pdf417Encoder('Hello');
        self::assertGreaterThanOrEqual(3, $enc->rows);
        self::assertLessThanOrEqual(90, $enc->rows);
        self::assertGreaterThanOrEqual(1, $enc->cols);
        self::assertLessThanOrEqual(30, $enc->cols);
        self::assertSame(count($enc->codewords), $enc->rows * $enc->cols);
    }

    #[Test]
    public function encode_includes_start_and_stop_patterns(): void
    {
        $enc = new Pdf417Encoder('Test');
        $matrix = $enc->modules();

        // First 17 bits = START pattern (1FEA8 binary 11111111010101000)
        $firstRow = $matrix[0];
        $startBits = '';
        for ($i = 0; $i < 17; $i++) {
            $startBits .= $firstRow[$i] ? '1' : '0';
        }
        self::assertSame('11111111010101000', $startBits);

        // Last 18 bits = STOP pattern (3FA29 binary 111111101000101001)
        $rowLen = count($firstRow);
        $stopBits = '';
        for ($i = $rowLen - 18; $i < $rowLen; $i++) {
            $stopBits .= $firstRow[$i] ? '1' : '0';
        }
        self::assertSame('111111101000101001', $stopBits);
    }

    #[Test]
    public function multi_byte_compaction_for_multiple_of_6(): void
    {
        // 6 bytes → 924 latch + 5 codewords.
        $cw = Pdf417Encoder::byteCompaction('abcdef');
        self::assertSame(924, $cw[0]);
        self::assertCount(6, $cw); // 1 latch + 5 codewords
    }

    #[Test]
    public function single_byte_compaction_for_non_multiple_of_6(): void
    {
        // 5 bytes → 901 latch + 5 codewords.
        $cw = Pdf417Encoder::byteCompaction('hello');
        self::assertSame(901, $cw[0]);
        self::assertCount(6, $cw); // 1 latch + 5 codewords
        // Each byte becomes its own codeword.
        self::assertSame(ord('h'), $cw[1]);
        self::assertSame(ord('o'), $cw[5]);
    }

    #[Test]
    public function reed_solomon_appends_expected_codeword_count(): void
    {
        $data = [10, 100, 200, 300, 400];
        $eccLevel = 3; // 16 ECC codewords.
        $ecc = Pdf417Encoder::reedSolomonEncode($data, $eccLevel);
        self::assertCount(16, $ecc);
        foreach ($ecc as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(929, $c, 'codewords ∈ GF(929)');
        }
    }

    #[Test]
    public function encode_handles_binary_data(): void
    {
        $enc = new Pdf417Encoder("\x00\x01\xFF\xFE\x80\x7F");
        self::assertGreaterThan(0, count($enc->modules()));
    }

    #[Test]
    public function encode_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pdf417Encoder('');
    }

    #[Test]
    public function ecc_level_explicit_override(): void
    {
        $enc = new Pdf417Encoder('Short', eccLevel: 0);
        self::assertSame(0, $enc->eccLevel);
        // ECL 0 = 2 ECC codewords.
        $enc2 = new Pdf417Encoder('Short', eccLevel: 8);
        self::assertSame(8, $enc2->eccLevel);
        // Higher ECL → больше overall codewords.
        self::assertGreaterThan(count($enc->codewords), count($enc2->codewords));
    }

    #[Test]
    public function ecc_level_invalid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pdf417Encoder('x', eccLevel: 9);
    }

    #[Test]
    public function long_data_chooses_more_columns(): void
    {
        $short = new Pdf417Encoder('Hi');
        $long = new Pdf417Encoder(str_repeat('A', 200));
        // Long data → wider matrix.
        self::assertGreaterThan($short->cols, $long->cols);
    }

    #[Test]
    public function symbol_length_descriptor_is_first_codeword(): void
    {
        $enc = new Pdf417Encoder('Data');
        $eccSize = 2 << $enc->eccLevel;
        $expectedSld = $enc->rows * $enc->cols - $eccSize;
        self::assertSame($expectedSld, $enc->codewords[0]);
    }

    #[Test]
    public function matrix_row_width_matches_expected_formula(): void
    {
        $enc = new Pdf417Encoder('Hello');
        $matrix = $enc->modules();
        // Width = 17 (start) + 17 (left RI) + cols*17 (data) + 17 (right RI) + 18 (stop)
        $expected = 17 + 17 + $enc->cols * 17 + 17 + 18;
        self::assertSame($expected, count($matrix[0]));
    }

    #[Test]
    public function rs_encode_deterministic(): void
    {
        $a = Pdf417Encoder::reedSolomonEncode([1, 2, 3, 4], 2);
        $b = Pdf417Encoder::reedSolomonEncode([1, 2, 3, 4], 2);
        self::assertSame($a, $b);
    }

    #[Test]
    public function rs_factors_first_known_values(): void
    {
        // Spot-check known values из ISO/IEC 15438 Annex F: ECL 0 = {27, 917}.
        self::assertSame(27, Pdf417Patterns::RS_FACTORS[0][0]);
        self::assertSame(917, Pdf417Patterns::RS_FACTORS[0][1]);
    }
}
