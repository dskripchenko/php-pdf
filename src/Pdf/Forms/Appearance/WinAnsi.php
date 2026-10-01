<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

/**
 * WinAnsiEncoding (ISO 32000-1 Annex D.2) — the single-byte encoding of the
 * simple fonts that AcroForm /DR dictionaries usually carry.
 *
 * @internal
 */
final class WinAnsi
{
    /** Codes 0x80..0x9F, which differ from Latin-1. */
    private const HIGH = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
        0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
        0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
        0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /** @var array<int,int>|null */
    private static ?array $reverse = null;

    /** Unicode codepoint of a WinAnsi code, or null when the code is unassigned. */
    public static function codepoint(int $code): ?int
    {
        if ($code >= 0x80 && $code <= 0x9F) {
            return self::HIGH[$code] ?? null;
        }

        return $code >= 0x20 && $code <= 0xFF ? $code : null;
    }

    /**
     * Encode UTF-8 text as WinAnsi bytes, or null when some character has no
     * WinAnsi code.
     */
    public static function encode(string $utf8): ?string
    {
        if (self::$reverse === null) {
            self::$reverse = [];
            for ($code = 0x20; $code <= 0xFF; $code++) {
                $cp = self::codepoint($code);
                if ($cp !== null) {
                    self::$reverse[$cp] = $code;
                }
            }
        }

        $out = '';
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $char) {
            $code = self::$reverse[mb_ord($char, 'UTF-8')] ?? null;
            if ($code === null) {
                return null;
            }
            $out .= chr($code);
        }

        return $out;
    }
}
