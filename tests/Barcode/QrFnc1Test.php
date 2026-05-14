<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 211: QR FNC1 mode 1 (GS1) and mode 2 (AIM) marker tests.
 */
final class QrFnc1Test extends TestCase
{
    #[Test]
    public function default_no_fnc1(): void
    {
        $enc = new QrEncoder('hello');
        self::assertNull($enc->fnc1Mode);
        self::assertNull($enc->fnc1AimIndicator);
    }

    #[Test]
    public function gs1_fnc1_mode_1_accepted(): void
    {
        $enc = new QrEncoder('01095060001343528200', fnc1Mode: 1);
        self::assertSame(1, $enc->fnc1Mode);
        self::assertNull($enc->fnc1AimIndicator);
    }

    #[Test]
    public function aim_fnc1_mode_2_accepted(): void
    {
        $enc = new QrEncoder('data', fnc1Mode: 2, fnc1AimIndicator: 165);
        self::assertSame(2, $enc->fnc1Mode);
        self::assertSame(165, $enc->fnc1AimIndicator);
    }

    #[Test]
    public function rejects_invalid_fnc1_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1Mode: 3);
    }

    #[Test]
    public function rejects_mode_2_without_aim_indicator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1Mode: 2);
    }

    #[Test]
    public function rejects_aim_indicator_without_mode_2(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1AimIndicator: 100);
    }

    #[Test]
    public function rejects_aim_indicator_with_mode_1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1Mode: 1, fnc1AimIndicator: 100);
    }

    #[Test]
    public function rejects_out_of_range_aim_indicator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1Mode: 2, fnc1AimIndicator: 256);
    }

    #[Test]
    public function rejects_negative_aim_indicator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('data', fnc1Mode: 2, fnc1AimIndicator: -1);
    }

    #[Test]
    public function fnc1_mode_1_uses_more_data_bits(): void
    {
        // FNC1 mode 1 adds 4 bits → may bump version.
        $base = new QrEncoder('01095060001343528200', QrEccLevel::H);
        $fnc1 = new QrEncoder('01095060001343528200', QrEccLevel::H, fnc1Mode: 1);
        // Should encode без exception.
        self::assertGreaterThan(0, $fnc1->size());
        // Both should be valid; FNC1 version may differ if at capacity boundary.
        self::assertGreaterThanOrEqual($base->version, $fnc1->version);
    }

    #[Test]
    public function fnc1_mode_2_uses_12_extra_bits(): void
    {
        $enc = new QrEncoder('TEST', fnc1Mode: 2, fnc1AimIndicator: 165);
        self::assertGreaterThan(0, $enc->size());
    }

    #[Test]
    public function gs1_works_with_numeric_data(): void
    {
        $enc = new QrEncoder('01095060001343528200', fnc1Mode: 1);
        // GTIN-style data — numeric mode.
        self::assertSame(\Dskripchenko\PhpPdf\Barcode\QrEncodingMode::Numeric, $enc->mode);
    }

    #[Test]
    public function fnc1_combines_с_ecc_level(): void
    {
        $enc = new QrEncoder('GS1DATA', QrEccLevel::M, fnc1Mode: 1);
        self::assertSame(QrEccLevel::M, $enc->eccLevel);
        self::assertSame(1, $enc->fnc1Mode);
    }

    #[Test]
    public function fnc1_combines_with_eci(): void
    {
        // FNC1 + ECI together is unusual но technically allowed by spec.
        $enc = new QrEncoder('data', fnc1Mode: 1, eciDesignator: 26);
        self::assertSame(1, $enc->fnc1Mode);
        self::assertSame(26, $enc->eciDesignator);
    }
}
