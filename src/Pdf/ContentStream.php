<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Builder для содержимого Page content stream'а — графического PostScript-
 * подмножества PDF (ISO 32000-1 § 7.8).
 *
 * Operators (минимальный для POC-R9.a):
 *   q / Q     — push / pop graphics state
 *   BT / ET   — begin / end text object
 *   Tf        — set font + size (`/F1 12 Tf`)
 *   Td        — move text position (`x y Td`, в pt от origin)
 *   Tj        — show text string (`(Hello) Tj`)
 *   re / S / f — rectangle / stroke / fill (для прямоугольников)
 *   rg        — set non-stroking RGB color (`0.5 0.5 0.5 rg`)
 *
 * Coordinate system: origin в **левом нижнем** углу страницы; X растёт
 * вправо, Y растёт вверх. Это противоположно CSS / экранным координатам.
 * Единица — 1 pt (1/72 inch).
 */
final class ContentStream
{
    private string $body = '';

    /**
     * Текстовая операция. Координаты в pt от origin (левый-нижний).
     */
    public function text(string $fontName, float $sizePt, float $xPt, float $yPt, string $text): self
    {
        $escapedText = $this->escapeString($text);
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        $this->body .= sprintf("%s %s Td\n", $this->formatNumber($xPt), $this->formatNumber($yPt));
        $this->body .= sprintf("(%s) Tj\n", $escapedText);
        $this->body .= 'ET'."\n";

        return $this;
    }

    /**
     * Filled rectangle с цветом RGB (0..1).
     */
    public function fillRectangle(
        float $xPt,
        float $yPt,
        float $widthPt,
        float $heightPt,
        float $r = 0,
        float $g = 0,
        float $b = 0,
    ): self {
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s %s %s rg\n", $this->formatNumber($r), $this->formatNumber($g), $this->formatNumber($b));
        $this->body .= sprintf("%s %s %s %s re\n", $this->formatNumber($xPt), $this->formatNumber($yPt), $this->formatNumber($widthPt), $this->formatNumber($heightPt));
        $this->body .= 'f'."\n";
        $this->body .= 'Q'."\n";

        return $this;
    }

    public function toString(): string
    {
        return $this->body;
    }

    public function isEmpty(): bool
    {
        return $this->body === '';
    }

    /**
     * Эскейпинг для PDF literal string `(…)`. По ISO 32000-1 §7.3.4.2:
     * нужно escape'ить `\`, `(`, `)`. Также non-printable bytes → \nnn.
     */
    private function escapeString(string $s): string
    {
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $c = $s[$i];
            $ord = ord($c);
            if ($c === '\\' || $c === '(' || $c === ')') {
                $out .= '\\'.$c;
            } elseif ($ord < 0x20 || $ord > 0x7E) {
                // Non-printable / non-ASCII: octal \nnn.
                $out .= sprintf("\\%03o", $ord);
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * Без локали-зависимого float-decimal-separator'а.
     * Минимум trailing zeros.
     */
    private function formatNumber(float $n): string
    {
        if ((int) $n == $n) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');
    }
}
