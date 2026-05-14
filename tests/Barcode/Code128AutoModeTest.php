<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code128Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 164: Code 128 auto-mode switching tests.
 */
final class Code128AutoModeTest extends TestCase
{
    #[Test]
    public function alphanumeric_with_long_digit_run_compresses_to_set_c(): void
    {
        // "ABC1234567" — B for "ABC", switch to C for even-length digits.
        $auto = new Code128Encoder('ABC1234567');
        $legacy = new Code128Encoder('ABC1234567', autoMode: false);
        self::assertLessThan($legacy->moduleCount(), $auto->moduleCount(),
            'auto-mode должен compress digit-run в Set C');
    }

    #[Test]
    public function pure_digit_long_run_uses_set_c(): void
    {
        // Pure digit long string — same в обоих modes (Set C optimal).
        $enc = new Code128Encoder('123456789012');
        // 12 digits = 6 C pairs + start + chk + stop = 9 CW × 11 + 2 = 101.
        self::assertSame(101, $enc->moduleCount());
    }

    #[Test]
    public function pure_lowercase_uses_set_b(): void
    {
        $enc = new Code128Encoder('hello');
        // Start B + 5 chars + chk + stop = 8 CW × 11 + 2 = 90.
        self::assertSame(90, $enc->moduleCount());
    }

    #[Test]
    public function pure_uppercase_uses_set_b(): void
    {
        $enc = new Code128Encoder('HELLO');
        self::assertSame(90, $enc->moduleCount());
    }

    #[Test]
    public function control_only_uses_set_a(): void
    {
        // \x01\x02 — only ctrl chars + no lowercase → pure Set A.
        $enc = new Code128Encoder("\x01\x02");
        // Start A + 2 ctrl + chk + stop = 5 CW × 11 + 2 = 57.
        self::assertSame(57, $enc->moduleCount());
    }

    #[Test]
    public function mixed_uppercase_and_control_uses_pure_set_a(): void
    {
        // ABC\x01 — no lowercase, no long digit → Set A entire.
        $enc = new Code128Encoder("ABC\x01");
        // Start A + 4 chars + chk + stop = 7 CW × 11 + 2 = 79.
        self::assertSame(79, $enc->moduleCount());
    }

    #[Test]
    public function lowercase_with_control_uses_b_a_switch(): void
    {
        // "Hello\x01" — lowercase forces Set B, then CODE_A for \x01.
        $enc = new Code128Encoder("Hello\x01");
        // Start B + Hello(5) + CODE_A(1) + \x01(1) + chk + stop = 10 CW × 11 + 2 = 112.
        self::assertSame(112, $enc->moduleCount());
    }

    #[Test]
    public function digit_run_at_start_uses_set_c_start(): void
    {
        // Starts с long digit run → START_C directly.
        $enc = new Code128Encoder('1234ABC');
        // Start C + 1234(2 pairs) + CODE_B + ABC(3) + chk + stop = 9 CW × 11 + 2 = 101.
        self::assertSame(101, $enc->moduleCount());
    }

    #[Test]
    public function legacy_mode_preserves_old_behavior(): void
    {
        // Legacy mode не делает auto-switch — falls back на single-set logic.
        $auto = new Code128Encoder('AB1234');
        $legacy = new Code128Encoder('AB1234', autoMode: false);
        // Auto compresses через Set C — короче.
        self::assertLessThan($legacy->moduleCount(), $auto->moduleCount());
    }

    #[Test]
    public function rejects_high_bytes_in_auto_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code128Encoder('Привет');  // Cyrillic > 126.
    }

    #[Test]
    public function empty_input_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code128Encoder('');
    }
}
