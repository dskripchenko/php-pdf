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
     * Текст с pre-encoded hex glyph-ID string (для Type0 composite fonts
     * с Identity-H encoding). $hexString должен включать угловые скобки:
     *   `<00480065006C006C006F>` — encoded "Hello"
     */
    public function textHexString(string $fontName, float $sizePt, float $xPt, float $yPt, string $hexString): self
    {
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        $this->body .= sprintf("%s %s Td\n", $this->formatNumber($xPt), $this->formatNumber($yPt));
        $this->body .= sprintf("%s Tj\n", $hexString);
        $this->body .= 'ET'."\n";

        return $this;
    }

    /**
     * Текст с kerning через PDF `TJ` operator (показывает glyphs с
     * inter-glyph position adjustments).
     *
     * $tjOps — alternating list<string|int>:
     *  - string '<NNNN...>' — hex glyph-ID run (shown через TJ)
     *  - int N — position adjustment в 1000/em units (positive = move
     *    next glyph LEFT, less space между chars; e.g. kerning AV)
     *
     * Example для kerning'нутого «AVA»:
     *   $cs->textTjArray('F1', 12, 72, 720, ['<0036>', 74, '<00570036>']);
     *   // → BT /F1 12 Tf 72 720 Td [<0036> 74 <00570036>] TJ ET
     *
     * @param  list<string|int>  $tjOps
     */
    public function textTjArray(string $fontName, float $sizePt, float $xPt, float $yPt, array $tjOps): self
    {
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        $this->body .= sprintf("%s %s Td\n", $this->formatNumber($xPt), $this->formatNumber($yPt));
        $this->body .= '[';
        foreach ($tjOps as $op) {
            if (is_int($op)) {
                $this->body .= ' '.$op.' ';
            } else {
                $this->body .= $op; // already-wrapped <hex>
            }
        }
        $this->body .= "] TJ\n";
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

    /**
     * Stroked rectangle (только outline, без fill). RGB stroke color
     * 0..1, line width в pt.
     */
    public function strokeRectangle(
        float $xPt,
        float $yPt,
        float $widthPt,
        float $heightPt,
        float $lineWidthPt = 0.5,
        float $r = 0,
        float $g = 0,
        float $b = 0,
    ): self {
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s w\n", $this->formatNumber($lineWidthPt));
        $this->body .= sprintf("%s %s %s RG\n", $this->formatNumber($r), $this->formatNumber($g), $this->formatNumber($b));
        $this->body .= sprintf("%s %s %s %s re\n", $this->formatNumber($xPt), $this->formatNumber($yPt), $this->formatNumber($widthPt), $this->formatNumber($heightPt));
        $this->body .= 'S'."\n";
        $this->body .= 'Q'."\n";

        return $this;
    }

    /**
     * Draw image (XObject) с translation + scale.
     *
     * PDF coords: image XObject родной 1×1 unit. Чтобы получить ширину
     * widthPt × heightPt в точке (xPt, yPt) — CTM matrix:
     *   widthPt 0 0 heightPt xPt yPt cm
     * Origin image = left-bottom corner.
     *
     * @param  string  $name  Имя в page /Resources/XObject << /Im1 ... >>
     */
    public function drawImage(string $name, float $xPt, float $yPt, float $widthPt, float $heightPt): self
    {
        $this->body .= "q\n";
        $this->body .= sprintf("%s 0 0 %s %s %s cm\n",
            $this->formatNumber($widthPt),
            $this->formatNumber($heightPt),
            $this->formatNumber($xPt),
            $this->formatNumber($yPt),
        );
        $this->body .= sprintf("/%s Do\n", $name);
        $this->body .= "Q\n";

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
