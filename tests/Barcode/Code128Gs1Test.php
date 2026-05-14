<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Code128Encoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 165: GS1-128 (Code 128 с FNC1 + Application Identifiers) tests.
 */
final class Code128Gs1Test extends TestCase
{
    #[Test]
    public function gs1_factory_creates_encoder(): void
    {
        $enc = Code128Encoder::gs1('(01)09506000134352');
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function gs1_with_gtin14_only(): void
    {
        // (01) = GTIN-14, fixed 14 digits.
        $enc = Code128Encoder::gs1('(01)09506000134352');
        // START_C + FNC1 + "01" + 7 digit pairs + chk + STOP = 12 CW × 11 + 2 = 134.
        self::assertSame(134, $enc->moduleCount());
    }

    #[Test]
    public function gs1_with_multiple_ais(): void
    {
        // (01) fixed + (10) variable + (17) fixed date.
        $enc = Code128Encoder::gs1('(01)09506000134352(10)ABC123(17)260930');
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function gs1_rejects_invalid_ai_length(): void
    {
        // (01) requires exactly 14 digits.
        $this->expectException(\InvalidArgumentException::class);
        Code128Encoder::gs1('(01)12345');
    }

    #[Test]
    public function gs1_rejects_malformed_ai_syntax(): void
    {
        // Missing closing paren.
        $this->expectException(\InvalidArgumentException::class);
        Code128Encoder::gs1('(01');
    }

    #[Test]
    public function gs1_rejects_empty_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code128Encoder::gs1('(01)');
    }

    #[Test]
    public function gs1_rejects_no_ai(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code128Encoder::gs1('plain text without ai');
    }

    #[Test]
    public function gs1_with_text_data_uses_set_b(): void
    {
        // (10) Batch — variable text. Should use Set B for data.
        $enc = Code128Encoder::gs1('(10)BATCH-2026-001');
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function gs1_with_serial_after_gtin(): void
    {
        // Common pattern: (01)GTIN(21)Serial. (21) variable, (01) fixed.
        $enc = Code128Encoder::gs1('(01)09506000134352(21)SN12345');
        self::assertGreaterThan(0, $enc->moduleCount());
    }

    #[Test]
    public function gs1_with_two_variable_ais_separated_by_fnc1(): void
    {
        // Two variable AIs: (10) batch и (21) serial → FNC1 между.
        $encTwo = Code128Encoder::gs1('(10)ABC(21)DEF');
        $encSingleConcat = new Code128Encoder('10ABC21DEF');
        // GS1 includes start-FNC1 + variable-separator-FNC1 → больше CW чем
        // pure concatenation.
        self::assertGreaterThan($encSingleConcat->moduleCount(), $encTwo->moduleCount());
    }
}
