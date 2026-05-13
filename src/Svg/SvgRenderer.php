<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Svg;

use Dskripchenko\PhpPdf\Pdf\Page;

/**
 * Phase 52: SVG → PDF native paths renderer.
 *
 * Parses SVG XML и emits PDF drawing operators на target Page region
 * (x, y, width, height в PDF coords; SVG coords transformed Y-flip +
 * scaled).
 *
 * SVG namespace ignored — works на любом XML с SVG-named elements.
 */
final class SvgRenderer
{
    /**
     * @param  array{0: int, 1: int, 2: int}|null  $rgb
     */
    private static function namedColorRgb(string $name): ?array
    {
        $named = [
            'black' => [0, 0, 0], 'white' => [255, 255, 255],
            'red' => [255, 0, 0], 'green' => [0, 128, 0], 'blue' => [0, 0, 255],
            'gray' => [128, 128, 128], 'silver' => [192, 192, 192],
            'maroon' => [128, 0, 0], 'olive' => [128, 128, 0], 'lime' => [0, 255, 0],
            'aqua' => [0, 255, 255], 'teal' => [0, 128, 128], 'navy' => [0, 0, 128],
            'fuchsia' => [255, 0, 255], 'purple' => [128, 0, 128], 'yellow' => [255, 255, 0],
        ];

        return $named[strtolower($name)] ?? null;
    }

    /**
     * Parse fill/stroke value → [r, g, b, hasColor]. hasColor=false для 'none'.
     *
     * @return array{0: float, 1: float, 2: float, 3: bool}
     */
    private static function parseColor(?string $value): array
    {
        if ($value === null || $value === '' || strtolower($value) === 'none') {
            return [0, 0, 0, false];
        }
        $v = trim($value);
        if (str_starts_with($v, '#')) {
            $hex = substr($v, 1);
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (strlen($hex) === 6 && ctype_xdigit($hex)) {
                return [
                    hexdec(substr($hex, 0, 2)) / 255,
                    hexdec(substr($hex, 2, 2)) / 255,
                    hexdec(substr($hex, 4, 2)) / 255,
                    true,
                ];
            }
        }
        $named = self::namedColorRgb($v);
        if ($named !== null) {
            return [$named[0] / 255, $named[1] / 255, $named[2] / 255, true];
        }

        return [0, 0, 0, true]; // unknown → black.
    }

    public static function render(string $svgXml, Page $page, float $boxX, float $boxY, float $boxW, float $boxH): void
    {
        // Suppress libxml warnings.
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($svgXml);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return;
        }

        // Determine source coord box.
        $srcW = (float) ($xml['width'] ?? 100);
        $srcH = (float) ($xml['height'] ?? 100);
        if (isset($xml['viewBox'])) {
            $parts = preg_split('@\s+@', trim((string) $xml['viewBox']));
            if ($parts !== false && count($parts) >= 4) {
                $srcW = (float) $parts[2];
                $srcH = (float) $parts[3];
            }
        }
        if ($srcW <= 0) {
            $srcW = 100;
        }
        if ($srcH <= 0) {
            $srcH = 100;
        }

        $scaleX = $boxW / $srcW;
        $scaleY = $boxH / $srcH;

        // Transform svg(x, y) → pdf(x, y). SVG Y grows down; PDF Y grows up.
        $tx = function (float $svgX) use ($boxX, $scaleX): float {
            return $boxX + $svgX * $scaleX;
        };
        $ty = function (float $svgY) use ($boxY, $boxH, $scaleY): float {
            return $boxY + ($boxH - $svgY * $scaleY);
        };

        self::walkElement($xml, $page, $tx, $ty, $scaleX, $scaleY);
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function walkElement(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX, float $scaleY): void
    {
        foreach ($el->children() as $child) {
            $tag = $child->getName();
            match ($tag) {
                'rect' => self::renderRect($child, $page, $tx, $ty, $scaleX, $scaleY),
                'line' => self::renderLine($child, $page, $tx, $ty, $scaleX),
                'circle' => self::renderCircle($child, $page, $tx, $ty, $scaleX, $scaleY),
                'ellipse' => self::renderEllipse($child, $page, $tx, $ty, $scaleX, $scaleY),
                'polygon' => self::renderPolygon($child, $page, $tx, $ty, true, $scaleX),
                'polyline' => self::renderPolygon($child, $page, $tx, $ty, false, $scaleX),
                'path' => self::renderPath($child, $page, $tx, $ty, $scaleX),
                'g' => self::walkElement($child, $page, $tx, $ty, $scaleX, $scaleY),
                default => null,
            };
        }
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderRect(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX, float $scaleY): void
    {
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);
        $w = (float) ($el['width'] ?? 0);
        $h = (float) ($el['height'] ?? 0);
        [$fr, $fg, $fb, $hasFill] = self::parseColor(isset($el['fill']) ? (string) $el['fill'] : '#000');
        [$sr, $sg, $sb, $hasStroke] = self::parseColor(isset($el['stroke']) ? (string) $el['stroke'] : null);
        $sw = (float) ($el['stroke-width'] ?? 1);

        $px = $tx($x);
        $pw = $w * $scaleX;
        $ph = $h * $scaleY;
        $py = $ty($y + $h); // PDF y = bottom-left of rect.

        if ($hasFill) {
            $page->fillRect($px, $py, $pw, $ph, $fr, $fg, $fb);
        }
        if ($hasStroke) {
            $page->strokeRect($px, $py, $pw, $ph, $sw * $scaleX, $sr, $sg, $sb);
        }
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderLine(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX): void
    {
        $x1 = (float) ($el['x1'] ?? 0);
        $y1 = (float) ($el['y1'] ?? 0);
        $x2 = (float) ($el['x2'] ?? 0);
        $y2 = (float) ($el['y2'] ?? 0);
        [$sr, $sg, $sb] = self::parseColor(isset($el['stroke']) ? (string) $el['stroke'] : '#000');
        $sw = (float) ($el['stroke-width'] ?? 1);
        $page->strokeLine($tx($x1), $ty($y1), $tx($x2), $ty($y2), $sw * $scaleX, $sr, $sg, $sb);
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderCircle(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX, float $scaleY): void
    {
        $cx = (float) ($el['cx'] ?? 0);
        $cy = (float) ($el['cy'] ?? 0);
        $r = (float) ($el['r'] ?? 0);
        self::renderEllipseAt($cx, $cy, $r, $r, $el, $page, $tx, $ty, $scaleX, $scaleY);
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderEllipse(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX, float $scaleY): void
    {
        $cx = (float) ($el['cx'] ?? 0);
        $cy = (float) ($el['cy'] ?? 0);
        $rx = (float) ($el['rx'] ?? 0);
        $ry = (float) ($el['ry'] ?? 0);
        self::renderEllipseAt($cx, $cy, $rx, $ry, $el, $page, $tx, $ty, $scaleX, $scaleY);
    }

    /**
     * Ellipse → polygon approximation (36 segments).
     *
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderEllipseAt(float $cx, float $cy, float $rx, float $ry, \SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX, float $scaleY): void
    {
        $segments = 36;
        $points = [];
        for ($i = 0; $i < $segments; $i++) {
            $angle = 2 * M_PI * $i / $segments;
            $points[] = [$tx($cx + cos($angle) * $rx), $ty($cy + sin($angle) * $ry)];
        }
        [$fr, $fg, $fb, $hasFill] = self::parseColor(isset($el['fill']) ? (string) $el['fill'] : '#000');
        [$sr, $sg, $sb, $hasStroke] = self::parseColor(isset($el['stroke']) ? (string) $el['stroke'] : null);
        $sw = (float) ($el['stroke-width'] ?? 1);

        if ($hasFill) {
            $page->fillPolygon($points, $fr, $fg, $fb);
        }
        if ($hasStroke) {
            // Closed polyline для stroked ellipse.
            $closed = $points;
            $closed[] = $points[0];
            $page->strokePolyline($closed, $sw * $scaleX, $sr, $sg, $sb);
        }
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderPolygon(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, bool $closed, float $scaleX): void
    {
        $pointsAttr = (string) ($el['points'] ?? '');
        $points = self::parsePoints($pointsAttr, $tx, $ty);
        if ($points === []) {
            return;
        }
        [$fr, $fg, $fb, $hasFill] = self::parseColor(isset($el['fill']) ? (string) $el['fill'] : ($closed ? '#000' : null));
        [$sr, $sg, $sb, $hasStroke] = self::parseColor(isset($el['stroke']) ? (string) $el['stroke'] : null);
        $sw = (float) ($el['stroke-width'] ?? 1);

        if ($closed && $hasFill) {
            $page->fillPolygon($points, $fr, $fg, $fb);
        }
        if ($hasStroke) {
            $line = $points;
            if ($closed) {
                $line[] = $points[0];
            }
            $page->strokePolyline($line, $sw * $scaleX, $sr, $sg, $sb);
        }
    }

    /**
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     * @return list<array{0: float, 1: float}>
     */
    private static function parsePoints(string $raw, callable $tx, callable $ty): array
    {
        $tokens = preg_split('@[\s,]+@', trim($raw));
        if ($tokens === false) {
            return [];
        }
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
        $out = [];
        for ($i = 0; $i + 1 < count($tokens); $i += 2) {
            $out[] = [$tx((float) $tokens[$i]), $ty((float) $tokens[$i + 1])];
        }

        return $out;
    }

    /**
     * Phase 53: SVG path parser с поддержкой M/L/H/V/C/S/Q/T/Z commands
     * (uppercase = absolute, lowercase = relative).
     *
     * Arc (A/a) — NOT supported (требует ellipse-arc → cubic conversion).
     *
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     */
    private static function renderPath(\SimpleXMLElement $el, Page $page, callable $tx, callable $ty, float $scaleX): void
    {
        $d = (string) ($el['d'] ?? '');
        if ($d === '') {
            return;
        }
        [$fr, $fg, $fb, $hasFill] = self::parseColor(isset($el['fill']) ? (string) $el['fill'] : '#000');
        [$sr, $sg, $sb, $hasStroke] = self::parseColor(isset($el['stroke']) ? (string) $el['stroke'] : null);
        $sw = (float) ($el['stroke-width'] ?? 1);

        $commands = self::parsePathD($d, $tx, $ty);
        if ($commands === []) {
            return;
        }

        // Determine fill/stroke mode.
        if ($hasFill && $hasStroke) {
            $mode = 'fillstroke';
        } elseif ($hasFill) {
            $mode = 'fill';
        } elseif ($hasStroke) {
            $mode = 'stroke';
        } else {
            return; // Nothing to draw.
        }

        $page->emitPath(
            $commands,
            $mode,
            $hasFill ? ['r' => $fr, 'g' => $fg, 'b' => $fb] : null,
            $hasStroke ? ['r' => $sr, 'g' => $sg, 'b' => $sb] : null,
            $sw * $scaleX,
        );
    }

    /**
     * Parse SVG path "d" attribute → list of PDF-compatible path commands.
     * Coordinates already transformed через $tx/$ty.
     *
     * Quadratic Bezier (Q/T) auto-converted к cubic Bezier (PDF не имеет
     * quadratic operator).
     *
     * @param  callable(float): float  $tx
     * @param  callable(float): float  $ty
     * @return list<array|string>
     */
    private static function parsePathD(string $d, callable $tx, callable $ty): array
    {
        // Tokenize: split on each command letter (preserve лезерные command'ы как separators).
        $regex = '@([MmLlHhVvCcSsQqTtZz])([^MmLlHhVvCcSsQqTtZz]*)@';
        if (! preg_match_all($regex, $d, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $commands = [];
        $cx = 0;
        $cy = 0; // current point (SVG units, NOT transformed)
        $startX = 0;
        $startY = 0; // path start for Z
        $lastCubicCtrlX = null;
        $lastCubicCtrlY = null;
        $lastQuadCtrlX = null;
        $lastQuadCtrlY = null;

        foreach ($matches as $m) {
            $op = $m[1];
            $args = trim($m[2]);
            $nums = $args === '' ? [] : preg_split('@[\s,]+|(?<=[0-9.])-@', $args);
            if ($nums === false) {
                $nums = [];
            }
            $nums = array_values(array_map('floatval', array_filter($nums, fn ($n) => $n !== '')));
            $isRel = ctype_lower($op);
            $opUp = strtoupper($op);

            switch ($opUp) {
                case 'M':
                    if (count($nums) < 2) {
                        break;
                    }
                    $x = $isRel ? $cx + $nums[0] : $nums[0];
                    $y = $isRel ? $cy + $nums[1] : $nums[1];
                    $commands[] = ['M', $tx($x), $ty($y)];
                    $cx = $x; $cy = $y;
                    $startX = $x; $startY = $y;
                    // Subsequent pairs treated как L.
                    for ($i = 2; $i + 1 < count($nums); $i += 2) {
                        $x = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $y = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $commands[] = ['L', $tx($x), $ty($y)];
                        $cx = $x; $cy = $y;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'L':
                    for ($i = 0; $i + 1 < count($nums); $i += 2) {
                        $x = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $y = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $commands[] = ['L', $tx($x), $ty($y)];
                        $cx = $x; $cy = $y;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'H':
                    foreach ($nums as $n) {
                        $x = $isRel ? $cx + $n : $n;
                        $commands[] = ['L', $tx($x), $ty($cy)];
                        $cx = $x;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'V':
                    foreach ($nums as $n) {
                        $y = $isRel ? $cy + $n : $n;
                        $commands[] = ['L', $tx($cx), $ty($y)];
                        $cy = $y;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'C':
                    for ($i = 0; $i + 5 < count($nums); $i += 6) {
                        $x1 = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $y1 = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $x2 = $isRel ? $cx + $nums[$i + 2] : $nums[$i + 2];
                        $y2 = $isRel ? $cy + $nums[$i + 3] : $nums[$i + 3];
                        $x3 = $isRel ? $cx + $nums[$i + 4] : $nums[$i + 4];
                        $y3 = $isRel ? $cy + $nums[$i + 5] : $nums[$i + 5];
                        $commands[] = ['C', $tx($x1), $ty($y1), $tx($x2), $ty($y2), $tx($x3), $ty($y3)];
                        $cx = $x3; $cy = $y3;
                        $lastCubicCtrlX = $x2;
                        $lastCubicCtrlY = $y2;
                    }
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'S':
                    for ($i = 0; $i + 3 < count($nums); $i += 4) {
                        // First control = reflection prev cubic ctrl OR current point.
                        if ($lastCubicCtrlX !== null) {
                            $x1 = 2 * $cx - $lastCubicCtrlX;
                            $y1 = 2 * $cy - $lastCubicCtrlY;
                        } else {
                            $x1 = $cx; $y1 = $cy;
                        }
                        $x2 = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $y2 = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $x3 = $isRel ? $cx + $nums[$i + 2] : $nums[$i + 2];
                        $y3 = $isRel ? $cy + $nums[$i + 3] : $nums[$i + 3];
                        $commands[] = ['C', $tx($x1), $ty($y1), $tx($x2), $ty($y2), $tx($x3), $ty($y3)];
                        $cx = $x3; $cy = $y3;
                        $lastCubicCtrlX = $x2;
                        $lastCubicCtrlY = $y2;
                    }
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;

                case 'Q':
                    for ($i = 0; $i + 3 < count($nums); $i += 4) {
                        $qx = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $qy = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $ex = $isRel ? $cx + $nums[$i + 2] : $nums[$i + 2];
                        $ey = $isRel ? $cy + $nums[$i + 3] : $nums[$i + 3];
                        // Quadratic к cubic: P1 = start + 2/3 * (Q - start); P2 = end + 2/3 * (Q - end).
                        $c1x = $cx + 2 / 3 * ($qx - $cx);
                        $c1y = $cy + 2 / 3 * ($qy - $cy);
                        $c2x = $ex + 2 / 3 * ($qx - $ex);
                        $c2y = $ey + 2 / 3 * ($qy - $ey);
                        $commands[] = ['C', $tx($c1x), $ty($c1y), $tx($c2x), $ty($c2y), $tx($ex), $ty($ey)];
                        $cx = $ex; $cy = $ey;
                        $lastQuadCtrlX = $qx;
                        $lastQuadCtrlY = $qy;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    break;

                case 'T':
                    for ($i = 0; $i + 1 < count($nums); $i += 2) {
                        if ($lastQuadCtrlX !== null) {
                            $qx = 2 * $cx - $lastQuadCtrlX;
                            $qy = 2 * $cy - $lastQuadCtrlY;
                        } else {
                            $qx = $cx; $qy = $cy;
                        }
                        $ex = $isRel ? $cx + $nums[$i] : $nums[$i];
                        $ey = $isRel ? $cy + $nums[$i + 1] : $nums[$i + 1];
                        $c1x = $cx + 2 / 3 * ($qx - $cx);
                        $c1y = $cy + 2 / 3 * ($qy - $cy);
                        $c2x = $ex + 2 / 3 * ($qx - $ex);
                        $c2y = $ey + 2 / 3 * ($qy - $ey);
                        $commands[] = ['C', $tx($c1x), $ty($c1y), $tx($c2x), $ty($c2y), $tx($ex), $ty($ey)];
                        $cx = $ex; $cy = $ey;
                        $lastQuadCtrlX = $qx;
                        $lastQuadCtrlY = $qy;
                    }
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    break;

                case 'Z':
                    $commands[] = 'Z';
                    $cx = $startX;
                    $cy = $startY;
                    $lastCubicCtrlX = $lastCubicCtrlY = null;
                    $lastQuadCtrlX = $lastQuadCtrlY = null;
                    break;
            }
        }

        return $commands;
    }
}
