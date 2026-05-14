<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Text;

/**
 * Phase 136: Unicode Bidirectional Algorithm (UAX #9) — implicit levels.
 *
 * Implements core Bidi rules для mixed LTR/RTL paragraphs:
 *  - Bidi class lookup (range-based, не полная UCD таблица)
 *  - Paragraph level detection (P2/P3)
 *  - W rules (W1-W7, weak character resolution)
 *  - N rules (N1-N2, neutral resolution)
 *  - I rules (I1-I2, level assignment)
 *  - L2 reordering (reverse RTL runs)
 *
 * Phase 148 additions:
 *  - X9 filter: drop bidi formatting characters (LRE/RLE/LRO/RLO/PDF +
 *    LRI/RLI/FSI/PDI) before processing — these don't render
 *  - L3 mirroring: paired bracket/punctuation chars в RTL runs swapped
 *    с их Unicode mirror counterparts
 *
 * Не реализовано:
 *  - X1-X8 stack-based explicit embedding/override level tracking — rarely
 *    used в plain text; full implementation requires ≤125-deep level stack
 *  - L4 (combining marks reordering) — handled implicitly via NSM class
 *
 * Use case: mixed Latin + Arabic/Hebrew text где исходная Bidi
 * algorithm требуется для correct paragraph layout. Pure unidirectional
 * runs работают и без этого алгоритма.
 */
final class BidiAlgorithm
{
    // Strong types
    public const L = 'L';

    public const R = 'R';

    public const AL = 'AL';

    // Weak types
    public const EN = 'EN';

    public const ES = 'ES';

    public const ET = 'ET';

    public const AN = 'AN';

    public const CS = 'CS';

    public const NSM = 'NSM';

    public const BN = 'BN';

    // Neutral types
    public const B = 'B';

    public const S = 'S';

    public const WS = 'WS';

    public const ON = 'ON';

    /**
     * Reorder UTF-8 paragraph для visual display.
     *
     * Returns visually-ordered codepoints (left-to-right). RTL runs reversed.
     *
     * @param  int|null  $paragraphLevel  null = auto-detect (P2/P3); 0 = force LTR; 1 = force RTL
     * @return list<int>
     */
    public static function reorder(string $utf8, ?int $paragraphLevel = null): array
    {
        $cps = self::utf8ToCps($utf8);

        return self::reorderCodepoints($cps, $paragraphLevel);
    }

    /**
     * @param  list<int>  $cps
     * @return list<int>
     */
    public static function reorderCodepoints(array $cps, ?int $paragraphLevel = null): array
    {
        if ($cps === []) {
            return [];
        }
        // Phase 148: X9 — drop Bidi formatting characters (LRE/RLE/LRO/RLO/PDF
        // + LRI/RLI/FSI/PDI) before processing. Full X1-X8 stack-based level
        // override is deferred — практически embeddings rare в plain text.
        $cps = self::filterFormattingChars($cps);
        if ($cps === []) {
            return [];
        }
        $types = array_map([self::class, 'bidiClass'], $cps);
        $paragraphLevel ??= self::detectParagraphLevel($types);

        $resolved = $types;
        self::applyW($resolved, $paragraphLevel);
        self::applyN($resolved, $paragraphLevel);
        $levels = self::applyI($resolved, $paragraphLevel);

        // Phase 148: L3 mirroring BEFORE L2 reorder — mirror chars at odd
        // (RTL) levels using levels still aligned к original cps.
        $cps = self::applyL3($cps, $levels);

        return self::applyL2($cps, $levels);
    }

    /**
     * Phase 148: X9 filter. Bidi formatting characters don't appear в
     * rendered output (UAX 9 §3.3, после L1).
     *
     * @param  list<int>  $cps
     * @return list<int>
     */
    private static function filterFormattingChars(array $cps): array
    {
        $out = [];
        foreach ($cps as $cp) {
            if ($cp >= 0x202A && $cp <= 0x202E) {
                continue; // LRE/RLE/PDF/LRO/RLO
            }
            if ($cp >= 0x2066 && $cp <= 0x2069) {
                continue; // LRI/RLI/FSI/PDI
            }
            $out[] = $cp;
        }

        return $out;
    }

    /**
     * Phase 148: L3 mirroring. Применяется ДО L2 reorder пока levels всё
     * ещё parallel к input cps. Для каждой позиции at odd level (RTL),
     * если char имеет mirror counterpart — заменить им.
     *
     * @param  list<int>  $cps
     * @param  list<int>  $levels   levels parallel к input cps
     * @return list<int>
     */
    private static function applyL3(array $cps, array $levels): array
    {
        $out = $cps;
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            if (($levels[$i] & 1) === 0) {
                continue; // even level = LTR
            }
            $mirror = self::MIRROR_PAIRS[$out[$i]] ?? null;
            if ($mirror !== null) {
                $out[$i] = $mirror;
            }
        }

        return $out;
    }

    /**
     * BidiMirroring subset — most common ASCII brackets + Unicode brackets.
     *
     * @var array<int, int>
     */
    private const MIRROR_PAIRS = [
        0x0028 => 0x0029,  // ( ↔ )
        0x0029 => 0x0028,
        0x003C => 0x003E,  // < ↔ >
        0x003E => 0x003C,
        0x005B => 0x005D,  // [ ↔ ]
        0x005D => 0x005B,
        0x007B => 0x007D,  // { ↔ }
        0x007D => 0x007B,
        0x00AB => 0x00BB,  // « ↔ »
        0x00BB => 0x00AB,
        0x2039 => 0x203A,  // ‹ ↔ ›
        0x203A => 0x2039,
        0x2045 => 0x2046,  // ⁅ ↔ ⁆
        0x2046 => 0x2045,
        0x2329 => 0x232A,  // 〈 ↔ 〉
        0x232A => 0x2329,
        0x3008 => 0x3009,  // 〈 ↔ 〉
        0x3009 => 0x3008,
        0x300A => 0x300B,  // 《 ↔ 》
        0x300B => 0x300A,
        0x300C => 0x300D,  // 「 ↔ 」
        0x300D => 0x300C,
        0x300E => 0x300F,  // 『 ↔ 』
        0x300F => 0x300E,
    ];

    /**
     * Determine Bidi class для codepoint via range tables. Subset of UCD —
     * covers Latin, Cyrillic, Arabic, Hebrew, digits, common punctuation,
     * whitespace.
     */
    public static function bidiClass(int $cp): string
    {
        // ASCII range — fast path.
        if ($cp < 0x80) {
            return self::asciiBidiClass($cp);
        }
        // Hebrew.
        if ($cp >= 0x0590 && $cp <= 0x05FF) {
            // Hebrew points are NSM, letters are R.
            if (($cp >= 0x0591 && $cp <= 0x05BD)
                || $cp === 0x05BF
                || ($cp >= 0x05C1 && $cp <= 0x05C2)
                || ($cp >= 0x05C4 && $cp <= 0x05C5)
                || $cp === 0x05C7) {
                return self::NSM;
            }

            return self::R;
        }
        // Arabic block and extensions.
        if (($cp >= 0x0600 && $cp <= 0x06FF)
            || ($cp >= 0x0750 && $cp <= 0x077F)
            || ($cp >= 0x08A0 && $cp <= 0x08FF)
            || ($cp >= 0xFB50 && $cp <= 0xFDFF)
            || ($cp >= 0xFE70 && $cp <= 0xFEFF)) {
            // Arabic-Indic digits.
            if (($cp >= 0x0660 && $cp <= 0x0669) || ($cp >= 0x06F0 && $cp <= 0x06F9)) {
                return self::AN;
            }
            // Arabic harakat (diacritics) are NSM.
            if (($cp >= 0x064B && $cp <= 0x065F)
                || $cp === 0x0670
                || ($cp >= 0x06D6 && $cp <= 0x06DC)
                || ($cp >= 0x06DF && $cp <= 0x06E4)
                || ($cp >= 0x06E7 && $cp <= 0x06E8)
                || ($cp >= 0x06EA && $cp <= 0x06ED)
                || $cp === 0x08D3) {
                return self::NSM;
            }

            return self::AL;
        }
        // Combining marks (general).
        if ($cp >= 0x0300 && $cp <= 0x036F) {
            return self::NSM;
        }
        // Latin Extended A/B, Cyrillic, Greek, etc. — all L.
        if ($cp >= 0x0100 && $cp <= 0x058F) {
            return self::L;
        }
        // Above Hebrew/Arabic — assume L for most other Latin/CJK scripts.
        if ($cp >= 0x0900 && $cp <= 0xFFFF) {
            return self::L;
        }

        return self::ON;
    }

    private static function asciiBidiClass(int $cp): string
    {
        // Whitespace.
        if ($cp === 0x09) {
            return self::S;
        }
        if ($cp === 0x0A || $cp === 0x0D) {
            return self::B;
        }
        if ($cp === 0x0B || $cp === 0x0C) {
            return self::S;
        }
        if ($cp === 0x20) {
            return self::WS;
        }
        // Digits.
        if ($cp >= 0x30 && $cp <= 0x39) {
            return self::EN;
        }
        // Letters.
        if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)) {
            return self::L;
        }
        // ES (+ -)
        if ($cp === 0x2B || $cp === 0x2D) {
            return self::ES;
        }
        // ET (# $ % * °)
        if ($cp === 0x23 || $cp === 0x24 || $cp === 0x25) {
            return self::ET;
        }
        // CS (. , : / )
        if ($cp === 0x2E || $cp === 0x2C || $cp === 0x3A || $cp === 0x2F) {
            return self::CS;
        }
        // ON — brackets, quotes, etc.
        return self::ON;
    }

    /** @param list<string> $types */
    public static function detectParagraphLevel(array $types): int
    {
        foreach ($types as $t) {
            if ($t === self::L) {
                return 0;
            }
            if ($t === self::R || $t === self::AL) {
                return 1;
            }
        }

        return 0; // default LTR
    }

    /**
     * W rules — weak character type resolution (per UAX 9 §3.3.3).
     *
     * @param  list<string>  $types  modified in-place
     */
    private static function applyW(array &$types, int $paragraphLevel): void
    {
        $n = count($types);
        if ($n === 0) {
            return;
        }
        // W1: NSM takes type of previous character (or sor for first).
        $sor = $paragraphLevel === 0 ? self::L : self::R;
        $prev = $sor;
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === self::NSM) {
                $types[$i] = $prev;
            } else {
                $prev = $types[$i];
            }
        }
        // W2: EN preceded (через ET, CS, etc.) by AL becomes AN.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== self::EN) {
                continue;
            }
            // Walk back through neutrals/weaks к previous strong.
            for ($j = $i - 1; $j >= 0; $j--) {
                $tj = $types[$j];
                if ($tj === self::L || $tj === self::R) {
                    break;
                }
                if ($tj === self::AL) {
                    $types[$i] = self::AN;
                    break;
                }
            }
        }
        // W3: AL → R unconditionally.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === self::AL) {
                $types[$i] = self::R;
            }
        }
        // W4: ES/CS surrounded by EN's → EN. CS surrounded by AN's → AN.
        for ($i = 1; $i < $n - 1; $i++) {
            if ($types[$i] === self::ES && $types[$i - 1] === self::EN && $types[$i + 1] === self::EN) {
                $types[$i] = self::EN;
            } elseif ($types[$i] === self::CS) {
                if ($types[$i - 1] === self::EN && $types[$i + 1] === self::EN) {
                    $types[$i] = self::EN;
                } elseif ($types[$i - 1] === self::AN && $types[$i + 1] === self::AN) {
                    $types[$i] = self::AN;
                }
            }
        }
        // W5: ET adjacent (run) to EN → EN.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== self::ET) {
                continue;
            }
            // Look forward for EN.
            $hasEnNeighbor = false;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($types[$j] === self::ET) {
                    continue;
                }
                if ($types[$j] === self::EN) {
                    $hasEnNeighbor = true;
                }
                break;
            }
            // Look backward.
            if (! $hasEnNeighbor) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($types[$j] === self::ET) {
                        continue;
                    }
                    if ($types[$j] === self::EN) {
                        $hasEnNeighbor = true;
                    }
                    break;
                }
            }
            if ($hasEnNeighbor) {
                $types[$i] = self::EN;
            }
        }
        // W6: separators и terminators между non-EN/AN → ON.
        for ($i = 0; $i < $n; $i++) {
            if (in_array($types[$i], [self::ES, self::ET, self::CS], true)) {
                $types[$i] = self::ON;
            }
        }
        // W7: EN preceded by L (через neutrals) becomes L.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== self::EN) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                $tj = $types[$j];
                if ($tj === self::L) {
                    $types[$i] = self::L;
                    break;
                }
                if ($tj === self::R) {
                    break;
                }
            }
        }
    }

    /**
     * N rules — neutral resolution (per UAX 9 §3.3.4).
     * Simplified: N1+N2 combined без bracket pair handling (N0).
     *
     * @param  list<string>  $types
     */
    private static function applyN(array &$types, int $paragraphLevel): void
    {
        $n = count($types);
        $neutral = [self::B, self::S, self::WS, self::ON];
        // Find runs of consecutive neutrals и assign based on surrounding strong types.
        $sorR = $paragraphLevel === 1 ? self::R : self::L;
        $i = 0;
        while ($i < $n) {
            if (! in_array($types[$i], $neutral, true)) {
                $i++;

                continue;
            }
            $runStart = $i;
            while ($i < $n && in_array($types[$i], $neutral, true)) {
                $i++;
            }
            $runEnd = $i - 1;

            // Previous strong (treat AN/EN as R for N rules).
            $prevStrong = $sorR;
            for ($j = $runStart - 1; $j >= 0; $j--) {
                $tj = $types[$j];
                if ($tj === self::L) {
                    $prevStrong = self::L;
                    break;
                }
                if ($tj === self::R || $tj === self::EN || $tj === self::AN) {
                    $prevStrong = self::R;
                    break;
                }
            }
            // Next strong.
            $nextStrong = $sorR;
            for ($j = $runEnd + 1; $j < $n; $j++) {
                $tj = $types[$j];
                if ($tj === self::L) {
                    $nextStrong = self::L;
                    break;
                }
                if ($tj === self::R || $tj === self::EN || $tj === self::AN) {
                    $nextStrong = self::R;
                    break;
                }
            }
            // N1: if matched, take that direction. Else N2: take paragraph direction.
            $direction = $prevStrong === $nextStrong ? $prevStrong : ($paragraphLevel === 1 ? self::R : self::L);
            for ($k = $runStart; $k <= $runEnd; $k++) {
                $types[$k] = $direction;
            }
        }
    }

    /**
     * I rules — implicit level assignment per UAX 9 §3.3.5.
     *
     * Even paragraph level (LTR=0):
     *  L → same level, R → +1, AN/EN → +2
     * Odd paragraph level (RTL=1):
     *  R → same, L/AN/EN → +1
     *
     * @param  list<string>  $types
     * @return list<int>  per-char level
     */
    private static function applyI(array $types, int $paragraphLevel): array
    {
        $levels = [];
        foreach ($types as $t) {
            if (($paragraphLevel & 1) === 0) {
                // Even (LTR paragraph).
                $levels[] = match ($t) {
                    self::L => $paragraphLevel,
                    self::R => $paragraphLevel + 1,
                    self::AN, self::EN => $paragraphLevel + 2,
                    default => $paragraphLevel,
                };
            } else {
                // Odd (RTL paragraph).
                $levels[] = match ($t) {
                    self::R => $paragraphLevel,
                    self::L, self::AN, self::EN => $paragraphLevel + 1,
                    default => $paragraphLevel,
                };
            }
        }

        return $levels;
    }

    /**
     * L2 — reverse contiguous spans of chars at level ≥ k для k from
     * max(levels) down to lowest odd level.
     *
     * @param  list<int>  $cps
     * @param  list<int>  $levels
     * @return list<int>
     */
    private static function applyL2(array $cps, array $levels): array
    {
        if ($cps === []) {
            return [];
        }
        $maxLevel = max($levels);
        if ($maxLevel === 0) {
            return $cps;
        }
        $result = $cps;
        $n = count($result);
        for ($k = $maxLevel; $k >= 1; $k--) {
            $i = 0;
            while ($i < $n) {
                if ($levels[$i] >= $k) {
                    $start = $i;
                    while ($i < $n && $levels[$i] >= $k) {
                        $i++;
                    }
                    // Reverse $result[$start..$i-1].
                    $span = array_slice($result, $start, $i - $start);
                    $reversed = array_reverse($span);
                    array_splice($result, $start, $i - $start, $reversed);
                } else {
                    $i++;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public static function utf8ToCps(string $utf8): array
    {
        $cps = [];
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $b = ord($utf8[$i]);
            if ($b < 0x80) {
                $cps[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0) {
                $cps[] = (($b & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0) {
                $cps[] = (($b & 0x0F) << 12) | ((ord($utf8[$i + 1]) & 0x3F) << 6) | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0) {
                $cps[] = (($b & 0x07) << 18) | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6) | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $i++;
            }
        }

        return $cps;
    }
}
