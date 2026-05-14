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

    public function __construct(public readonly string $data, int $minEccPercent = 23)
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Aztec input must be non-empty');
        }

        // 1. High-level encode → bit string.
        $bits = self::encodeToBits($data);

        // 2. Pick smallest compact size accommodating data + ECC overhead.
        //    Per ZXing Encoder.java algorithm.
        $eccBits = intdiv(strlen($bits) * $minEccPercent, 100) + 11;
        $totalSizeBits = strlen($bits) + $eccBits;

        $layers = null;
        $wordSize = 0;
        $totalBitsInLayer = 0;
        $stuffedBits = '';
        foreach ([1, 2, 3, 4] as $L) {
            $totalBitsInLayerForL = ((88) + 16 * $L) * $L; // compact formula
            if ($totalSizeBits > $totalBitsInLayerForL) {
                continue;
            }
            $ws = self::COMPACT_CW_BITS[$L];
            if ($wordSize !== $ws) {
                $wordSize = $ws;
                $stuffedBits = self::stuffBits($bits, $ws);
            }
            $usable = $totalBitsInLayerForL - ($totalBitsInLayerForL % $ws);
            // Compact ограничен 64 message words.
            if (strlen($stuffedBits) > $ws * 64) {
                continue;
            }
            if (strlen($stuffedBits) + $eccBits <= $usable) {
                $layers = $L;
                $totalBitsInLayer = $totalBitsInLayerForL;
                break;
            }
        }
        if ($layers === null) {
            throw new \InvalidArgumentException('Aztec compact capacity exceeded; use Full Aztec');
        }

        $this->layers = $layers;
        $this->size = 11 + 4 * $layers; // 15, 19, 23, 27.

        // 3. Generate check (ECC) words filling totalBitsInLayer.
        $messageBits = self::generateCheckWords($stuffedBits, $totalBitsInLayer, $wordSize);

        // 4. Generate mode message.
        $messageSizeInWords = intdiv(strlen($stuffedBits), $wordSize);
        $this->dataCodewords = $messageSizeInWords;
        $this->eccCodewords = intdiv($totalBitsInLayer, $wordSize) - $messageSizeInWords;
        $modeMessage = self::generateCompactModeMessage($layers, $messageSizeInWords);

        // 5. Build matrix per ZXing algorithm: spiral data, then mode, then bullseye.
        $this->matrix = $this->buildMatrix($messageBits, $modeMessage, $layers);
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
     * Bit stuffing per ISO/IEC 24778 §6.4.4, ported from ZXing Encoder.java.
     *
     * Iterates wordSize bits at a time. If first (wordSize-1) bits are all
     * same (1...1 or 0...0), modify the last bit of the codeword:
     *  - All-ones: emit codeword & mask (clear last bit), back up 1 bit.
     *  - All-zeros: emit codeword | 1 (set last bit), back up 1 bit.
     * Otherwise emit codeword as-is.
     *
     * Pads with all-1s if last input bit short of word boundary.
     */
    public static function stuffBits(string $bits, int $wordSize): string
    {
        $n = strlen($bits);
        $mask = (1 << $wordSize) - 2; // all-1s except LSB
        $out = '';
        for ($i = 0; $i < $n; $i += $wordSize) {
            $word = 0;
            for ($j = 0; $j < $wordSize; $j++) {
                if ($i + $j >= $n || $bits[$i + $j] === '1') {
                    $word |= 1 << ($wordSize - 1 - $j);
                }
            }
            if (($word & $mask) === $mask) {
                // First wordSize-1 bits all 1: clear last bit, defer 1 input bit.
                $out .= str_pad(decbin($word & $mask), $wordSize, '0', STR_PAD_LEFT);
                $i--;
            } elseif (($word & $mask) === 0) {
                // First wordSize-1 bits all 0: set last bit, defer 1 input bit.
                $out .= str_pad(decbin($word | 1), $wordSize, '0', STR_PAD_LEFT);
                $i--;
            } else {
                $out .= str_pad(decbin($word), $wordSize, '0', STR_PAD_LEFT);
            }
        }

        return $out;
    }

    /**
     * Generate check (ECC) words via Reed-Solomon ported from ZXing
     * ReedSolomonEncoder.java + GenericGFPoly.
     *
     * Convention: generator polynomial stored MSB-first (leading coef
     * at index 0). Synthetic division aligns naturally.
     */
    public static function generateCheckWords(string $messageBits, int $totalBits, int $wordSize): string
    {
        $messageSizeInWords = intdiv(strlen($messageBits), $wordSize);
        $totalWords = intdiv($totalBits, $wordSize);
        $eccLen = $totalWords - $messageSizeInWords;
        $primPoly = match ($wordSize) {
            4 => 0x13,
            6 => 0x43,
            8 => 0x12D,
            default => throw new \InvalidArgumentException('Unsupported word size: ' . $wordSize),
        };
        $size = 1 << $wordSize;
        [$logTable, $expTable] = self::gfTables($size, $primPoly);

        // Convert message bits → ints (MSB first within each word).
        $messageWords = [];
        for ($i = 0; $i < $messageSizeInWords; $i++) {
            $val = 0;
            for ($j = 0; $j < $wordSize; $j++) {
                if ($messageBits[$i * $wordSize + $j] === '1') {
                    $val |= 1 << ($wordSize - 1 - $j);
                }
            }
            $messageWords[] = $val;
        }

        // Build generator polynomial — MSB-first: [1, α^1] × [1, α^2] × ...
        // gen[0] = leading coefficient (highest degree), gen[eccLen] = constant.
        // For Aztec, generator base is 1 (α^1, α^2, ..., α^eccLen).
        $gen = [1];
        for ($d = 1; $d <= $eccLen; $d++) {
            // Multiply current gen by (x + α^d). Convention: MSB first.
            // [g0, g1, ..., gk] × [1, α^d] = [g0, g0*α^d + g1, g1*α^d + g2, ..., gk*α^d].
            $next = array_fill(0, count($gen) + 1, 0);
            $next[0] = $gen[0];
            for ($j = 1; $j < count($gen); $j++) {
                $next[$j] = self::gfMul($gen[$j - 1], $expTable[$d], $logTable, $expTable, $size) ^ $gen[$j];
            }
            $next[count($gen)] = self::gfMul($gen[count($gen) - 1], $expTable[$d], $logTable, $expTable, $size);
            $gen = $next;
        }

        // Synthetic division: msg × x^eccLen / gen, taking remainder.
        // Buffer: messageWords (length k) followed by eccLen zeros = (k + eccLen) = totalWords.
        $buf = array_merge($messageWords, array_fill(0, $eccLen, 0));
        for ($i = 0; $i < $messageSizeInWords; $i++) {
            $coef = $buf[$i];
            if ($coef !== 0) {
                // gen has length eccLen+1, gen[0]=1 (leading).
                // For j=0..eccLen: buf[i+j] -= gen[j] × coef.
                // Since gen[0]=1, buf[i] -= coef → buf[i] = 0.
                for ($j = 0; $j < count($gen); $j++) {
                    $buf[$i + $j] ^= self::gfMul($gen[$j], $coef, $logTable, $expTable, $size);
                }
            }
        }
        $eccWords = array_slice($buf, $messageSizeInWords, $eccLen);

        // Output bits: startPad + message + ecc.
        $startPad = $totalBits % $wordSize;
        $bits = str_repeat('0', $startPad);
        foreach ($messageWords as $w) {
            $bits .= str_pad(decbin($w), $wordSize, '0', STR_PAD_LEFT);
        }
        foreach ($eccWords as $w) {
            $bits .= str_pad(decbin($w), $wordSize, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Generate mode message bits для Compact Aztec.
     * 2 bits layers-1 + 6 bits messageSizeInWords-1 + 20 bits RS ECC = 28 bits.
     */
    public static function generateCompactModeMessage(int $layers, int $messageSizeInWords): string
    {
        $info = (($layers - 1) << 6) | ($messageSizeInWords - 1);
        $infoBits = str_pad(decbin($info), 8, '0', STR_PAD_LEFT);

        return self::generateCheckWords($infoBits, 28, 4);
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
     * Build Compact Aztec matrix per ZXing Encoder.java algorithm.
     *
     * Order (важно!): data spiral first → mode message → bullseye + orient.
     *
     * @param  string  $messageBits  bits для data spiral (incl. RS ECC)
     * @param  string  $modeBits     28-bit mode message
     */
    private function buildMatrix(string $messageBits, string $modeBits, int $layers): array
    {
        $matrixSize = $this->size;
        $center = intdiv($matrixSize, 2);
        $m = array_fill(0, $matrixSize, array_fill(0, $matrixSize, false));
        $baseMatrixSize = 11 + 4 * $layers; // compact base size = matrixSize for compact.

        // 1. Draw data bits — 2-cell dominoes around layers (outermost first).
        $rowOffset = 0;
        for ($i = 0; $i < $layers; $i++) {
            $rowSize = ($layers - $i) * 4 + 9; // compact formula
            for ($j = 0; $j < $rowSize; $j++) {
                $columnOffset = $j * 2;
                for ($k = 0; $k < 2; $k++) {
                    // Top side
                    if (self::bitAt($messageBits, $rowOffset + $columnOffset + $k)) {
                        // matrix.set(x=col, y=row) → $m[row][col] = true
                        $m[$i * 2 + $j][$i * 2 + $k] = true;
                    }
                    // Right side
                    if (self::bitAt($messageBits, $rowOffset + $rowSize * 2 + $columnOffset + $k)) {
                        $m[$baseMatrixSize - 1 - $i * 2 - $k][$i * 2 + $j] = true;
                    }
                    // Bottom side
                    if (self::bitAt($messageBits, $rowOffset + $rowSize * 4 + $columnOffset + $k)) {
                        $m[$baseMatrixSize - 1 - $i * 2 - $j][$baseMatrixSize - 1 - $i * 2 - $k] = true;
                    }
                    // Left side
                    if (self::bitAt($messageBits, $rowOffset + $rowSize * 6 + $columnOffset + $k)) {
                        $m[$i * 2 + $k][$baseMatrixSize - 1 - $i * 2 - $j] = true;
                    }
                }
            }
            $rowOffset += $rowSize * 8;
        }

        // 2. Draw mode message (28 bits for compact) в 11×11 ring around 9×9 bullseye.
        $this->drawCompactModeMessage($m, $center, $modeBits);

        // 3. Draw bullseye (compact: size=5, drawn AFTER data + mode).
        $this->drawBullsEye($m, $center, 5);

        return $m;
    }

    private static function bitAt(string $bits, int $i): bool
    {
        return isset($bits[$i]) && $bits[$i] === '1';
    }

    /**
     * Draw concentric square frames at distance 0, 2, 4, ..., size-1 from
     * center. Plus 6 fixed orientation cells at 11×11 corners (compact).
     */
    private function drawBullsEye(array &$m, int $center, int $size): void
    {
        for ($i = 0; $i < $size; $i += 2) {
            for ($j = $center - $i; $j <= $center + $i; $j++) {
                $m[$center - $i][$j] = true;
                $m[$center + $i][$j] = true;
                $m[$j][$center - $i] = true;
                $m[$j][$center + $i] = true;
            }
        }
        // 6 orientation cells (compact-specific positions).
        // Note: ZXing uses (col, row) but my matrix is [row][col].
        $m[$center - $size][$center - $size] = true;
        $m[$center - $size][$center - $size + 1] = true;
        $m[$center - $size + 1][$center - $size] = true;
        $m[$center - $size][$center + $size] = true;
        $m[$center - $size + 1][$center + $size] = true;
        $m[$center + $size - 1][$center + $size] = true;
    }

    /**
     * Place 28-bit compact mode message в 11×11 ring around 9×9 bullseye.
     * Top edge: bits 0..6 at cols c-3..c+3, row c-5.
     * Right edge: bits 7..13 at col c+5, rows c-3..c+3.
     * Bottom edge: bits 14..20 at cols c+3..c-3 (reverse), row c+5.
     * Left edge: bits 21..27 at col c-5, rows c+3..c-3 (reverse).
     */
    private function drawCompactModeMessage(array &$m, int $center, string $modeBits): void
    {
        for ($i = 0; $i < 7; $i++) {
            $offset = $center - 3 + $i;
            if (self::bitAt($modeBits, $i)) {
                $m[$center - 5][$offset] = true;
            }
            if (self::bitAt($modeBits, $i + 7)) {
                $m[$offset][$center + 5] = true;
            }
            if (self::bitAt($modeBits, 20 - $i)) {
                $m[$center + 5][$offset] = true;
            }
            if (self::bitAt($modeBits, 27 - $i)) {
                $m[$offset][$center - 5] = true;
            }
        }
    }
}
