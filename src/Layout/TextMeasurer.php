<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

use Dskripchenko\PhpPdf\Pdf\PdfFont;

/**
 * Измеряет визуальную ширину UTF-8 строк в pt для заданного шрифта и размера.
 *
 * Использует PdfFont->widthOfCharPdfUnits() — width конкретного glyph'а
 * в 1000/em units. Преобразование к pt:
 *
 *   pt_width = sum(glyph_widths_in_1000s) × sizePt / 1000
 *
 * NB: для v0.1 не учитываем kerning (готовится в Phase 2) и не учитываем
 * GSUB substitutions (ligatures). Это значит фактическая ширина в reader'е
 * может слегка отличаться от нашего measurement (kerning делает текст
 * чуть уже). Допустимая ошибка для line-breaking layout < 2%.
 */
final class TextMeasurer
{
    public function __construct(
        private readonly PdfFont $font,
        private readonly float $sizePt,
    ) {}

    public function widthPt(string $utf8): float
    {
        $totalUnits = 0;
        foreach ($this->iterateCodepoints($utf8) as $cp) {
            $totalUnits += $this->font->widthOfCharPdfUnits($cp);
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

    /**
     * Pure UTF-8 byte iterator — без преобразования в большие массивы.
     *
     * @return iterable<int>
     */
    private function iterateCodepoints(string $utf8): iterable
    {
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $b1 = ord($utf8[$i]);
            if ($b1 < 0x80) {
                yield $b1;
                $i++;
            } elseif (($b1 & 0xE0) === 0xC0) {
                yield (($b1 & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b1 & 0xF0) === 0xE0) {
                yield (($b1 & 0x0F) << 12)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 6)
                    | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                yield (($b1 & 0x07) << 18)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            }
        }
    }
}
