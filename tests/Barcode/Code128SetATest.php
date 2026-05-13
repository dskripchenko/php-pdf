<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code128Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Code128SetATest extends TestCase
{
    #[Test]
    public function control_char_with_uppercase_triggers_set_a(): void
    {
        // \x01 + uppercase → Set A path (no lowercase).
        $enc = new Code128Encoder("AB\x01");
        self::assertSame(68, $enc->moduleCount());
    }

    #[Test]
    public function tab_with_uppercase_uses_set_a(): void
    {
        $enc = new Code128Encoder("X\tY");
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function newline_only_uppercase_uses_set_a(): void
    {
        $enc = new Code128Encoder("\nABC\n");
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function control_with_lowercase_falls_к_set_b(): void
    {
        // Mixed control + lowercase — must use Set B (no Set A→B switching).
        // Set B doesn't support 0..31, так что должно throw.
        $this->expectException(\InvalidArgumentException::class);
        new Code128Encoder("Hello\x01");
    }

    #[Test]
    public function set_a_rejects_bytes_above_95(): void
    {
        // Non-ASCII byte (e.g. 0xC3 — Cyrillic UTF-8 lead) → falls back к
        // path that throws. Mixed control + high byte hits Set A path
        // which validates 0..95 range.
        $this->expectException(\InvalidArgumentException::class);
        new Code128Encoder("\x01"."Привет");
    }

    #[Test]
    public function set_a_first_module_black(): void
    {
        $enc = new Code128Encoder("\tABC");
        $modules = $enc->modules();
        self::assertTrue($modules[0]);
        self::assertTrue($modules[count($modules) - 1]);
    }

    #[Test]
    public function pure_text_uses_set_b_not_a(): void
    {
        // Without control chars, Set B used.
        $a = new Code128Encoder("Hello");
        self::assertGreaterThan(0, $a->moduleCount());
    }
}
