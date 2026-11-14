<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader\Text;

/**
 * Glyph name → Unicode text, after the Adobe Glyph List conventions (§9.10.2):
 * the names of the Latin set, `uniXXXX` sequences, `uXXXX[XX]`, the `afii`
 * names of Cyrillic, ligatures joined by `_`, and a `.suffix` dropped.
 * Unknown names give null.
 */
final class GlyphNames
{
    /** Names of the Latin-1 supplement, U+00A1 onward in order. */
    private const LATIN1 = [
        'exclamdown', 'cent', 'sterling', 'currency', 'yen', 'brokenbar', 'section', 'dieresis', 'copyright',
        'ordfeminine', 'guillemotleft', 'logicalnot', null, 'registered', 'macron', 'degree', 'plusminus',
        'twosuperior', 'threesuperior', 'acute', 'mu', 'paragraph', 'periodcentered', 'cedilla', 'onesuperior',
        'ordmasculine', 'guillemotright', 'onequarter', 'onehalf', 'threequarters', 'questiondown', 'Agrave',
        'Aacute', 'Acircumflex', 'Atilde', 'Adieresis', 'Aring', 'AE', 'Ccedilla', 'Egrave', 'Eacute',
        'Ecircumflex', 'Edieresis', 'Igrave', 'Iacute', 'Icircumflex', 'Idieresis', 'Eth', 'Ntilde', 'Ograve',
        'Oacute', 'Ocircumflex', 'Otilde', 'Odieresis', 'multiply', 'Oslash', 'Ugrave', 'Uacute', 'Ucircumflex',
        'Udieresis', 'Yacute', 'Thorn', 'germandbls', 'agrave', 'aacute', 'acircumflex', 'atilde', 'adieresis',
        'aring', 'ae', 'ccedilla', 'egrave', 'eacute', 'ecircumflex', 'edieresis', 'igrave', 'iacute',
        'icircumflex', 'idieresis', 'eth', 'ntilde', 'ograve', 'oacute', 'ocircumflex', 'otilde', 'odieresis',
        'divide', 'oslash', 'ugrave', 'uacute', 'ucircumflex', 'udieresis', 'yacute', 'thorn', 'ydieresis',
    ];

    /** Names of ASCII punctuation and the rest of the common set. */
    private const NAMES = [
        'space' => 0x20, 'exclam' => 0x21, 'quotedbl' => 0x22, 'numbersign' => 0x23, 'dollar' => 0x24,
        'percent' => 0x25, 'ampersand' => 0x26, 'quotesingle' => 0x27, 'parenleft' => 0x28, 'parenright' => 0x29,
        'asterisk' => 0x2A, 'plus' => 0x2B, 'comma' => 0x2C, 'hyphen' => 0x2D, 'period' => 0x2E, 'slash' => 0x2F,
        'zero' => 0x30, 'one' => 0x31, 'two' => 0x32, 'three' => 0x33, 'four' => 0x34, 'five' => 0x35,
        'six' => 0x36, 'seven' => 0x37, 'eight' => 0x38, 'nine' => 0x39, 'colon' => 0x3A, 'semicolon' => 0x3B,
        'less' => 0x3C, 'equal' => 0x3D, 'greater' => 0x3E, 'question' => 0x3F, 'at' => 0x40,
        'bracketleft' => 0x5B, 'backslash' => 0x5C, 'bracketright' => 0x5D, 'asciicircum' => 0x5E,
        'underscore' => 0x5F, 'grave' => 0x60, 'braceleft' => 0x7B, 'bar' => 0x7C, 'braceright' => 0x7D,
        'asciitilde' => 0x7E, 'nbspace' => 0xA0, 'nonbreakingspace' => 0xA0, 'sfthyphen' => 0xAD, 'softhyphen' => 0xAD,
        'Euro' => 0x20AC, 'quotesinglbase' => 0x201A, 'florin' => 0x0192, 'quotedblbase' => 0x201E,
        'ellipsis' => 0x2026, 'dagger' => 0x2020, 'daggerdbl' => 0x2021, 'circumflex' => 0x02C6,
        'perthousand' => 0x2030, 'Scaron' => 0x0160, 'guilsinglleft' => 0x2039, 'OE' => 0x0152,
        'Zcaron' => 0x017D, 'quoteleft' => 0x2018, 'quoteright' => 0x2019, 'quotedblleft' => 0x201C,
        'quotedblright' => 0x201D, 'bullet' => 0x2022, 'endash' => 0x2013, 'emdash' => 0x2014, 'tilde' => 0x02DC,
        'trademark' => 0x2122, 'scaron' => 0x0161, 'guilsinglright' => 0x203A, 'oe' => 0x0153, 'zcaron' => 0x017E,
        'Ydieresis' => 0x0178, 'fi' => 0xFB01, 'fl' => 0xFB02, 'ff' => 0xFB00, 'ffi' => 0xFB03, 'ffl' => 0xFB04,
        'dotlessi' => 0x0131, 'minus' => 0x2212, 'fraction' => 0x2044, 'Lslash' => 0x0141, 'lslash' => 0x0142,
        'caron' => 0x02C7, 'breve' => 0x02D8, 'dotaccent' => 0x02D9, 'ring' => 0x02DA, 'ogonek' => 0x02DB,
        'hungarumlaut' => 0x02DD, 'periodcentered' => 0x00B7, 'numero' => 0x2116, 'afii61352' => 0x2116,
        'arrowright' => 0x2192, 'arrowleft' => 0x2190, 'checkmark' => 0x2713, 'degree' => 0x00B0,
    ];

    /** @var array<string,int>|null */
    private static ?array $table = null;

    public static function toUnicode(string $name): ?string
    {
        $base = explode('.', $name, 2)[0];
        if ($base === '') {
            return null;
        }
        if (str_contains($base, '_')) {
            $out = '';
            foreach (explode('_', $base) as $part) {
                $one = self::single($part);
                if ($one === null) {
                    return null;
                }
                $out .= $one;
            }

            return $out;
        }

        return self::single($base);
    }

    private static function single(string $name): ?string
    {
        if (preg_match('/^uni((?:[0-9A-F]{4})+)$/', $name, $m) === 1) {
            $out = '';
            foreach (str_split($m[1], 4) as $hex) {
                $cp = (int) hexdec($hex);
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    return null;
                }
                $out .= mb_chr($cp, 'UTF-8');
            }

            return $out;
        }
        if (preg_match('/^u([0-9A-F]{4,6})$/', $name, $m) === 1) {
            $cp = (int) hexdec($m[1]);

            return ($cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF)) ? mb_chr($cp, 'UTF-8') : null;
        }
        if (preg_match('/^afii(100(1[7-9]|[2-4]\d)|1006[5-9]|100[7-9]\d)$/', $name) === 1) {
            return self::cyrillic((int) substr($name, 4));
        }
        if (strlen($name) === 1 && ctype_alpha($name)) {
            return $name;
        }
        $cp = self::table()[$name] ?? null;

        return $cp === null ? null : mb_chr($cp, 'UTF-8');
    }

    /** afii10017…10049 — А…Я with Ё; afii10065…10097 — а…я with ё. */
    private static function cyrillic(int $n): ?string
    {
        $cp = match (true) {
            $n === 10023 => 0x0401,
            $n >= 10017 && $n <= 10022 => 0x0410 + ($n - 10017),
            $n >= 10024 && $n <= 10049 => 0x0416 + ($n - 10024),
            $n === 10071 => 0x0451,
            $n >= 10065 && $n <= 10070 => 0x0430 + ($n - 10065),
            $n >= 10072 && $n <= 10097 => 0x0436 + ($n - 10072),
            default => null,
        };

        return $cp === null ? null : mb_chr($cp, 'UTF-8');
    }

    /** @return array<string,int> */
    private static function table(): array
    {
        if (self::$table === null) {
            $table = self::NAMES;
            foreach (self::LATIN1 as $i => $name) {
                if ($name !== null) {
                    $table[$name] ??= 0xA1 + $i;
                }
            }
            self::$table = $table;
        }

        return self::$table;
    }
}
