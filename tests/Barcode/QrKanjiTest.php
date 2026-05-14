<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use Dskripchenko\PhpPdf\Barcode\QrEncodingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QrKanjiTest extends TestCase
{
    #[Test]
    public function kanji_mode_indicator_bits(): void
    {
        self::assertSame(0b1000, QrEncodingMode::Kanji->indicatorBits());
    }

    #[Test]
    public function kanji_data_bits_per_char(): void
    {
        self::assertSame(13, QrEncodingMode::Kanji->dataBitsFor(1));
        self::assertSame(26, QrEncodingMode::Kanji->dataBitsFor(2));
        self::assertSame(130, QrEncodingMode::Kanji->dataBitsFor(10));
    }

    #[Test]
    public function kanji_char_count_indicator_widths(): void
    {
        self::assertSame(8, QrEncodingMode::Kanji->charCountIndicatorBits(1));
        self::assertSame(8, QrEncodingMode::Kanji->charCountIndicatorBits(9));
        self::assertSame(10, QrEncodingMode::Kanji->charCountIndicatorBits(10));
        self::assertSame(12, QrEncodingMode::Kanji->charCountIndicatorBits(27));
    }

    #[Test]
    public function encodeKanji_known_value(): void
    {
        // 0x935F (悪) — Shift_JIS Kanji.
        // Subtract 0x8140 → 0x121F. MSB 0x12 (18) × 0xC0 + 0x1F (31) = 3487.
        $bits = QrEncoder::encodeKanji("\x93\x5F");
        self::assertSame(13, strlen($bits));
        self::assertSame(str_pad(decbin(3487), 13, '0', STR_PAD_LEFT), $bits);
    }

    #[Test]
    public function encodeKanji_two_chars(): void
    {
        $bits = QrEncoder::encodeKanji("\x93\x5F\x93\x5F");
        self::assertSame(26, strlen($bits));
    }

    #[Test]
    public function encoder_with_kanji_mode(): void
    {
        // 1 Kanji char (2 bytes) → V1 ECC L OK.
        $enc = new QrEncoder("\x93\x5F", mode: QrEncodingMode::Kanji);
        self::assertSame(QrEncodingMode::Kanji, $enc->mode);
        self::assertSame(1, $enc->version);
    }

    #[Test]
    public function out_of_range_byte_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Bytes 0x00 0x00 — outside Shift_JIS Kanji ranges.
        QrEncoder::encodeKanji("\x00\x00");
    }
}
