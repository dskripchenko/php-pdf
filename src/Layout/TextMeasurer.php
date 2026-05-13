<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

use Dskripchenko\PhpPdf\Pdf\PdfFont;

/**
 * Измеряет визуальную ширину UTF-8 строк в pt для заданного шрифта и размера.
 *
 * Phase 2c (kerning): учитываем GPOS pair-adjustments если font имеет
 * kerning table. Без kerning'а (PDF base-14 или старые font'ы без GPOS) —
 * деградация к простому summing'у glyph-advance widths.
 *
 * Не покрываем (Phase L):
 *  - GSUB ligature substitutions (Phase 2d даёт fi/fl/ffi)
 *  - Hyphenation
 *  - Complex script shaping (Arabic, Indic)
 *
 * Допустимая ошибка для line-breaking purposes без kerning < 2%; с
 * kerning'ом ≈ 0% для PDF base-14 alike and < 0.5% для embedded TTFs.
 */
final class TextMeasurer
{
    public function __construct(
        private readonly PdfFont $font,
        private readonly float $sizePt,
        private readonly bool $useKerning = true,
    ) {}

    public function widthPt(string $utf8): float
    {
        $totalUnits = 0;
        $prevGid = null;
        foreach ($this->font->utf8ToGlyphs($utf8) as ['gid' => $gid]) {
            $totalUnits += $this->font->widthOfGlyphPdfUnits($gid);
            if ($this->useKerning && $prevGid !== null) {
                // kerningPdfUnits > 0 для tighter pairs (less width).
                $totalUnits -= $this->font->kerningPdfUnits($prevGid, $gid);
            }
            $prevGid = $gid;
        }

        return $totalUnits * $this->sizePt / 1000;
    }

    public function widthOfCodepointPt(int $cp): float
    {
        return $this->font->widthOfCharPdfUnits($cp) * $this->sizePt / 1000;
    }

    public function sizePt(): float
    {
        return $this->sizePt;
    }
}
