<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\AztecEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 125: Aztec Compact encoder structural tests.
 *
 * Verifies bullseye finder, matrix sizes, RS encoding, character mode
 * tables. Real-world decode requires external scanner verification
 * (см. AztecEncoder PHPDoc — experimental status).
 */
final class AztecEncoderTest extends TestCase
{
    #[Test]
    public function rejects_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AztecEncoder('');
    }

    #[Test]
    public function compact_size_per_layer(): void
    {
        $cases = [
            'A' => 15,         // 1L = 15×15
            str_repeat('A', 20) => 19, // 2L = 19×19
            str_repeat('A', 50) => 23, // 3L = 23×23
            str_repeat('A', 80) => 27, // 4L = 27×27
        ];
        foreach ($cases as $data => $expectedSize) {
            $enc = new AztecEncoder($data);
            self::assertSame($expectedSize, $enc->matrixSize(), "for data length ".strlen($data));
            self::assertSame($expectedSize, count($enc->modules()));
            self::assertSame($expectedSize, count($enc->modules()[0]));
        }
    }

    #[Test]
    public function bullseye_finder_pattern_correct(): void
    {
        $enc = new AztecEncoder('HELLO');
        $m = $enc->modules();
        $center = intdiv($enc->matrixSize(), 2);
        // Chebyshev distance pattern: distance 0,2,4 → black; 1,3 → white.
        for ($r = $center - 4; $r <= $center + 4; $r++) {
            for ($c = $center - 4; $c <= $center + 4; $c++) {
                $d = max(abs($r - $center), abs($c - $center));
                $expected = ($d % 2 === 0);
                self::assertSame($expected, $m[$r][$c],
                    "cell ($r,$c) distance=$d");
            }
        }
    }

    #[Test]
    public function layers_grow_with_data_size(): void
    {
        $short = new AztecEncoder('A');
        $longer = new AztecEncoder(str_repeat('LONGDATAVALUE', 5));
        self::assertGreaterThanOrEqual($short->layers, $longer->layers);
    }

    #[Test]
    public function long_data_promotes_to_full(): void
    {
        // 500 X's overflows Compact (max 4L = 27×27, ~76 codewords) → Full Aztec.
        $enc = new AztecEncoder(str_repeat('X', 500));
        self::assertFalse($enc->compact);
        self::assertGreaterThanOrEqual(5, $enc->layers);
    }

    #[Test]
    public function very_long_data_overflows_full(): void
    {
        // Beyond max Full Aztec (32 layers, 151×151, ~3000 codewords).
        $this->expectException(\InvalidArgumentException::class);
        new AztecEncoder(str_repeat('X', 4000));
    }

    #[Test]
    public function data_codewords_plus_ecc_fit_capacity(): void
    {
        $enc = new AztecEncoder('Hello World 12345');
        $cwBits = $enc->layers <= 2 ? 6 : 8;
        $capacityBits = match ($enc->layers) {
            1 => 102,
            2 => 240,
            3 => 408,
            4 => 608,
        };
        $totalCw = intdiv($capacityBits, $cwBits);
        self::assertSame($totalCw, $enc->dataCodewords + $enc->eccCodewords);
    }

    #[Test]
    public function bit_stuffing_no_all_zero_codeword(): void
    {
        // Construct a bit string with 6 consecutive zeros — должно быть
        // bit-stuffed для GF(64).
        $stuffed = AztecEncoder::stuffBits('000000111111', 6);
        // First codeword (6 bits) cannot be all-zero after stuffing.
        $cw0 = substr($stuffed, 0, 6);
        self::assertNotSame('000000', $cw0);
        $cw1 = substr($stuffed, 6, 6);
        self::assertNotSame('111111', $cw1);
    }

    #[Test]
    public function bit_stuffing_preserves_safe_codewords(): void
    {
        // Bits forming codeword "010101" должны pass через unchanged.
        $stuffed = AztecEncoder::stuffBits('010101', 6);
        self::assertSame('010101', $stuffed);
    }

    #[Test]
    public function reed_solomon_gf64_known_output(): void
    {
        // Empty data + 3 ECC codewords over GF(64) → known zero output.
        $ecc = AztecEncoder::reedSolomonEncode([], 3, 6, 0x43);
        self::assertCount(3, $ecc);
        self::assertSame([0, 0, 0], $ecc);
    }

    #[Test]
    public function reed_solomon_deterministic(): void
    {
        $a = AztecEncoder::reedSolomonEncode([1, 2, 3, 4, 5], 5, 6, 0x43);
        $b = AztecEncoder::reedSolomonEncode([1, 2, 3, 4, 5], 5, 6, 0x43);
        self::assertSame($a, $b);
    }

    #[Test]
    public function reed_solomon_gf16_mode_message(): void
    {
        // Mode message uses GF(16) with x^4+x+1 prim poly.
        $ecc = AztecEncoder::reedSolomonEncode([5, 10], 5, 4, 0x13);
        self::assertCount(5, $ecc);
        foreach ($ecc as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(16, $c);
        }
    }

    #[Test]
    public function character_encoding_uses_upper_mode_for_uppercase(): void
    {
        // 'A' = code 2 in Upper mode (5 bits).
        $bits = AztecEncoder::encodeToBits('A');
        // First codeword should be 'A' = 00010 (5 bits).
        self::assertSame('00010', substr($bits, 0, 5));
    }

    #[Test]
    public function character_encoding_uses_digit_mode_with_latch(): void
    {
        // '5' = code 7 in Digit mode (4 bits), preceded by D/L=30 (5 bits).
        $bits = AztecEncoder::encodeToBits('5');
        // 30 in 5 bits = 11110.
        self::assertSame('11110', substr($bits, 0, 5));
        // '5' = code 7 in 4 bits = 0111.
        self::assertSame('0111', substr($bits, 5, 4));
    }

    #[Test]
    public function space_encodable_in_all_modes(): void
    {
        // Space in current mode (Upper) = code 1 (5 bits) = 00001.
        $bits = AztecEncoder::encodeToBits(' ');
        self::assertSame('00001', $bits);
    }

    #[Test]
    public function matrix_size_matches_layer_formula(): void
    {
        foreach (['A', 'ABCDE', 'HELLO WORLD'] as $data) {
            $enc = new AztecEncoder($data);
            $expectedSize = 11 + 4 * $enc->layers;
            self::assertSame($expectedSize, $enc->matrixSize());
        }
    }

    #[Test]
    public function consistent_output_for_same_input(): void
    {
        $a = (new AztecEncoder('HELLO'))->modules();
        $b = (new AztecEncoder('HELLO'))->modules();
        self::assertSame($a, $b);
    }
}
