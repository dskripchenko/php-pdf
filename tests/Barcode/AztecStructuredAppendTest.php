<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\AztecEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 221: Aztec Structured Append (ISO 24778 §8.4) tests.
 */
final class AztecStructuredAppendTest extends TestCase
{
    #[Test]
    public function single_symbol_no_file_id(): void
    {
        $enc = AztecEncoder::structuredAppend('Hello', 1, 1);
        // Header " AA" + data.
        self::assertSame(' AAHello', $enc->data);
    }

    #[Test]
    public function three_symbol_set_position_letters(): void
    {
        $sym1 = AztecEncoder::structuredAppend('first', 1, 3);
        $sym2 = AztecEncoder::structuredAppend('second', 2, 3);
        $sym3 = AztecEncoder::structuredAppend('third', 3, 3);

        // Position letters A/B/C, count letter C (total 3).
        self::assertSame(' ACfirst', $sym1->data);
        self::assertSame(' BCsecond', $sym2->data);
        self::assertSame(' CCthird', $sym3->data);
    }

    #[Test]
    public function full_set_26_symbols(): void
    {
        $sym26 = AztecEncoder::structuredAppend('last', 26, 26);
        // Position Z, count Z.
        self::assertSame(' ZZlast', $sym26->data);
    }

    #[Test]
    public function with_file_id(): void
    {
        $enc = AztecEncoder::structuredAppend('data', 1, 2, fileId: 'INVOICE');
        // Format: "INVOICE AB<data>"
        self::assertSame('INVOICE ABdata', $enc->data);
    }

    #[Test]
    public function file_id_alphanumeric_allowed(): void
    {
        $enc = AztecEncoder::structuredAppend('x', 1, 1, fileId: 'ABC123');
        self::assertSame('ABC123 AAx', $enc->data);
    }

    #[Test]
    public function rejects_position_out_of_range_low(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 0, 5);
    }

    #[Test]
    public function rejects_position_out_of_range_high(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 27, 27);
    }

    #[Test]
    public function rejects_total_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 1, 0);
    }

    #[Test]
    public function rejects_position_greater_than_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 5, 3);
    }

    #[Test]
    public function rejects_file_id_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 1, 1, fileId: str_repeat('A', 25));
    }

    #[Test]
    public function rejects_file_id_with_space(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 1, 1, fileId: 'BAD ID');
    }

    #[Test]
    public function rejects_file_id_lowercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AztecEncoder::structuredAppend('x', 1, 1, fileId: 'lowercase');
    }

    #[Test]
    public function symbol_encodes_к_valid_aztec_matrix(): void
    {
        // Verify resulting symbol actually encodes correctly.
        $enc = AztecEncoder::structuredAppend('TEST', 1, 3);
        self::assertGreaterThan(0, $enc->matrixSize());
        self::assertNotEmpty($enc->modules());
    }

    #[Test]
    public function reconstructed_message_concatenates(): void
    {
        // Document-level use case: 3 symbols carrying parts of "HELLO WORLD".
        $sym1 = AztecEncoder::structuredAppend('HEL', 1, 3);
        $sym2 = AztecEncoder::structuredAppend('LO ', 2, 3);
        $sym3 = AztecEncoder::structuredAppend('WORLD', 3, 3);

        // Decoder would strip headers и concatenate data parts.
        // Verify each symbol carries correct header + payload.
        self::assertStringStartsWith(' AC', $sym1->data);
        self::assertStringStartsWith(' BC', $sym2->data);
        self::assertStringStartsWith(' CC', $sym3->data);
        self::assertStringEndsWith('HEL', $sym1->data);
        self::assertStringEndsWith('LO ', $sym2->data);
        self::assertStringEndsWith('WORLD', $sym3->data);
    }
}
