<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 125: Aztec Compact 2D barcode (ISO/IEC 24778:2008).
 *
 * Implementation scope:
 *  - Compact Aztec only (sizes 15×15 .. 27×27, layers 1-4).
 *  - Full ASCII via 5 character modes (Upper/Lower/Mixed/Punct/Digit)
 *    with mode latches/shifts.
 *  - Binary mode (B/S) для bytes > 127 или non-encodable chars.
 *  - Bit stuffing per ISO §6.4.4 (no all-zero/all-one codewords).
 *  - Reed-Solomon ECC over GF(16) для mode message, GF(64) для 1-2L
 *    data, GF(256) для 3-4L data.
 *  - Auto layer selection с ~23% ECC redundancy (per ISO рекомендация).
 *
 * Не реализовано в этой версии:
 *  - Full Aztec (15×15..151×151, layers 5-32) — отдельный phase.
 *  - Rune mode (single-character symbol).
 *  - Structured Append (multi-symbol concatenation).
 *  - ECI / FLG(n) extended channel interpretation.
 *
 * Output via {@see modules()} — 2D bool matrix; true = black module.
 */
final class AztecEncoder
{
    // ---------------- Character mode tables ----------------

    /** Special action codes (returned from encode functions as negative ints). */
    private const SHIFT_PUNCT = -1;     // P/S
    private const SHIFT_BINARY = -2;    // B/S — followed by length + bytes
    private const SHIFT_UPPER_FROM_LOWER = -3; // U/S in Lower mode
    private const LATCH_UPPER = -10;
    private const LATCH_LOWER = -11;
    private const LATCH_MIXED = -12;
    private const LATCH_PUNCT = -13;
    private const LATCH_DIGIT = -14;

    /** Mode IDs. */
    private const MODE_UPPER = 0;
    private const MODE_LOWER = 1;
    private const MODE_MIXED = 2;
    private const MODE_PUNCT = 3;
    private const MODE_DIGIT = 4;

    /** Bit widths per mode. */
    private const MODE_BITS = [
        self::MODE_UPPER => 5,
        self::MODE_LOWER => 5,
        self::MODE_MIXED => 5,
        self::MODE_PUNCT => 5,
        self::MODE_DIGIT => 4,
    ];

    // Character → (mode, code-value) lookup. Built lazily in classmap().
    /** @var array<int, list<array{0:int, 1:int}>>|null */
    private static ?array $charMap = null;

    // ---------------- Compact size info ----------------

    /** Compact Aztec data capacity (bits) per layer count. */
    private const COMPACT_DATA_BITS = [
        1 => 102,  // 17 codewords × 6 bits
        2 => 240,  // 40 codewords × 6 bits
        3 => 408,  // 51 codewords × 8 bits
        4 => 608,  // 76 codewords × 8 bits
    ];

    /** Codeword bit width per compact layer count. */
    private const COMPACT_CW_BITS = [
        1 => 6,
        2 => 6,
        3 => 8,
        4 => 8,
    ];

    public readonly int $layers;

    public readonly int $size;

    public readonly int $dataCodewords;

    public readonly int $eccCodewords;

    /** @var list<list<bool>> */
    private array $matrix;

    public function __construct(public readonly string $data)
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Aztec input must be non-empty');
        }

        // 1. Encode text → bit stream (мay contain unbalanced bits).
        $bits = self::encodeToBits($data);

        // 2. Choose layer count fitting data with ECC overhead.
        $layers = null;
        $cwBits = 0;
        $dataCwCount = 0;
        $eccCwCount = 0;
        $stuffedBits = '';
        foreach ([1, 2, 3, 4] as $L) {
            $b = self::COMPACT_CW_BITS[$L];
            $totalCw = intdiv(self::COMPACT_DATA_BITS[$L], $b);
            // ECC ratio default ~23% per ISO; ensure ECC ≥ 3 codewords minimum.
            $ecc = max(3, (int) round($totalCw * 0.23));
            $maxDataCw = $totalCw - $ecc;
            // Bit stuff bits to ensure no all-0/all-1 codeword.
            $tryStuffed = self::stuffBits($bits, $b);
            $bitsNeeded = strlen($tryStuffed);
            // Pad to multiple of b.
            $padNeeded = ($b - ($bitsNeeded % $b)) % $b;
            $cwUsed = intdiv($bitsNeeded + $padNeeded, $b);
            if ($cwUsed <= $maxDataCw) {
                $layers = $L;
                $cwBits = $b;
                $dataCwCount = $totalCw - $ecc;
                $eccCwCount = $ecc;
                $stuffedBits = $tryStuffed;
                break;
            }
        }
        if ($layers === null) {
            throw new \InvalidArgumentException('Aztec compact capacity exceeded (>76 codewords); use full Aztec');
        }

        $this->layers = $layers;
        $this->size = 11 + 4 * $layers; // Compact: 15, 19, 23, 27.
        $this->dataCodewords = $dataCwCount;
        $this->eccCodewords = $eccCwCount;

        // 3. Pad bit stream к full data capacity (pad with 1s).
        $totalDataBits = $dataCwCount * $cwBits;
        $stuffedBits = str_pad($stuffedBits, $totalDataBits, '1', STR_PAD_RIGHT);
        // After padding, check last codeword isn't all-1 (would be illegal).
        $lastCw = substr($stuffedBits, -$cwBits);
        if ($lastCw === str_repeat('1', $cwBits)) {
            // Flip last bit to avoid all-1 codeword.
            $stuffedBits = substr($stuffedBits, 0, -1) . '0';
        }

        // 4. Split bits → integer codewords (GF(64) or GF(256)).
        $codewords = [];
        for ($i = 0; $i < $totalDataBits; $i += $cwBits) {
            $codewords[] = bindec(substr($stuffedBits, $i, $cwBits));
        }

        // 5. Compute Reed-Solomon ECC.
        $primPoly = $cwBits === 6 ? 0x43 : 0x12D; // GF(64) x^6+x+1; GF(256) x^8+x^4+x^3+x^2+1.
        $eccVals = self::reedSolomonEncode($codewords, $eccCwCount, $cwBits, $primPoly);
        $allCw = array_merge($codewords, $eccVals);

        // 6. Convert codewords к single bit string (MSB-first within each codeword).
        $bitStream = '';
        foreach ($allCw as $cw) {
            $bitStream .= str_pad(decbin($cw), $cwBits, '0', STR_PAD_LEFT);
        }

        // 7. Build matrix.
        $this->matrix = $this->buildMatrix($bitStream, $layers);
    }

    /** @return list<list<bool>> */
    public function modules(): array
    {
        return $this->matrix;
    }

    public function matrixSize(): int
    {
        return $this->size;
    }

    // ---------------- Character encoding ----------------

    /**
     * Convert string к Aztec bit stream switching modes optimally
     * (greedy heuristic, not optimal but correct).
     */
    public static function encodeToBits(string $text): string
    {
        self::classmap();
        $bits = '';
        $mode = self::MODE_UPPER;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            $ord = ord($ch);
            $entries = self::$charMap[$ord] ?? null;

            if ($entries === null) {
                // Non-encodable char — emit as binary shift.
                $bits .= self::emitBinaryShift($mode, [$ord]);

                continue;
            }

            // Pick best entry for current mode.
            $bestMode = null;
            $bestCode = null;
            $bestCost = PHP_INT_MAX;
            foreach ($entries as [$m, $c]) {
                $cost = self::switchCost($mode, $m);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $bestMode = $m;
                    $bestCode = $c;
                }
            }

            // Emit mode switch (latch or shift) если требуется.
            $bits .= self::switchModeBits($mode, $bestMode);
            // Update mode (latches change persistent mode; shifts handled inline).
            if ($bestMode !== self::MODE_PUNCT || $mode === self::MODE_MIXED) {
                // Latching transitions update mode; punct from non-mixed is a shift.
                if (self::isLatchSwitch($mode, $bestMode)) {
                    $mode = $bestMode;
                }
            } elseif ($mode === self::MODE_DIGIT && $bestMode === self::MODE_PUNCT) {
                // From Digit, going to Punct uses upper-latch then punct-shift — mode resets к Upper.
                $mode = self::MODE_UPPER;
            }
            // Emit char code in best mode.
            $bits .= str_pad(decbin($bestCode), self::MODE_BITS[$bestMode], '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Cost of switching from $from to $to (number of bits для switch sequence).
     */
    private static function switchCost(int $from, int $to): int
    {
        if ($from === $to) {
            return 0;
        }
        // P/S (punct shift) from Upper/Lower/Mixed/Digit = 5 bits (or 4 for Digit).
        if ($to === self::MODE_PUNCT) {
            return self::MODE_BITS[$from]; // single shift cost.
        }
        // Most other transitions = 1 latch (5 bits or 4 for Digit).
        return self::MODE_BITS[$from];
    }

    private static function isLatchSwitch(int $from, int $to): bool
    {
        // Punct from non-Mixed = shift (returns к prior mode).
        // From Mixed → Punct = latch.
        if ($to === self::MODE_PUNCT && $from !== self::MODE_MIXED) {
            return false;
        }

        return true;
    }

    /**
     * Emit mode-switch bits between $from и $to. Returns "" if same mode.
     */
    private static function switchModeBits(int $from, int $to): string
    {
        if ($from === $to) {
            return '';
        }
        $fromBits = self::MODE_BITS[$from];

        // Punct from non-Mixed: P/S shift (code 0 в all modes 5-bit; code 0 в Digit 4-bit).
        if ($to === self::MODE_PUNCT && $from !== self::MODE_MIXED) {
            return str_pad(decbin(0), $fromBits, '0', STR_PAD_LEFT);
        }

        // U/S from Lower (shift, one char only):
        // (Not used в greedy impl currently; latches preferred.)

        // Latch codes:
        // From Upper:  L/L=28, M/L=29, D/L=30
        // From Lower:  U/S=28(shift), M/L=29, D/L=30
        // From Mixed:  L/L=28, U/L=29, P/L=30
        // From Punct:  U/L=31
        // From Digit:  U/L=14, U/S=15(shift)
        $code = match ([$from, $to]) {
            [self::MODE_UPPER, self::MODE_LOWER] => 28,
            [self::MODE_UPPER, self::MODE_MIXED] => 29,
            [self::MODE_UPPER, self::MODE_DIGIT] => 30,
            [self::MODE_LOWER, self::MODE_MIXED] => 29,
            [self::MODE_LOWER, self::MODE_DIGIT] => 30,
            [self::MODE_LOWER, self::MODE_UPPER] => 28, // U/S shift in greedy impl emits 28 as latch
            [self::MODE_MIXED, self::MODE_LOWER] => 28,
            [self::MODE_MIXED, self::MODE_UPPER] => 29,
            [self::MODE_MIXED, self::MODE_PUNCT] => 30,
            [self::MODE_MIXED, self::MODE_DIGIT] => self::MODE_MIXED, // double-step
            [self::MODE_PUNCT, self::MODE_UPPER] => 31,
            [self::MODE_DIGIT, self::MODE_UPPER] => 14,
            default => null,
        };

        if ($code === self::MODE_MIXED) {
            // Special: Mixed→Digit needs Mixed→Upper(29) then Upper→Digit(30).
            return str_pad(decbin(29), 5, '0', STR_PAD_LEFT) . str_pad(decbin(30), 5, '0', STR_PAD_LEFT);
        }
        if ($code === null) {
            // Fallback: go via Upper. From=Digit→Lower means D→U(14)+U→L(28).
            $viaUpper = self::switchModeBits($from, self::MODE_UPPER);
            $viaUpper .= self::switchModeBits(self::MODE_UPPER, $to);

            return $viaUpper;
        }

        return str_pad(decbin($code), $fromBits, '0', STR_PAD_LEFT);
    }

    /**
     * Emit binary shift sequence: B/S (5 bits) + length (5 or 11 bits) + 8 bits per byte.
     *
     * @param  list<int>  $bytes
     */
    private static function emitBinaryShift(int $mode, array $bytes): string
    {
        $n = count($bytes);
        $bsCode = $mode === self::MODE_DIGIT ? 15 : 31; // B/S = 31 in 5-bit modes; в Digit нет direct B/S → используем U/S
        $modeBits = self::MODE_BITS[$mode];
        if ($mode === self::MODE_DIGIT) {
            // From Digit: U/S (single upper shift) — но мы уходим в B/S через Upper latch.
            $out = str_pad(decbin(14), 4, '0', STR_PAD_LEFT); // D→U latch.
            $modeBits = 5;
            $bsCode = 31;
            $out .= str_pad(decbin($bsCode), 5, '0', STR_PAD_LEFT);
        } else {
            $out = str_pad(decbin($bsCode), $modeBits, '0', STR_PAD_LEFT);
        }

        // Length: 5 bits если 1-31; else 0 (5 bits) + 11 bits для actual length.
        if ($n <= 31) {
            $out .= str_pad(decbin($n), 5, '0', STR_PAD_LEFT);
        } else {
            $out .= '00000';
            $out .= str_pad(decbin($n - 31), 11, '0', STR_PAD_LEFT);
        }
        foreach ($bytes as $b) {
            $out .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * Build character → [(mode, code), ...] mapping. Order of (mode, code)
     * pairs prefers shorter encoding modes.
     */
    private static function classmap(): void
    {
        if (self::$charMap !== null) {
            return;
        }
        $map = [];

        // Upper mode (5-bit): 1=SP, 2-27=A-Z.
        $map[ord(' ')][] = [self::MODE_UPPER, 1];
        for ($i = 0; $i < 26; $i++) {
            $map[ord('A') + $i][] = [self::MODE_UPPER, 2 + $i];
        }

        // Lower mode: 1=SP, 2-27=a-z.
        $map[ord(' ')][] = [self::MODE_LOWER, 1];
        for ($i = 0; $i < 26; $i++) {
            $map[ord('a') + $i][] = [self::MODE_LOWER, 2 + $i];
        }

        // Mixed mode: ctrl chars + special punctuation.
        // 0:P/S, 1:SP, 2:^A, 3:^B, ..., 13:^L, 14:^M (CR), 15:'^', 16:'_', 17:'`', 18:'|', 19:'~', 20:DEL, 21:'@', 22:'\\', 23:'^', 24:'_', 25:'`', 26:'|', 27:'~', 28:LL, 29:UL, 30:PL, 31:BS
        // (simplified — only the common printables + control)
        $map[ord(' ')][] = [self::MODE_MIXED, 1];
        // Control chars 1..13 → codes 2..14 (^A..^M).
        for ($i = 1; $i <= 13; $i++) {
            $map[$i][] = [self::MODE_MIXED, 1 + $i];
        }
        // 14 = ESC (0x1B), 15-19 = FS,GS,RS,US,@.
        $mixedMap = [
            0x1B => 14, 0x1C => 15, 0x1D => 16, 0x1E => 17, 0x1F => 18,
            ord('@') => 19, ord('\\') => 20, ord('^') => 21,
            ord('_') => 22, ord('`') => 23, ord('|') => 24,
            ord('~') => 25, 0x7F => 26,
        ];
        foreach ($mixedMap as $byte => $code) {
            $map[$byte][] = [self::MODE_MIXED, $code];
        }

        // Punct mode: punctuation.
        // 0:FLG(n), 1:CR, 2:CR/LF, 3:.SP, 4:,SP, 5::SP, 6-30: various, 31:U/L
        $punctMap = [
            ord('!') => 6, ord('"') => 7, ord('#') => 8, ord('$') => 9,
            ord('%') => 10, ord('&') => 11, ord('\'') => 12, ord('(') => 13,
            ord(')') => 14, ord('*') => 15, ord('+') => 16, ord(',') => 17,
            ord('-') => 18, ord('.') => 19, ord('/') => 20, ord(':') => 21,
            ord(';') => 22, ord('<') => 23, ord('=') => 24, ord('>') => 25,
            ord('?') => 26, ord('[') => 27, ord(']') => 28,
            ord('{') => 29, ord('}') => 30,
        ];
        foreach ($punctMap as $byte => $code) {
            $map[$byte][] = [self::MODE_PUNCT, $code];
        }

        // Digit mode: 1:SP, 2-11:'0'-'9', 12:',', 13:'.'.
        $map[ord(' ')][] = [self::MODE_DIGIT, 1];
        for ($i = 0; $i <= 9; $i++) {
            $map[ord('0') + $i][] = [self::MODE_DIGIT, 2 + $i];
        }
        $map[ord(',')][] = [self::MODE_DIGIT, 12];
        $map[ord('.')][] = [self::MODE_DIGIT, 13];

        self::$charMap = $map;
    }

    // ---------------- Bit stuffing ----------------

    /**
     * Bit stuffing per ISO/IEC 24778 §6.4.4 — when a b-bit codeword would
     * be all-zeros or all-ones, insert a complemented stuff bit.
     *
     * Algorithm: walk bit stream; для every b consecutive bits forming a
     * codeword, if the first (b-1) bits are all same, insert complement
     * at position b и shift remaining bits right.
     */
    public static function stuffBits(string $bits, int $b): string
    {
        $out = '';
        $i = 0;
        $n = strlen($bits);
        while ($i + $b <= $n) {
            $chunk = substr($bits, $i, $b);
            $first = $chunk[0];
            $allSame = true;
            for ($k = 0; $k < $b - 1; $k++) {
                if ($chunk[$k] !== $first) {
                    $allSame = false;
                    break;
                }
            }
            if ($allSame && substr_count($chunk, $first) === $b) {
                // Whole codeword same — insert opposite bit at position b-1.
                $out .= substr($chunk, 0, $b - 1) . ($first === '1' ? '0' : '1');
                $i += $b - 1; // re-consume the original b-th bit в next codeword.
            } else {
                $out .= $chunk;
                $i += $b;
            }
        }
        // Append remaining tail bits unchanged (will be padded later).
        if ($i < $n) {
            $out .= substr($bits, $i);
        }

        return $out;
    }

    // ---------------- Reed-Solomon ----------------

    /**
     * Reed-Solomon ECC over GF(2^m).
     *
     * @param  list<int>  $data
     * @return list<int>  ECC codewords
     */
    public static function reedSolomonEncode(array $data, int $eccLen, int $bitsPerCw, int $primPoly): array
    {
        $size = 1 << $bitsPerCw;
        [$logTable, $expTable] = self::gfTables($size, $primPoly);

        // Generator polynomial coefficients.
        $gen = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                if ($c !== 0) {
                    $next[$j] = $c ^ self::gfMul($c, 0, $logTable, $expTable, $size); // dummy, replaced below
                }
            }
            // Multiply gen × (x - α^i) = gen × x + gen × α^i (XOR).
            $alpha_i = $expTable[$i];
            $newGen = [];
            $newGen[] = $gen[0]; // x^len term = gen[0]
            for ($j = 1; $j < count($gen); $j++) {
                $newGen[$j] = $gen[$j] ^ self::gfMul($gen[$j - 1], $alpha_i, $logTable, $expTable, $size);
            }
            $newGen[count($gen)] = self::gfMul($gen[count($gen) - 1], $alpha_i, $logTable, $expTable, $size);
            $gen = $newGen;
        }

        // Polynomial division: data || zeros divided by gen.
        $msg = array_merge($data, array_fill(0, $eccLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $msg[$i];
            if ($coef !== 0) {
                for ($j = 0; $j < count($gen); $j++) {
                    $msg[$i + $j] ^= self::gfMul($gen[$j], $coef, $logTable, $expTable, $size);
                }
            }
        }

        return array_slice($msg, count($data), $eccLen);
    }

    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function gfTables(int $size, int $primPoly): array
    {
        static $cache = [];
        $key = "$size:$primPoly";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $exp = array_fill(0, $size * 2, 0);
        $log = array_fill(0, $size, 0);
        $x = 1;
        for ($i = 0; $i < $size - 1; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & $size) {
                $x ^= $primPoly;
            }
        }
        for ($i = $size - 1; $i < 2 * $size; $i++) {
            $exp[$i] = $exp[$i - ($size - 1)];
        }

        return $cache[$key] = [$log, $exp];
    }

    /** @param list<int> $logTable @param list<int> $expTable */
    private static function gfMul(int $a, int $b, array $logTable, array $expTable, int $size): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $expTable[($logTable[$a] + $logTable[$b]) % ($size - 1)];
    }

    // ---------------- Matrix layout ----------------

    /**
     * Build Compact Aztec matrix:
     *  1. Bullseye finder (9×9) at center
     *  2. Mode message ring (around 9×9 → 11×11)
     *  3. Orientation marks (4 corners of 11×11)
     *  4. Data spiral (2-module-thick layers around 11×11)
     */
    private function buildMatrix(string $bitStream, int $layers): array
    {
        $size = $this->size;
        $center = intdiv($size, 2);
        $m = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Bullseye 9×9 (Chebyshev distance pattern).
        for ($r = $center - 4; $r <= $center + 4; $r++) {
            for ($c = $center - 4; $c <= $center + 4; $c++) {
                $d = max(abs($r - $center), abs($c - $center));
                $m[$r][$c] = ($d % 2 === 0);
            }
        }

        // 2. Mode message — build 8 information bits + RS over GF(16).
        $modeBits = $this->buildModeMessage($layers);
        // Place 28 mode bits + 4 orientation cells in 11×11 ring around 9×9.
        $this->placeModeMessage($m, $modeBits, $center);

        // 3. Data spiral around 11×11 core.
        $this->placeDataSpiral($m, $bitStream, $center, $layers);

        return $m;
    }

    /**
     * Build the 28-bit mode message for compact:
     *  - 2 bits: layers - 1 (so 1L=00, 2L=01, 3L=10, 4L=11)
     *  - 6 bits: dataCodewords - 1
     *  - 20 bits: RS ECC over GF(16), generator built on the fly
     *
     * Returns 28-bit string MSB-first.
     */
    private function buildModeMessage(int $layers): string
    {
        $info = (($layers - 1) << 6) | ($this->dataCodewords - 1);
        $infoBits = str_pad(decbin($info), 8, '0', STR_PAD_LEFT);
        // 2 codewords × 4 bits = 8 bits info.
        $infoCw = [bindec(substr($infoBits, 0, 4)), bindec(substr($infoBits, 4, 4))];
        // 5 ECC codewords for compact (per ISO §6.3.1).
        $eccCw = self::reedSolomonEncode($infoCw, 5, 4, 0x13); // GF(16) x^4+x+1.
        $allCw = array_merge($infoCw, $eccCw);
        $bits = '';
        foreach ($allCw as $cw) {
            $bits .= str_pad(decbin($cw), 4, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Place 28 mode message bits in the 11×11 ring around the 9×9 bullseye.
     *
     * Compact Aztec mode message layout (per ISO §6.3.2):
     *  - 4 sides of the ring, 7 mode bits per side
     *  - Reading direction: top→right→bottom→left, each side left-to-right
     *    либо top-to-bottom
     *  - Center column/row of each side is скипnut (orientation marker позиции
     *    в этом упрощённом impl — фиксированные corner cells)
     *
     * @param  list<list<bool>>  $m
     */
    private function placeModeMessage(array &$m, string $bits, int $center): void
    {
        // Inner core extends from (center-5, center-5) to (center+5, center+5) = 11×11.
        $top = $center - 5;
        $left = $center - 5;
        $bot = $center + 5;
        $right = $center + 5;

        // Orientation: place 4 corner cells of 11×11.
        // Standard compact orientation pattern (Acrobat-compatible):
        //   TL: black, TR: black, BR: white, BL: black
        $m[$top][$left] = true;
        $m[$top][$right] = true;
        $m[$bot][$right] = false;
        $m[$bot][$left] = true;

        $idx = 0;
        // Top side: row=top, cols from left+1 to right-1 (7 cells, skipping corners).
        for ($c = $left + 1; $c < $right; $c++) {
            $m[$top][$c] = ($bits[$idx++] === '1');
        }
        // Right side: col=right, rows from top+1 to bot-1.
        for ($r = $top + 1; $r < $bot; $r++) {
            $m[$r][$right] = ($bits[$idx++] === '1');
        }
        // Bottom side: row=bot, cols from right-1 down to left+1 (reverse).
        for ($c = $right - 1; $c > $left; $c--) {
            if ($idx < 28) {
                $m[$bot][$c] = ($bits[$idx++] === '1');
            }
        }
        // Left side: col=left, rows from bot-1 up to top+1 (reverse).
        for ($r = $bot - 1; $r > $top; $r--) {
            if ($idx < 28) {
                $m[$r][$left] = ($bits[$idx++] === '1');
            }
        }
    }

    /**
     * Place data spiral around the 11×11 core. Each layer is 2 modules thick.
     *
     * Per ISO §6.5, data fills in 2-cell pairs ("domino" patterns) in a
     * specific spiraling order. Simplified implementation: fill each layer
     * ring in CCW direction, 2 cells deep per step.
     *
     * @param  list<list<bool>>  $m
     */
    private function placeDataSpiral(array &$m, string $bits, int $center, int $layers): void
    {
        $bitIdx = 0;
        $n = strlen($bits);
        // Layer L wraps inner (innerHalf-block) с outer extent innerHalf+2.
        // Sides are partitioned так чтобы не overlap (right covers full height,
        // others exclude corners уже occupied by right/bottom).
        for ($L = 1; $L <= $layers; $L++) {
            $innerHalf = 5 + 2 * ($L - 1);
            $outerHalf = $innerHalf + 2;

            // Right side (2 cols, full height): rows 0..size-1, cols innerHalf+1, innerHalf+2.
            for ($r = $center - $outerHalf; $r <= $center + $outerHalf; $r++) {
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$r][$center + $innerHalf + 1] = ($bits[$bitIdx++] === '1');
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$r][$center + $innerHalf + 2] = ($bits[$bitIdx++] === '1');
            }
            // Bottom side (2 rows, excl right corners): rows innerHalf+1, +2; cols -outerHalf..innerHalf.
            for ($c = $center + $innerHalf; $c >= $center - $outerHalf; $c--) {
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$center + $innerHalf + 1][$c] = ($bits[$bitIdx++] === '1');
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$center + $innerHalf + 2][$c] = ($bits[$bitIdx++] === '1');
            }
            // Left side (2 cols, excl bottom corners): rows -outerHalf..innerHalf; cols -innerHalf-1, -2.
            for ($r = $center + $innerHalf; $r >= $center - $outerHalf; $r--) {
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$r][$center - $innerHalf - 1] = ($bits[$bitIdx++] === '1');
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$r][$center - $innerHalf - 2] = ($bits[$bitIdx++] === '1');
            }
            // Top side (2 rows, excl left+right corners): rows -innerHalf-1, -2; cols -innerHalf..innerHalf.
            for ($c = $center - $innerHalf; $c <= $center + $innerHalf; $c++) {
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$center - $innerHalf - 1][$c] = ($bits[$bitIdx++] === '1');
                if ($bitIdx >= $n) {
                    return;
                }
                $m[$center - $innerHalf - 2][$c] = ($bits[$bitIdx++] === '1');
            }
        }
    }
}
