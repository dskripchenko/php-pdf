<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use Dskripchenko\PhpPdf\Barcode\QrEncodingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QrEncodingModeTest extends TestCase
{
    #[Test]
    public function indicator_bits_match_spec(): void
    {
        self::assertSame(0b0001, QrEncodingMode::Numeric->indicatorBits());
        self::assertSame(0b0010, QrEncodingMode::Alphanumeric->indicatorBits());
        self::assertSame(0b0100, QrEncodingMode::Byte->indicatorBits());
    }

    #[Test]
    public function char_count_indicator_widths(): void
    {
        self::assertSame(10, QrEncodingMode::Numeric->charCountIndicatorBits(1));
        self::assertSame(10, QrEncodingMode::Numeric->charCountIndicatorBits(9));
        self::assertSame(12, QrEncodingMode::Numeric->charCountIndicatorBits(10));
        self::assertSame(12, QrEncodingMode::Numeric->charCountIndicatorBits(26));
        self::assertSame(14, QrEncodingMode::Numeric->charCountIndicatorBits(27));

        self::assertSame(9, QrEncodingMode::Alphanumeric->charCountIndicatorBits(1));
        self::assertSame(11, QrEncodingMode::Alphanumeric->charCountIndicatorBits(10));

        self::assertSame(8, QrEncodingMode::Byte->charCountIndicatorBits(1));
        self::assertSame(16, QrEncodingMode::Byte->charCountIndicatorBits(10));
    }

    #[Test]
    public function data_bits_for_numeric(): void
    {
        // 3 chars → 10 bits.
        self::assertSame(10, QrEncodingMode::Numeric->dataBitsFor(3));
        // 6 chars → 20 bits.
        self::assertSame(20, QrEncodingMode::Numeric->dataBitsFor(6));
        // 7 chars → 20 + 4 = 24.
        self::assertSame(24, QrEncodingMode::Numeric->dataBitsFor(7));
        // 8 chars → 20 + 7 = 27.
        self::assertSame(27, QrEncodingMode::Numeric->dataBitsFor(8));
    }

    #[Test]
    public function data_bits_for_alphanumeric(): void
    {
        // 2 chars → 11 bits.
        self::assertSame(11, QrEncodingMode::Alphanumeric->dataBitsFor(2));
        // 3 chars → 11 + 6 = 17.
        self::assertSame(17, QrEncodingMode::Alphanumeric->dataBitsFor(3));
    }

    #[Test]
    public function detect_picks_numeric_for_digits(): void
    {
        self::assertSame(QrEncodingMode::Numeric, QrEncodingMode::detect('12345'));
        self::assertSame(QrEncodingMode::Numeric, QrEncodingMode::detect('0'));
    }

    #[Test]
    public function detect_picks_alphanumeric_for_upper_and_special(): void
    {
        self::assertSame(QrEncodingMode::Alphanumeric, QrEncodingMode::detect('HELLO'));
        self::assertSame(QrEncodingMode::Alphanumeric, QrEncodingMode::detect('AB CD'));
        self::assertSame(QrEncodingMode::Alphanumeric, QrEncodingMode::detect('A%B+C'));
        // Mix digits + uppercase.
        self::assertSame(QrEncodingMode::Alphanumeric, QrEncodingMode::detect('ABC123'));
    }

    #[Test]
    public function detect_picks_byte_for_lowercase_or_unicode(): void
    {
        self::assertSame(QrEncodingMode::Byte, QrEncodingMode::detect('hello'));
        self::assertSame(QrEncodingMode::Byte, QrEncodingMode::detect('Привет'));
    }

    #[Test]
    public function numeric_mode_more_compact_than_byte(): void
    {
        // 17-digit string in numeric mode помещается в V1 ECC L;
        // 17 chars в byte mode тоже V1, но более compact bit usage.
        $numericInput = '12345678901234567';
        $enc = new QrEncoder($numericInput);
        self::assertSame(QrEncodingMode::Numeric, $enc->mode);
        self::assertSame(1, $enc->version);
    }

    #[Test]
    public function alphanumeric_more_compact_than_byte(): void
    {
        // 18 chars uppercase → V1 alphanumeric ECC L (cap ~25),
        // тогда как byte cap=17 потребует V2.
        $enc = new QrEncoder('ABCDEFGHIJKLMNOPQR');
        self::assertSame(QrEncodingMode::Alphanumeric, $enc->mode);
        self::assertSame(1, $enc->version);
    }

    #[Test]
    public function explicit_mode_byte_overrides_detection(): void
    {
        $enc = new QrEncoder('12345', mode: QrEncodingMode::Byte);
        self::assertSame(QrEncodingMode::Byte, $enc->mode);
    }

    #[Test]
    public function explicit_numeric_rejects_non_digit_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('ABC123', mode: QrEncodingMode::Numeric);
    }

    #[Test]
    public function explicit_alphanumeric_rejects_lowercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('hello', mode: QrEncodingMode::Alphanumeric);
    }

    #[Test]
    public function numeric_and_byte_yield_different_encoding(): void
    {
        $numeric = new QrEncoder('12345');
        $byte = new QrEncoder('12345', mode: QrEncodingMode::Byte);
        // Both V1, but matrix content differs из-за encoding mode.
        self::assertNotEquals($numeric->modules(), $byte->modules());
    }

    #[Test]
    public function alphanumeric_with_ecc_h(): void
    {
        // 'HELLO WORLD' (11 alphanumeric chars) — V1 alphanumeric ECC H?
        $enc = new QrEncoder('HELLO WORLD', QrEccLevel::H);
        self::assertSame(QrEncodingMode::Alphanumeric, $enc->mode);
        self::assertGreaterThanOrEqual(1, $enc->version);
    }
}
