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
    public function text(
        string $fontName, float $sizePt, float $xPt, float $yPt, string $text,
        ?float $r = null, ?float $g = null, ?float $b = null,
        float $letterSpacingPt = 0,
    ): self {
        $escapedText = $this->escapeString($text);
        $this->openTextColor($r, $g, $b);
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        if ($letterSpacingPt !== 0.0) {
            $this->body .= sprintf("%s Tc\n", $this->formatNumber($letterSpacingPt));
        }
        $this->body .= sprintf("%s %s Td\n", $this->formatNumber($xPt), $this->formatNumber($yPt));
        $this->body .= sprintf("(%s) Tj\n", $escapedText);
        $this->body .= 'ET'."\n";
        $this->closeTextColor($r);

        return $this;
    }

    /**
     * Текст с pre-encoded hex glyph-ID string (для Type0 composite fonts
     * с Identity-H encoding). $hexString должен включать угловые скобки:
     *   `<00480065006C006C006F>` — encoded "Hello"
     */
    public function textHexString(
        string $fontName, float $sizePt, float $xPt, float $yPt, string $hexString,
        ?float $r = null, ?float $g = null, ?float $b = null,
        float $letterSpacingPt = 0,
    ): self {
        $this->openTextColor($r, $g, $b);
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        if ($letterSpacingPt !== 0.0) {
            $this->body .= sprintf("%s Tc\n", $this->formatNumber($letterSpacingPt));
        }
        $this->body .= sprintf("%s %s Td\n", $this->formatNumber($xPt), $this->formatNumber($yPt));
        $this->body .= sprintf("%s Tj\n", $hexString);
        $this->body .= 'ET'."\n";
        $this->closeTextColor($r);

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
    public function textTjArray(
        string $fontName, float $sizePt, float $xPt, float $yPt, array $tjOps,
        ?float $r = null, ?float $g = null, ?float $b = null,
    ): self {
        $this->openTextColor($r, $g, $b);
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
        $this->closeTextColor($r);

        return $this;
    }

    /**
     * Wraps text-show operator с q + rg для colored text. Если $r=null —
     * no-op (используется current graphics state color, обычно чёрный).
     */
    private function openTextColor(?float $r, ?float $g, ?float $b): void
    {
        if ($r === null) {
            return;
        }
        $this->body .= "q\n";
        $this->body .= sprintf("%s %s %s rg\n",
            $this->formatNumber($r),
            $this->formatNumber($g ?? 0),
            $this->formatNumber($b ?? 0),
        );
    }

    private function closeTextColor(?float $r): void
    {
        if ($r === null) {
            return;
        }
        $this->body .= "Q\n";
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

    /**
     * Stroked rounded rectangle. Rounded corners через 4 cubic Bezier
     * arcs (quarter-circle approximation, control points = r × 0.5523).
     */
    public function strokeRoundedRectangle(
        float $xPt, float $yPt, float $widthPt, float $heightPt, float $radiusPt,
        float $lineWidthPt = 0.5,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $r0 = max(0, min($radiusPt, min($widthPt, $heightPt) / 2));
        $kappa = 0.55228474983;  // 4 × (sqrt(2) - 1) / 3
        $cp = $r0 * $kappa;

        $x = $xPt; $y = $yPt; $w = $widthPt; $h = $heightPt;
        $f = $this->formatNumber(...);
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s w\n", $f($lineWidthPt));
        $this->body .= sprintf("%s %s %s RG\n", $f($r), $f($g), $f($b));
        // Path: start at (x+r, y), follow rect's outer edge через arcs.
        $this->body .= sprintf("%s %s m\n", $f($x + $r0), $f($y));
        // Bottom edge → bottom-right corner.
        $this->body .= sprintf("%s %s l\n", $f($x + $w - $r0), $f($y));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $w - $r0 + $cp), $f($y),
            $f($x + $w), $f($y + $r0 - $cp),
            $f($x + $w), $f($y + $r0),
        );
        // Right edge → top-right corner.
        $this->body .= sprintf("%s %s l\n", $f($x + $w), $f($y + $h - $r0));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $w), $f($y + $h - $r0 + $cp),
            $f($x + $w - $r0 + $cp), $f($y + $h),
            $f($x + $w - $r0), $f($y + $h),
        );
        // Top edge → top-left corner.
        $this->body .= sprintf("%s %s l\n", $f($x + $r0), $f($y + $h));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $r0 - $cp), $f($y + $h),
            $f($x), $f($y + $h - $r0 + $cp),
            $f($x), $f($y + $h - $r0),
        );
        // Left edge → bottom-left corner.
        $this->body .= sprintf("%s %s l\n", $f($x), $f($y + $r0));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x), $f($y + $r0 - $cp),
            $f($x + $r0 - $cp), $f($y),
            $f($x + $r0), $f($y),
        );
        $this->body .= "h\nS\n";
        $this->body .= 'Q'."\n";

        return $this;
    }

    /**
     * Filled rounded rectangle.
     */
    public function fillRoundedRectangle(
        float $xPt, float $yPt, float $widthPt, float $heightPt, float $radiusPt,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $r0 = max(0, min($radiusPt, min($widthPt, $heightPt) / 2));
        $kappa = 0.55228474983;
        $cp = $r0 * $kappa;

        $x = $xPt; $y = $yPt; $w = $widthPt; $h = $heightPt;
        $f = $this->formatNumber(...);
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s %s %s rg\n", $f($r), $f($g), $f($b));
        $this->body .= sprintf("%s %s m\n", $f($x + $r0), $f($y));
        $this->body .= sprintf("%s %s l\n", $f($x + $w - $r0), $f($y));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $w - $r0 + $cp), $f($y),
            $f($x + $w), $f($y + $r0 - $cp),
            $f($x + $w), $f($y + $r0),
        );
        $this->body .= sprintf("%s %s l\n", $f($x + $w), $f($y + $h - $r0));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $w), $f($y + $h - $r0 + $cp),
            $f($x + $w - $r0 + $cp), $f($y + $h),
            $f($x + $w - $r0), $f($y + $h),
        );
        $this->body .= sprintf("%s %s l\n", $f($x + $r0), $f($y + $h));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x + $r0 - $cp), $f($y + $h),
            $f($x), $f($y + $h - $r0 + $cp),
            $f($x), $f($y + $h - $r0),
        );
        $this->body .= sprintf("%s %s l\n", $f($x), $f($y + $r0));
        $this->body .= sprintf("%s %s %s %s %s %s c\n",
            $f($x), $f($y + $r0 - $cp),
            $f($x + $r0 - $cp), $f($y),
            $f($x + $r0), $f($y),
        );
        $this->body .= "h\nf\n";
        $this->body .= 'Q'."\n";

        return $this;
    }

    /**
     * Rotated text для watermarks. $angleRad — counter-clockwise angle
     * относительно X-axis. Поворот around точки (xPt, yPt). $color — RGB
     * 0..1.
     *
     * Использует text matrix Tm:
     *   cos(a) sin(a) -sin(a) cos(a) x y Tm
     *
     * Для embedded font передавай $hexString с угловыми скобками (Identity-H
     * encoded) + $isHex=true.
     */
    public function rotatedText(
        string $fontName,
        float $sizePt,
        float $xPt,
        float $yPt,
        float $angleRad,
        string $text,
        float $r = 0.85,
        float $g = 0.85,
        float $b = 0.85,
        bool $isHex = false,
    ): self {
        $cos = cos($angleRad);
        $sin = sin($angleRad);
        $this->body .= "q\n";
        $this->body .= sprintf("%s %s %s rg\n",
            $this->formatNumber($r),
            $this->formatNumber($g),
            $this->formatNumber($b),
        );
        $this->body .= 'BT'."\n";
        $this->body .= sprintf("/%s %s Tf\n", $fontName, $this->formatNumber($sizePt));
        $this->body .= sprintf("%s %s %s %s %s %s Tm\n",
            $this->formatNumber($cos),
            $this->formatNumber($sin),
            $this->formatNumber(-$sin),
            $this->formatNumber($cos),
            $this->formatNumber($xPt),
            $this->formatNumber($yPt),
        );
        if ($isHex) {
            $this->body .= sprintf("%s Tj\n", $text);
        } else {
            $this->body .= sprintf("(%s) Tj\n", $this->escapeString($text));
        }
        $this->body .= 'ET'."\n";
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
