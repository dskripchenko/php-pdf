<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code128Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Code128SetCTest extends TestCase
{
    #[Test]
    public function set_c_used_for_even_digit_string(): void
    {
        // 8 digits → Set C: 4 data CW + start + checksum + stop = 7 CW.
        // 11 modules per CW = 77 + 2 extra (stop pattern) = 79.
        $enc = new Code128Encoder('12345678');
        // Set C is more compact: 4 CW for 8 digits vs 8 CW для Set B.
        $cModules = $enc->moduleCount();

        // Compare против odd-length (forces Set B fallback).
        $encB = new Code128Encoder('123456789'); // 9 digits — Set B.
        $bModules = $encB->moduleCount();

        // Set C при 8 digits (4 CW) < Set B при 9 digits (9 CW).
        self::assertLessThan($bModules, $cModules);
    }

    #[Test]
    public function set_c_compactness_8_digits(): void
    {
        $enc = new Code128Encoder('12345678');
        // Set C: start_C + 4 pair CWs + checksum + stop = 7 CWs.
        // 7 × 11 + 2 (stop tail) = 79 modules.
        self::assertSame(79, $enc->moduleCount());
    }

    #[Test]
    public function set_b_used_for_short_digit_string(): void
    {
        // <4 digits — Set C overhead не worth it → Set B used.
        $enc = new Code128Encoder('12');
        // Set B: start + 2 char CWs + checksum + stop = 5 CWs × 11 + 2 = 57.
        self::assertSame(57, $enc->moduleCount());
    }

    #[Test]
    public function set_b_used_for_odd_digit_string(): void
    {
        // Odd-length digit-only — falls back к Set B (Set C requires pairs).
        $enc = new Code128Encoder('12345');
        // Set B: start + 5 chars + checksum + stop = 8 CW × 11 + 2 = 90.
        self::assertSame(90, $enc->moduleCount());
    }

    #[Test]
    public function set_b_used_for_mixed_content(): void
    {
        // Non-digit content — Set B unconditional.
        $enc = new Code128Encoder('ABC1234');
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function set_c_renders_via_long_numeric(): void
    {
        // 12-digit numeric (e.g. EAN-style barcode encoded в Code 128).
        $enc = new Code128Encoder('123456789012');
        // Set C: 6 pair CWs + start + checksum + stop = 9 CWs × 11 + 2 = 101.
        self::assertSame(101, $enc->moduleCount());
    }

    #[Test]
    public function set_c_first_and_last_module_black(): void
    {
        $enc = new Code128Encoder('1234');
        $modules = $enc->modules();
        self::assertTrue($modules[0], 'First module must be black (start pattern)');
        self::assertTrue($modules[count($modules) - 1], 'Last module (stop tail) must be black');
    }
}
