<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * The 14 standard PDF fonts (Adobe base-14), которые гарантированно
 * доступны в каждом PDF reader'е без embedding'а.
 *
 * Ограничения:
 *  - WinAnsi encoding (Latin-1 + some extended Latin); НЕТ Cyrillic,
 *    Greek, CJK, и т.д.
 *  - Symbol использует custom encoding для Greek/math glyphs.
 *  - ZapfDingbats использует custom encoding для shapes/arrows.
 *
 * Use cases:
 *  - Минимальный bundle размер (~600 байт on disk vs ~411KB for embedded
 *    Liberation)
 *  - Текст только Latin-1 (английский, западноевропейские языки)
 *
 * Для Cyrillic / Unicode требуется embedded TTF — см. PdfFont
 * (Phase 2 даёт subset, ToUnicode CMap, kerning, ligatures).
 *
 * Reference: ISO 32000-1 § 9.6.2.2.
 */
enum StandardFont: string
{
    case Helvetica = 'Helvetica';
    case HelveticaBold = 'Helvetica-Bold';
    case HelveticaOblique = 'Helvetica-Oblique';
    case HelveticaBoldOblique = 'Helvetica-BoldOblique';

    case TimesRoman = 'Times-Roman';
    case TimesBold = 'Times-Bold';
    case TimesItalic = 'Times-Italic';
    case TimesBoldItalic = 'Times-BoldItalic';

    case Courier = 'Courier';
    case CourierBold = 'Courier-Bold';
    case CourierOblique = 'Courier-Oblique';
    case CourierBoldOblique = 'Courier-BoldOblique';

    case Symbol = 'Symbol';
    case ZapfDingbats = 'ZapfDingbats';

    /**
     * PostScript name для /BaseFont в font dictionary. Совпадает с
     * value enum'а.
     */
    public function postScriptName(): string
    {
        return $this->value;
    }

    /**
     * PDF font dictionary эмиссия для Type1 standard font:
     *   << /Type /Font /Subtype /Type1 /BaseFont /<name> /Encoding /WinAnsiEncoding >>
     *
     * Symbol и ZapfDingbats имеют built-in encoding (не /WinAnsiEncoding),
     * поэтому /Encoding опускаем.
     */
    public function pdfDictionary(): string
    {
        $needsEncoding = ! in_array($this, [self::Symbol, self::ZapfDingbats], true);
        $encoding = $needsEncoding ? ' /Encoding /WinAnsiEncoding' : '';

        return sprintf(
            '<< /Type /Font /Subtype /Type1 /BaseFont /%s%s >>',
            $this->value,
            $encoding,
        );
    }

    /**
     * Heuristics: какой stylesheet group принадлежит font'у.
     */
    public function isSerif(): bool
    {
        return in_array($this, [
            self::TimesRoman, self::TimesBold, self::TimesItalic, self::TimesBoldItalic,
        ], true);
    }

    public function isMonospaced(): bool
    {
        return in_array($this, [
            self::Courier, self::CourierBold, self::CourierOblique, self::CourierBoldOblique,
        ], true);
    }

    public function isBold(): bool
    {
        return str_contains($this->value, 'Bold');
    }

    public function isItalic(): bool
    {
        return str_contains($this->value, 'Italic') || str_contains($this->value, 'Oblique');
    }
}
