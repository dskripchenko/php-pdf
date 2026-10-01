<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;

/**
 * PDF text strings (ISO 32000-1 §7.9.2.2): what field names, tooltips and
 * text values are stored as. A text string is either PDFDocEncoding or
 * UTF-16BE with a byte order mark (PDF 2.0 adds UTF-8 with a BOM).
 *
 * @internal
 */
final class TextString
{
    /** PDFDocEncoding codes that differ from Latin-1 (Annex D.2). */
    private const PDF_DOC = [
        0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9, 0x1C => 0x02DD,
        0x1D => 0x02DB, 0x1E => 0x02DA, 0x1F => 0x02DC, 0x80 => 0x2022, 0x81 => 0x2020,
        0x82 => 0x2021, 0x83 => 0x2026, 0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192,
        0x87 => 0x2044, 0x88 => 0x2039, 0x89 => 0x203A, 0x8A => 0x2212, 0x8B => 0x2030,
        0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D, 0x8F => 0x2018, 0x90 => 0x2019,
        0x91 => 0x201A, 0x92 => 0x2122, 0x93 => 0xFB01, 0x94 => 0xFB02, 0x95 => 0x0141,
        0x96 => 0x0152, 0x97 => 0x0160, 0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131,
        0x9B => 0x0142, 0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E, 0xA0 => 0x20AC,
    ];

    /** Decode a text string (or a name, as some writers store values) to UTF-8. */
    public static function decode(mixed $value): ?string
    {
        if ($value instanceof PdfName) {
            return $value->value;
        }
        if (!$value instanceof PdfString) {
            return null;
        }
        $bytes = $value->bytes;

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }

        $out = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);
            $out .= mb_chr(self::PDF_DOC[$code] ?? $code, 'UTF-8');
        }

        return $out;
    }

    /**
     * Encode UTF-8 text as a text string: plain bytes while it is ASCII (the
     * PDFDocEncoding subset every reader agrees on), UTF-16BE with a BOM
     * otherwise.
     */
    public static function encode(string $utf8): PdfString
    {
        if (preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $utf8) === 1) {
            return new PdfString($utf8);
        }

        return new PdfString("\xFE\xFF".mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'));
    }
}
