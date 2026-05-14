<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 38: QR Code encoding modes (ISO/IEC 18004 §7.4).
 *
 * Auto-detection picks most compact mode для input:
 *  - Numeric (0-9 only) — 10 bits per 3 chars.
 *  - Alphanumeric (0-9, A-Z, space, $%*+-./:) — 11 bits per 2 chars.
 *  - Byte (any 8-bit data) — 8 bits per char.
 *
 * Kanji mode → Phase 101. Structured Append → Phase 183. ECI → Phase 184.
 */
enum QrEncodingMode: int
{
    case Numeric = 0b0001;
    case Alphanumeric = 0b0010;
    case Byte = 0b0100;
    /** Phase 101: Kanji (Shift_JIS) — 13 bits per char. */
    case Kanji = 0b1000;

    /**
     * 4-bit mode indicator (по ISO/IEC 18004 Table 2).
     */
    public function indicatorBits(): int
    {
        return $this->value;
    }

    /**
     * Character count indicator width в bits, версия-зависимая.
     */
    public function charCountIndicatorBits(int $version): int
    {
        return match (true) {
            $version <= 9 => match ($this) {
                self::Numeric => 10,
                self::Alphanumeric => 9,
                self::Byte => 8,
                self::Kanji => 8,
            },
            $version <= 26 => match ($this) {
                self::Numeric => 12,
                self::Alphanumeric => 11,
                self::Byte => 16,
                self::Kanji => 10,
            },
            default => match ($this) {
                self::Numeric => 14,
                self::Alphanumeric => 13,
                self::Byte => 16,
                self::Kanji => 12,
            },
        };
    }

    /**
     * Bits required для $charCount input symbols в этом mode.
     */
    public function dataBitsFor(int $charCount): int
    {
        return match ($this) {
            self::Numeric => intdiv($charCount, 3) * 10
                + (($charCount % 3 === 1) ? 4 : (($charCount % 3 === 2) ? 7 : 0)),
            self::Alphanumeric => intdiv($charCount, 2) * 11
                + (($charCount % 2 === 1) ? 6 : 0),
            self::Byte => $charCount * 8,
            self::Kanji => $charCount * 13,
        };
    }

    /**
     * Auto-detect best mode для input.
     */
    public static function detect(string $data): self
    {
        if (preg_match('@^\d+$@', $data)) {
            return self::Numeric;
        }
        if (preg_match('@^[0-9A-Z $%*+\-./:]+$@', $data)) {
            return self::Alphanumeric;
        }

        return self::Byte;
    }

    /**
     * Alphanumeric character set order (для encoding lookup).
     */
    public const ALPHANUMERIC_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
}
