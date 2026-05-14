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

    // Phase 160: cache last emitted graphics state. Drop redundant q/rg/Q
    // wraps when consecutive operations use same fill color.
    private ?float $lastFillR = null;
    private ?float $lastFillG = null;
    private ?float $lastFillB = null;
    private bool $lastWasTextColorQ = false; // true если последний emit был q/rg wrap

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
     * Phase 160: persistent fill-color tracking. Старый подход — обернуть
     * каждый text emit в q/Q wrap (изолировать color change) — тратил
     * ~30-50 bytes per emit. Новый подход:
     *  - Drop q/Q wrap entirely (rely on PDF gstate persistence)
     *  - Emit `rg` ТОЛЬКО когда color реально меняется
     *  - fillRectangle и similar ops wrap своими q/Q (isolate их state)
     *
     * Caveat: caller отвечает за clean state — если кто-то external меняет
     * gstate fill color (fillRect внутри own q/Q OK, но persistant rg —
     * нет), tracker рассинхронится. Все эмиттеры в этом классе используют
     * q/Q wrap для own state changes.
     */
    private function openTextColor(?float $r, ?float $g, ?float $b): void
    {
        if ($r === null) {
            return;
        }
        $g ??= 0;
        $b ??= 0;
        // Skip emit если color уже в gstate.
        if ($this->lastFillR === $r && $this->lastFillG === $g && $this->lastFillB === $b) {
            return;
        }
        $this->body .= sprintf("%s %s %s rg\n",
            $this->formatNumber($r),
            $this->formatNumber($g),
            $this->formatNumber($b),
        );
        $this->lastFillR = $r;
        $this->lastFillG = $g;
        $this->lastFillB = $b;
    }

    private function closeTextColor(?float $r): void
    {
        // Phase 160: no-op. Color persists в gstate — нет нужды restore.
        // Метод оставлен для compat с existing call sites.
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
    /**
     * Phase 53: Generic path emission для SVG path support.
     *
     * Commands tuple per entry:
     *  - ['M', x, y]
     *  - ['L', x, y]
     *  - ['C', x1, y1, x2, y2, x3, y3]  cubic Bezier
     *  - 'Z' close
     *
     * \$mode: 'fill' | 'stroke' | 'fillstroke'.
     *
     * @param  list<array|string>  $commands
     * @param  array{r: float, g: float, b: float}|null  $fillRgb
     * @param  array{r: float, g: float, b: float}|null  $strokeRgb
     */
    public function emitPath(
        array $commands,
        string $mode = 'fill',
        ?array $fillRgb = null,
        ?array $strokeRgb = null,
        float $lineWidthPt = 1.0,
    ): self {
        if ($commands === []) {
            return $this;
        }
        $this->body .= "q\n";
        if ($fillRgb !== null && ($mode === 'fill' || $mode === 'fillstroke')) {
            $this->body .= sprintf("%s %s %s rg\n",
                $this->formatNumber($fillRgb['r']),
                $this->formatNumber($fillRgb['g']),
                $this->formatNumber($fillRgb['b']),
            );
        }
        if ($strokeRgb !== null && ($mode === 'stroke' || $mode === 'fillstroke')) {
            $this->body .= sprintf("%s w\n", $this->formatNumber($lineWidthPt));
            $this->body .= sprintf("%s %s %s RG\n",
                $this->formatNumber($strokeRgb['r']),
                $this->formatNumber($strokeRgb['g']),
                $this->formatNumber($strokeRgb['b']),
            );
        }
        foreach ($commands as $cmd) {
            if ($cmd === 'Z') {
                $this->body .= "h\n";

                continue;
            }
            if (! is_array($cmd)) {
                continue;
            }
            $type = $cmd[0];
            if ($type === 'M') {
                $this->body .= sprintf("%s %s m\n",
                    $this->formatNumber($cmd[1]), $this->formatNumber($cmd[2]),
                );
            } elseif ($type === 'L') {
                $this->body .= sprintf("%s %s l\n",
                    $this->formatNumber($cmd[1]), $this->formatNumber($cmd[2]),
                );
            } elseif ($type === 'C') {
                $this->body .= sprintf("%s %s %s %s %s %s c\n",
                    $this->formatNumber($cmd[1]), $this->formatNumber($cmd[2]),
                    $this->formatNumber($cmd[3]), $this->formatNumber($cmd[4]),
                    $this->formatNumber($cmd[5]), $this->formatNumber($cmd[6]),
                );
            }
        }
        $op = match ($mode) {
            'stroke' => 'S',
            'fillstroke' => 'B',
            default => 'f',
        };
        $this->body .= $op."\n";
        $this->body .= "Q\n";

        return $this;
    }

    /**
     * Phase 82: fill rectangle с shading pattern. Operators:
     *  /Pattern cs    — switch к pattern color space.
     *  /Pn scn        — use pattern resource named Pn.
     *  x y w h re f   — rect path + fill.
     */
    public function fillRectWithPattern(float $x, float $y, float $w, float $h, string $patternName): self
    {
        $this->body .= "q\n";
        $this->body .= "/Pattern cs\n";
        $this->body .= sprintf("/%s scn\n", $patternName);
        $this->body .= sprintf("%s %s %s %s re\nf\n",
            $this->formatNumber($x), $this->formatNumber($y),
            $this->formatNumber($w), $this->formatNumber($h),
        );
        $this->body .= "Q\n";

        return $this;
    }

    /**
     * Phase 48: Begin tagged marked content. Pairs с emitEndMarkedContent().
     */
    public function emitBeginMarkedContent(string $tag, int $mcid): self
    {
        $this->body .= sprintf("/%s << /MCID %d >> BDC\n", $tag, $mcid);

        return $this;
    }

    public function emitEndMarkedContent(): self
    {
        $this->body .= "EMC\n";

        return $this;
    }

    /**
     * Phase 86: Begin /Artifact marked content (PDF/UA — content
     * excluded from struct tree / screen readers). No MCID.
     */
    public function emitBeginArtifact(string $type = 'Pagination'): self
    {
        $this->body .= sprintf("/Artifact << /Type /%s >> BDC\n", $type);

        return $this;
    }

    /**
     * Phase 45: filled polygon. Points = list<[x, y]>; closed path.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function fillPolygon(array $points, float $r = 0, float $g = 0, float $b = 0): self
    {
        if (count($points) < 3) {
            return $this;
        }
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s %s %s rg\n", $this->formatNumber($r), $this->formatNumber($g), $this->formatNumber($b));
        $this->body .= sprintf("%s %s m\n", $this->formatNumber($points[0][0]), $this->formatNumber($points[0][1]));
        for ($i = 1; $i < count($points); $i++) {
            $this->body .= sprintf("%s %s l\n", $this->formatNumber($points[$i][0]), $this->formatNumber($points[$i][1]));
        }
        $this->body .= "h\nf\nQ\n";

        return $this;
    }

    /**
     * Phase 45: stroked polyline (НЕ closed) для line charts.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function strokePolyline(
        array $points,
        float $lineWidthPt = 1.0,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        if (count($points) < 2) {
            return $this;
        }
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s w\n", $this->formatNumber($lineWidthPt));
        $this->body .= sprintf("%s %s %s RG\n", $this->formatNumber($r), $this->formatNumber($g), $this->formatNumber($b));
        $this->body .= sprintf("%s %s m\n", $this->formatNumber($points[0][0]), $this->formatNumber($points[0][1]));
        for ($i = 1; $i < count($points); $i++) {
            $this->body .= sprintf("%s %s l\n", $this->formatNumber($points[$i][0]), $this->formatNumber($points[$i][1]));
        }
        $this->body .= "S\nQ\n";

        return $this;
    }

    /**
     * Phase 44: stroked straight line from (x1,y1) to (x2,y2).
     */
    public function strokeLine(
        float $x1, float $y1, float $x2, float $y2,
        float $lineWidthPt = 0.5,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $this->body .= 'q'."\n";
        $this->body .= sprintf("%s w\n", $this->formatNumber($lineWidthPt));
        $this->body .= sprintf("%s %s %s RG\n", $this->formatNumber($r), $this->formatNumber($g), $this->formatNumber($b));
        $this->body .= sprintf("%s %s m\n", $this->formatNumber($x1), $this->formatNumber($y1));
        $this->body .= sprintf("%s %s l\n", $this->formatNumber($x2), $this->formatNumber($y2));
        $this->body .= 'S'."\n";
        $this->body .= 'Q'."\n";

        return $this;
    }

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
     * Phase 107: paint a Form XObject через scale+translate CTM + `/Name Do`.
     */
    public function useFormXObject(string $name, float $sx, float $sy, float $tx, float $ty): self
    {
        $this->body .= "q\n";
        $this->body .= sprintf("%s 0 0 %s %s %s cm\n",
            $this->formatNumber($sx),
            $this->formatNumber($sy),
            $this->formatNumber($tx),
            $this->formatNumber($ty),
        );
        $this->body .= sprintf("/%s Do\n", $name);
        $this->body .= "Q\n";

        return $this;
    }

    /**
     * Phase 112: begin Optional Content section — `/OC /name BDC`.
     */
    public function beginLayerContent(string $resourceName): self
    {
        $this->body .= sprintf("/OC /%s BDC\n", $resourceName);

        return $this;
    }

    /**
     * Phase 112: end Optional Content section — `EMC`.
     */
    public function endLayerContent(): self
    {
        $this->body .= "EMC\n";

        return $this;
    }

    /**
     * Phase 114: set line dash pattern. `[a b c d] phase d`.
     * Empty array resets к solid line.
     *
     * @param  list<float>  $pattern  alternating on/off lengths (PDF units)
     */
    public function setLineDashPattern(array $pattern, float $phase = 0.0): self
    {
        $arr = implode(' ', array_map([$this, 'formatNumber'], $pattern));
        $this->body .= sprintf("[%s] %s d\n", $arr, $this->formatNumber($phase));

        return $this;
    }

    /** Phase 114: reset к solid line. */
    public function resetLineDashPattern(): self
    {
        $this->body .= "[] 0 d\n";

        return $this;
    }

    /**
     * Phase 114: set line cap style. 0=butt, 1=round, 2=projecting square.
     */
    public function setLineCap(int $cap): self
    {
        if ($cap < 0 || $cap > 2) {
            throw new \InvalidArgumentException('Line cap must be 0..2');
        }
        $this->body .= sprintf("%d J\n", $cap);

        return $this;
    }

    /**
     * Phase 114: set line join style. 0=miter, 1=round, 2=bevel.
     */
    public function setLineJoin(int $join): self
    {
        if ($join < 0 || $join > 2) {
            throw new \InvalidArgumentException('Line join must be 0..2');
        }
        $this->body .= sprintf("%d j\n", $join);

        return $this;
    }

    /** Phase 114: set miter limit (controls miter→bevel switchover). */
    public function setMiterLimit(float $limit): self
    {
        $this->body .= sprintf("%s M\n", $this->formatNumber($limit));

        return $this;
    }

    /**
     * Phase 116: установить clipping rect для последующих ops.
     *
     * Emits `q x y w h re W n` — pushes graphics state, builds rect,
     * marks как clip path (nonzero winding), discards stroke/fill.
     * Subsequent drawing is masked к rect. End с popGraphicsState().
     */
    public function clipRect(float $x, float $y, float $w, float $h): self
    {
        $this->body .= "q\n";
        $this->body .= sprintf("%s %s %s %s re\nW n\n",
            $this->formatNumber($x), $this->formatNumber($y),
            $this->formatNumber($w), $this->formatNumber($h),
        );

        return $this;
    }

    /**
     * Phase 116: установить clipping polygon. Uses non-zero winding rule.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function clipPolygon(array $points): self
    {
        if (count($points) < 3) {
            throw new \InvalidArgumentException('Clip polygon needs ≥3 points');
        }
        $this->body .= "q\n";
        $first = true;
        foreach ($points as [$x, $y]) {
            $this->body .= sprintf("%s %s %s\n",
                $this->formatNumber($x), $this->formatNumber($y),
                $first ? 'm' : 'l',
            );
            $first = false;
        }
        $this->body .= "h W n\n";

        return $this;
    }

    /** Phase 116: emit Q — restore graphics state (ends clip + всё). */
    public function endClip(): self
    {
        $this->body .= "Q\n";

        return $this;
    }

    /**
     * Phase 117: set DeviceCMYK non-stroking (fill) color. Values 0..1.
     * Emits `c m y k k`.
     */
    public function setCmykFillColor(float $c, float $m, float $y, float $k): self
    {
        self::validateCmyk($c, $m, $y, $k);
        $this->body .= sprintf("%s %s %s %s k\n",
            $this->formatNumber($c), $this->formatNumber($m),
            $this->formatNumber($y), $this->formatNumber($k),
        );

        return $this;
    }

    /**
     * Phase 117: set DeviceCMYK stroking color. Values 0..1.
     * Emits `c m y k K`.
     */
    public function setCmykStrokeColor(float $c, float $m, float $y, float $k): self
    {
        self::validateCmyk($c, $m, $y, $k);
        $this->body .= sprintf("%s %s %s %s K\n",
            $this->formatNumber($c), $this->formatNumber($m),
            $this->formatNumber($y), $this->formatNumber($k),
        );

        return $this;
    }

    private static function validateCmyk(float $c, float $m, float $y, float $k): void
    {
        foreach ([$c, $m, $y, $k] as $v) {
            if ($v < 0.0 || $v > 1.0) {
                throw new \InvalidArgumentException('CMYK components must be в диапазоне 0..1');
            }
        }
    }

    /**
     * Phase 118: set text rendering mode.
     *
     * Modes (ISO 32000-1 §9.3.6 Table 106):
     *  - 0: fill (default)
     *  - 1: stroke (outline only)
     *  - 2: fill + stroke
     *  - 3: invisible (useful для searchable OCR layer над scanned image)
     *  - 4: fill + add to path для clipping
     *  - 5: stroke + add to path для clipping
     *  - 6: fill + stroke + add to path для clipping
     *  - 7: add to path для clipping only
     *
     * Emits `N Tr`.
     */
    public function setTextRenderingMode(int $mode): self
    {
        if ($mode < 0 || $mode > 7) {
            throw new \InvalidArgumentException('Text rendering mode must be 0..7');
        }
        $this->body .= sprintf("%d Tr\n", $mode);

        return $this;
    }

    /**
     * Phase 102: drawImage с rotation вокруг (xPt + widthPt/2, yPt + heightPt/2).
     * angleRad — counter-clockwise (PDF convention).
     *
     * Composed CTM: T(cx, cy) · R(θ) · T(-cx, -cy) · S(w, h) · T(x, y).
     */
    public function drawImageRotated(
        string $name, float $xPt, float $yPt, float $widthPt, float $heightPt, float $angleRad,
    ): self {
        $cx = $xPt + $widthPt / 2;
        $cy = $yPt + $heightPt / 2;
        $cos = cos($angleRad);
        $sin = sin($angleRad);

        $this->body .= "q\n";
        // Translate к center, rotate, translate back, then scale + position.
        // Combined matrix: precompute final CTM analytically.
        // Image XObject 1×1 unit. Need transform: scale(w,h) → rotate around center → translate.
        // Equivalently: a=cos, b=sin, c=-sin, d=cos для rotation around origin
        // applied к scaled image, then translated так что center остаётся at (cx, cy).
        $a = $cos * $widthPt;
        $b = $sin * $widthPt;
        $c = -$sin * $heightPt;
        $d = $cos * $heightPt;
        $e = $cx - ($a + $c) / 2;
        $f = $cy - ($b + $d) / 2;
        $this->body .= sprintf("%s %s %s %s %s %s cm\n",
            $this->formatNumber($a), $this->formatNumber($b),
            $this->formatNumber($c), $this->formatNumber($d),
            $this->formatNumber($e), $this->formatNumber($f),
        );
        $this->body .= sprintf("/%s Do\n", $name);
        $this->body .= "Q\n";

        return $this;
    }

    /**
     * Phase 31: drawImage с применением ExtGState (opacity) перед draw.
     * Operator sequence: q / gs / cm / Do / Q — Q восстановит graphics
     * state, так что opacity не утечёт на последующий контент.
     */
    public function drawImageWithGs(
        string $name,
        string $gsName,
        float $xPt,
        float $yPt,
        float $widthPt,
        float $heightPt,
    ): self {
        $this->body .= "q\n";
        $this->body .= sprintf("/%s gs\n", $gsName);
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
     * Phase 31: pushes a graphics state save и applies ExtGState by name.
     * Caller must pair with popGraphicsState() через PDF operator Q.
     */
    public function pushGraphicsStateWithGs(string $gsName): self
    {
        $this->body .= "q\n";
        $this->body .= sprintf("/%s gs\n", $gsName);

        return $this;
    }

    public function popGraphicsState(): self
    {
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
