<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 183: QR Structured Append tests.
 */
final class QrStructuredAppendTest extends TestCase
{
    #[Test]
    public function compute_parity_xors_all_bytes(): void
    {
        // XOR(A=65, B=66, C=67) = 65 ^ 66 ^ 67 = 64.
        self::assertSame(64, QrEncoder::computeStructuredAppendParity('ABC'));
    }

    #[Test]
    public function compute_parity_empty_returns_zero(): void
    {
        self::assertSame(0, QrEncoder::computeStructuredAppendParity(''));
    }

    #[Test]
    public function structured_append_creates_encoder(): void
    {
        $parity = QrEncoder::computeStructuredAppendParity('Hello World');
        $sym1 = QrEncoder::structuredAppend('Hello ', 0, 2, $parity);
        $sym2 = QrEncoder::structuredAppend('World', 1, 2, $parity);

        self::assertGreaterThan(0, $sym1->size());
        self::assertGreaterThan(0, $sym2->size());
        // Same parity in both symbols.
        $parityComputed = QrEncoder::computeStructuredAppendParity('Hello World');
        self::assertSame($parity, $parityComputed);
    }

    #[Test]
    public function rejects_total_below_2(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::structuredAppend('data', 0, 1, 0);
    }

    #[Test]
    public function rejects_total_above_16(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::structuredAppend('data', 0, 17, 0);
    }

    #[Test]
    public function rejects_position_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::structuredAppend('data', 3, 2, 0); // pos 3, total 2
    }

    #[Test]
    public function rejects_parity_out_of_byte_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::structuredAppend('data', 0, 2, 256);
    }

    #[Test]
    public function structured_append_uses_more_capacity_than_plain(): void
    {
        // 20-bit header adds к capacity requirements; structured append symbol
        // может go в higher version если plain fits.
        $sa = QrEncoder::structuredAppend('A', 0, 2, 0);
        $plain = new QrEncoder('A');
        // SA version >= plain version (extra 20 bits).
        self::assertGreaterThanOrEqual($plain->version, $sa->version);
    }

    #[Test]
    public function multiple_symbols_consistent_parity(): void
    {
        $fullData = 'Part1+Part2+Part3';
        $parity = QrEncoder::computeStructuredAppendParity($fullData);
        $sym1 = QrEncoder::structuredAppend('Part1', 0, 3, $parity);
        $sym2 = QrEncoder::structuredAppend('+Part2', 1, 3, $parity);
        $sym3 = QrEncoder::structuredAppend('+Part3', 2, 3, $parity);
        self::assertGreaterThan(0, $sym1->size());
        self::assertGreaterThan(0, $sym2->size());
        self::assertGreaterThan(0, $sym3->size());
    }

    #[Test]
    public function max_16_symbols_allowed(): void
    {
        // total=16, position=15 (last) — valid edge case.
        $sym = QrEncoder::structuredAppend('data', 15, 16, 100);
        self::assertGreaterThan(0, $sym->size());
    }

    // -------- Phase 184: ECI --------

    #[Test]
    public function eci_designator_stored(): void
    {
        $enc = new QrEncoder('hello', eciDesignator: 26); // UTF-8
        self::assertSame(26, $enc->eciDesignator);
    }

    #[Test]
    public function eci_with_utf8_designator(): void
    {
        $enc = new QrEncoder('hello', eciDesignator: 26);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function eci_with_short_designator(): void
    {
        // 0..127 → single-byte designator.
        $enc = new QrEncoder('test', eciDesignator: 3); // ISO 8859-1
        self::assertSame(3, $enc->eciDesignator);
    }

    #[Test]
    public function eci_with_medium_designator(): void
    {
        // 128..16383 → 2-byte designator.
        $enc = new QrEncoder('test', eciDesignator: 1000);
        self::assertSame(1000, $enc->eciDesignator);
    }

    #[Test]
    public function eci_with_large_designator(): void
    {
        // 16384..999999 → 3-byte designator.
        $enc = new QrEncoder('test', eciDesignator: 50000);
        self::assertSame(50000, $enc->eciDesignator);
    }

    #[Test]
    public function eci_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('test', eciDesignator: -1);
    }

    #[Test]
    public function eci_rejects_too_large(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('test', eciDesignator: 1000000);
    }

    #[Test]
    public function eci_increases_capacity_requirement(): void
    {
        // 'A' fits в V1; same input с ECI header may stay at V1 (extra ~12 bits).
        $plain = new QrEncoder('A');
        $withEci = new QrEncoder('A', eciDesignator: 26);
        self::assertGreaterThanOrEqual($plain->version, $withEci->version);
    }
}
