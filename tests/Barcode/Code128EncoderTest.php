<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code128Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Code128EncoderTest extends TestCase
{
    #[Test]
    public function encodes_simple_alphanumeric(): void
    {
        $enc = new Code128Encoder('ABC');
        $modules = $enc->modules();

        // Start B (104) + 3 chars + checksum + Stop = 6 codes × 11 = 66
        // modules, plus stop tail (2 extra modules) = 68 total.
        self::assertCount(68, $modules);
        // Должно начинаться с черного бара (PDF код 128 spec).
        self::assertTrue($modules[0]);
    }

    #[Test]
    public function modules_with_quiet_zone_pads_correctly(): void
    {
        $enc = new Code128Encoder('X');
        $bare = $enc->moduleCount();
        $padded = $enc->modulesWithQuietZone(10);

        self::assertCount($bare + 20, $padded);
        // First/last 10 modules — white.
        for ($i = 0; $i < 10; $i++) {
            self::assertFalse($padded[$i], "Quiet zone module $i should be white");
            self::assertFalse($padded[count($padded) - 1 - $i], 'Trailing quiet zone module should be white');
        }
    }

    #[Test]
    public function rejects_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code128Encoder('');
    }

    #[Test]
    public function rejects_out_of_range_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Cyrillic — outside Code 128 Set B range.
        new Code128Encoder('Привет');
    }

    #[Test]
    public function accepts_control_chars_via_set_a(): void
    {
        // Phase 78: control chars trigger Set A encoding.
        $enc = new Code128Encoder("ABC\x01");
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function encodes_typical_invoice_number(): void
    {
        // No throws + reasonable module count.
        $enc = new Code128Encoder('INV-2026-00123');
        self::assertGreaterThan(100, $enc->moduleCount());
    }

    #[Test]
    public function encoded_modules_start_and_end_with_black_bar(): void
    {
        // Per spec: каждый pattern stripe starts with black. Stop pattern
        // тоже ends with black bar.
        $enc = new Code128Encoder('Hello');
        $modules = $enc->modules();

        self::assertTrue($modules[0], 'First module after start must be black bar');
        self::assertTrue($modules[count($modules) - 1], 'Last module of stop pattern must be black bar');
    }

    #[Test]
    public function different_data_yields_different_encoding(): void
    {
        $a = new Code128Encoder('AAAA');
        $b = new Code128Encoder('BBBB');

        self::assertNotEquals($a->modules(), $b->modules());
    }

    #[Test]
    public function checksum_changes_with_input(): void
    {
        // Indirect: equal-length inputs должны иметь одинаковую module count.
        // (Только checksum может отличаться, но он тоже 11 modules.)
        $a = new Code128Encoder('AAAA');
        $b = new Code128Encoder('BBBB');

        self::assertSame($a->moduleCount(), $b->moduleCount());
        // Но encoding отличается на data + checksum.
        self::assertNotEquals($a->modules(), $b->modules());
    }
}
