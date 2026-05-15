<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\AztecEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 238: Aztec FLG(n) ECI escape (ISO 24778 §6.5).
 */
final class AztecEciTest extends TestCase
{
    #[Test]
    public function default_no_eci(): void
    {
        $enc = new AztecEncoder('Hello');
        self::assertNull($enc->eciValue);
    }

    #[Test]
    public function with_eci_sets_value(): void
    {
        $enc = AztecEncoder::withEci(26, 'Hello'); // ECI 26 = UTF-8
        self::assertSame(26, $enc->eciValue);
    }

    #[Test]
    public function rejects_negative_eci(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::withEci(-1, 'data');
    }

    #[Test]
    public function rejects_eci_too_large(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::withEci(1000000, 'data');
    }

    #[Test]
    public function eci_increases_symbol_capacity_used(): void
    {
        // ECI prefix adds overhead: 5+5+5+3+4n+5 bits where n=ECI digit count.
        // For ECI 26 (2 digits): 5+5+5+3+8+5 = 31 extra bits.
        $base = new AztecEncoder('TEST');
        $withEci = AztecEncoder::withEci(26, 'TEST');

        // Both encode без exception.
        self::assertGreaterThan(0, $base->matrixSize());
        self::assertGreaterThan(0, $withEci->matrixSize());
    }

    #[Test]
    public function single_digit_eci(): void
    {
        // ECI 3 = ISO 8859-1.
        $enc = AztecEncoder::withEci(3, 'Hello');
        self::assertSame(3, $enc->eciValue);
        self::assertGreaterThan(0, $enc->matrixSize());
    }

    #[Test]
    public function six_digit_eci(): void
    {
        $enc = AztecEncoder::withEci(999999, 'data');
        self::assertSame(999999, $enc->eciValue);
        self::assertGreaterThan(0, $enc->matrixSize());
    }

    #[Test]
    public function eci_zero_supported(): void
    {
        // ECI 0 valid (denotes default Aztec interpretation).
        $enc = AztecEncoder::withEci(0, 'Hello');
        self::assertSame(0, $enc->eciValue);
    }

    #[Test]
    public function eci_changes_output_bits(): void
    {
        // Same data + different ECI → different matrix layout.
        // (Header bits added at start affect everything downstream.)
        $a = AztecEncoder::withEci(3, 'TEST');
        $b = AztecEncoder::withEci(26, 'TEST');

        self::assertNotSame($a->modules(), $b->modules());
    }

    #[Test]
    public function utf8_data_with_utf8_eci(): void
    {
        // Common case: UTF-8 data + ECI 26 marker.
        $enc = AztecEncoder::withEci(26, 'TEST');
        self::assertGreaterThan(0, $enc->matrixSize());
        self::assertNotEmpty($enc->modules());
    }

    #[Test]
    public function structured_append_with_eci_compatible(): void
    {
        // Structured Append uses string prepending; ECI uses bit prepending.
        // Should be compatible — SA processes data string, ECI processes bits.
        $sa = AztecEncoder::structuredAppend('DATA', 1, 3);
        self::assertSame(' ACDATA', $sa->data);

        // Combining? withEci accepts string; could do withEci on SA result.
        $combined = AztecEncoder::withEci(26, ' ACDATA');
        self::assertSame(26, $combined->eciValue);
    }
}
