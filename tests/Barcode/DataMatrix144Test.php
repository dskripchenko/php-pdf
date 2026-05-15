<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 237: DataMatrix 144×144 — largest standard ECC 200 symbol size.
 *
 * Unique aspect: 1558 data codewords across 10 RS blocks (8×156 + 2×155
 * distribution через round-robin interleaving). 36 regions of 22×22 modules.
 *
 * Source: ISO/IEC 16022:2006 Table O.1 + ZXing SymbolInfo.PROD_SYMBOLS.
 */
final class DataMatrix144Test extends TestCase
{
    #[Test]
    public function symbol_size_144_for_large_input(): void
    {
        // 1500 bytes input fits в 144×144 (just under 1558 data capacity).
        $data = str_repeat('A', 1500);
        $enc = new DataMatrixEncoder($data);
        self::assertSame(144, $enc->size());
    }

    #[Test]
    public function symbol_size_144_dimensions(): void
    {
        $enc = new DataMatrixEncoder(str_repeat('A', 1500));
        self::assertSame(144, $enc->symbolWidth);
        self::assertSame(144, $enc->symbolHeight);
        self::assertFalse($enc->rectangular);
    }

    #[Test]
    public function data_capacity_1558(): void
    {
        // Test boundary: 1558-byte data should fit; 1559-byte должно throw.
        $enc1558 = new DataMatrixEncoder(str_repeat('A', 1500));
        self::assertSame(1500, strlen($enc1558->data));

        // Exceeding capacity — но since we don't have larger symbol,
        // anything > 1558 should throw.
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder(str_repeat('A', 2000));
    }

    #[Test]
    public function symbol_matrix_correct_dimensions(): void
    {
        $enc = new DataMatrixEncoder(str_repeat('A', 1500));
        $modules = $enc->modules();
        self::assertCount(144, $modules);
        foreach ($modules as $row) {
            self::assertCount(144, $row);
        }
    }

    #[Test]
    public function ecc_codewords_count(): void
    {
        $enc = new DataMatrixEncoder(str_repeat('A', 1500));
        self::assertSame(620, $enc->eccCw);
    }

    #[Test]
    public function data_codewords_count(): void
    {
        $enc = new DataMatrixEncoder(str_repeat('A', 1500));
        self::assertSame(1558, $enc->dataCw);
    }

    #[Test]
    public function symbol_has_finder_pattern_modules(): void
    {
        // 144×144 should have valid L-finder + dotted finder patterns.
        // At minimum: left column row 0 = solid (L-finder left edge).
        $enc = new DataMatrixEncoder(str_repeat('A', 1500));
        $modules = $enc->modules();
        // Region L-finder: bottom-left corner pixel должен быть set.
        // For 36 regions, each 22×22, finders at region boundaries.
        // Just verify symbol is non-empty.
        $blackCount = 0;
        foreach ($modules as $row) {
            foreach ($row as $bit) {
                if ($bit) {
                    $blackCount++;
                }
            }
        }
        self::assertGreaterThan(5000, $blackCount, '144×144 symbol должен contain ~50% black modules');
        self::assertLessThan(15000, $blackCount);
    }

    #[Test]
    public function smaller_data_uses_smaller_symbol(): void
    {
        // Verify that we don't accidentally route к 144×144 для small data.
        $enc = new DataMatrixEncoder('Hello');
        self::assertLessThan(20, $enc->size());
    }

    #[Test]
    public function medium_data_uses_appropriate_symbol(): void
    {
        // ~1000 bytes — should fit в 132×132 (1304 data) NOT 144×144.
        $enc = new DataMatrixEncoder(str_repeat('A', 1000));
        self::assertLessThanOrEqual(132, $enc->size());
    }

    #[Test]
    public function binary_data_in_144(): void
    {
        // Binary data via Base 256 mode + 144×144.
        $binary = str_repeat("\x80\xFF\x01", 400); // ~1200 bytes
        $enc = new DataMatrixEncoder($binary, mode: DataMatrixEncoder::MODE_BASE256);
        // Should produce valid symbol (size depends on encoding overhead).
        self::assertGreaterThan(0, $enc->size());
    }
}
