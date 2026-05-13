<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 36-37: QR Code encoder.
 *
 * ISO/IEC 18004:2015.
 *
 * Supported:
 *  - Byte mode (ECI / Kanji / Alphanumeric / Numeric — Phase 38).
 *  - Error correction levels L / M / Q / H:
 *    - V1..V4: все 4 levels.
 *    - V5..V10: только ECC L (V5+ ECC M/Q/H deferred — mixed-block layout).
 *  - Versions 1..10.
 *  - Mask pattern 0 (i+j mod 2 = 0) — single pattern, без best-mask
 *    selection.
 *
 * Output — 2D bool matrix; true = black module.
 */
final class QrEncoder
{
    /**
     * Byte mode data capacity по [version][ecc-level].
     * Capacity = data_codewords - overhead_bytes(2 для V1-9, 3 для V10).
     */
    private const CAPACITY = [
        1 => ['L' => 17, 'M' => 14, 'Q' => 11, 'H' => 7],
        2 => ['L' => 32, 'M' => 26, 'Q' => 20, 'H' => 14],
        3 => ['L' => 53, 'M' => 42, 'Q' => 32, 'H' => 24],
        4 => ['L' => 78, 'M' => 62, 'Q' => 46, 'H' => 34],
        5 => ['L' => 106],
        6 => ['L' => 134],
        7 => ['L' => 154],
        8 => ['L' => 192],
        9 => ['L' => 230],
        10 => ['L' => 271],
    ];

    /**
     * ECC parameters: [data_codewords, total_codewords, ecc_per_block, num_blocks].
     * Indexed by [version][ecc-level-string].
     */
    private const ECC_PARAMS = [
        1 => [
            'L' => [19, 26, 7, 1],
            'M' => [16, 26, 10, 1],
            'Q' => [13, 26, 13, 1],
            'H' => [9, 26, 17, 1],
        ],
        2 => [
            'L' => [34, 44, 10, 1],
            'M' => [28, 44, 16, 1],
            'Q' => [22, 44, 22, 1],
            'H' => [16, 44, 28, 1],
        ],
        3 => [
            'L' => [55, 70, 15, 1],
            'M' => [44, 70, 26, 1],
            'Q' => [34, 70, 18, 2],
            'H' => [26, 70, 22, 2],
        ],
        4 => [
            'L' => [80, 100, 20, 1],
            'M' => [64, 100, 18, 2],
            'Q' => [48, 100, 26, 2],
            'H' => [36, 100, 16, 4],
        ],
        // V5+ only ECC L (mixed-block layout для M/Q/H deferred).
        5 => ['L' => [108, 134, 26, 1]],
        6 => ['L' => [136, 172, 18, 2]],
        7 => ['L' => [156, 196, 20, 2]],
        8 => ['L' => [194, 242, 24, 2]],
        9 => ['L' => [232, 292, 30, 2]],
        10 => ['L' => [274, 346, 18, 2]],
    ];

    /**
     * Position alignment center coordinates per version (1..10).
     * V1 не имеет alignment patterns кроме finders.
     */
    private const ALIGN_POSITIONS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    public readonly int $version;

    public readonly int $size;

    public readonly QrEccLevel $eccLevel;

    /**
     * Matrix: $modules[y][x] = bool.
     *
     * @var list<list<bool>>
     */
    private array $modules;

    public function __construct(public readonly string $data, ?QrEccLevel $eccLevel = null)
    {
        if ($data === '') {
            throw new \InvalidArgumentException('QR input must be non-empty');
        }
        $this->eccLevel = $eccLevel ?? QrEccLevel::L;
        $byteLen = strlen($data);
        $eccKey = $this->eccLevel->value;

        // Pick smallest version supporting data в byte mode + chosen ECC level.
        $version = null;
        foreach (self::CAPACITY as $v => $perLevel) {
            $cap = $perLevel[$eccKey] ?? null;
            if ($cap !== null && $byteLen <= $cap) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException(sprintf(
                'QR input too long (%d bytes) for ECC %s — max %d at this level',
                $byteLen,
                $eccKey,
                self::maxCapacityForLevel($eccKey),
            ));
        }

        $this->version = $version;
        $this->size = 17 + 4 * $version;

        // 1. Encode data → bitstream.
        $bits = $this->encodeData($data, $version);

        // 2. Bitstream → codewords; apply ECC.
        $codewords = $this->bitsToCodewords($bits);
        $eccCodewords = $this->applyReedSolomon($codewords, $version);
        $allCodewords = $this->interleaveBlocks($codewords, $eccCodewords, $version);

        // 3. Build matrix: function patterns + data placement + mask.
        $matrix = $this->buildMatrix($allCodewords, $version);
        $this->modules = $matrix;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Maximum data byte capacity для ECC level (max version reached).
     */
    public static function maxCapacityForLevel(string $level): int
    {
        $max = 0;
        foreach (self::CAPACITY as $perLevel) {
            $cap = $perLevel[$level] ?? 0;
            if ($cap > $max) {
                $max = $cap;
            }
        }

        return $max;
    }

    /**
     * @return list<list<bool>>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    public function module(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }

    // ── Data encoding ────────────────────────────────────────────────────

    /**
     * Encodes data в bit stream: mode indicator + char count + bytes +
     * terminator + padding.
     */
    private function encodeData(string $data, int $version): string
    {
        $bits = '';
        // Mode indicator: byte mode = 0100.
        $bits .= '0100';
        // Character count indicator: 8 bits for V1-9, 16 bits for V10+ (byte mode).
        $charCountBits = $version < 10 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($data)), $charCountBits, '0', STR_PAD_LEFT);
        // Data bytes как 8-bit chunks.
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Terminator: up to 4 zeros (or fewer if at capacity).
        [$dataCodewords] = self::ECC_PARAMS[$version][$this->eccLevel->value];
        $capacityBits = $dataCodewords * 8;
        $terminatorLen = min(4, $capacityBits - strlen($bits));
        $bits .= str_repeat('0', $terminatorLen);
        // Pad to byte boundary.
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        // Fill remaining capacity с alternating padding bytes 0xEC, 0x11.
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return $bits;
    }

    /**
     * @return list<int>  Byte values (0..255).
     */
    private function bitsToCodewords(string $bits): array
    {
        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        return $codewords;
    }

    // ── Reed-Solomon ─────────────────────────────────────────────────────

    /**
     * Pre-computed GF(256) log/antilog tables (primitive 0x11D, generator 2).
     *
     * @var list<int>
     */
    private static array $gfExp;

    /** @var list<int> */
    private static array $gfLog;

    private static function initGf(): void
    {
        if (isset(self::$gfExp)) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        // Wrap exp для удобства (no modulo at lookup).
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$gfExp = $exp;
        self::$gfLog = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    /**
     * Generator polynomial для $n ECC codewords.
     *
     * @return list<int>
     */
    private static function rsGenPoly(int $n): array
    {
        self::initGf();
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            $newPoly = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $newPoly[$j] ^= self::gfMul($coef, 1);
                $newPoly[$j + 1] ^= self::gfMul($coef, self::$gfExp[$i]);
            }
            $poly = $newPoly;
        }

        return $poly;
    }

    /**
     * Computes ECC codewords для data block.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function rsCompute(array $data, int $n): array
    {
        self::initGf();
        $gen = self::rsGenPoly($n);
        $remainder = array_merge($data, array_fill(0, $n, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $remainder[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j < count($gen); $j++) {
                $remainder[$i + $j] ^= self::gfMul($gen[$j], $coef);
            }
        }

        return array_slice($remainder, count($data));
    }

    /**
     * Applies Reed-Solomon ECC к codewords. Returns list of ECC blocks
     * (одна на каждый data block для версий с multi-block layout).
     *
     * @param  list<int>  $codewords  Data codewords.
     * @return list<list<int>>  ECC blocks.
     */
    private function applyReedSolomon(array $codewords, int $version): array
    {
        [$totalData, , $eccPerBlock, $numBlocks] = self::ECC_PARAMS[$version][$this->eccLevel->value];
        $blockSize = intdiv($totalData, $numBlocks);

        $blocks = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $start = $b * $blockSize;
            $dataBlock = array_slice($codewords, $start, $blockSize);
            $blocks[] = self::rsCompute($dataBlock, $eccPerBlock);
        }

        return $blocks;
    }

    /**
     * Interleaves data + ECC blocks по spec.
     *
     * @param  list<int>  $dataCodewords
     * @param  list<list<int>>  $eccBlocks
     * @return list<int>
     */
    private function interleaveBlocks(array $dataCodewords, array $eccBlocks, int $version): array
    {
        [$totalData, , , $numBlocks] = self::ECC_PARAMS[$version][$this->eccLevel->value];
        $blockSize = intdiv($totalData, $numBlocks);
        $dataBlocks = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $dataBlocks[] = array_slice($dataCodewords, $b * $blockSize, $blockSize);
        }

        // Interleave columnwise.
        $result = [];
        for ($i = 0; $i < $blockSize; $i++) {
            foreach ($dataBlocks as $blk) {
                $result[] = $blk[$i];
            }
        }
        $eccLen = count($eccBlocks[0]);
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($eccBlocks as $blk) {
                $result[] = $blk[$i];
            }
        }

        return $result;
    }

    // ── Matrix construction ──────────────────────────────────────────────

    /**
     * @param  list<int>  $codewords
     * @return list<list<bool>>
     */
    private function buildMatrix(array $codewords, int $version): array
    {
        $size = $this->size;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        // Reserved flags: true = function pattern, не должно overwrit'иться
        // data placement'ом.
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns (3 corners — TL, TR, BL).
        $this->placeFinder($matrix, $reserved, 0, 0);
        $this->placeFinder($matrix, $reserved, $size - 7, 0);
        $this->placeFinder($matrix, $reserved, 0, $size - 7);

        // 2. Separators (white 1-module rings вокруг finders) — already
        //    handled by reserved+false default.
        $this->reserveSeparators($reserved, $size);

        // 3. Timing patterns (alternating row/col 6).
        for ($i = 8; $i < $size - 8; $i++) {
            $on = $i % 2 === 0;
            $matrix[6][$i] = $on;
            $matrix[$i][6] = $on;
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        // 4. Alignment patterns (для V2+).
        $positions = self::ALIGN_POSITIONS[$version];
        $n = count($positions);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                // Skip углы где уже есть finder pattern.
                if (($r === 0 && $c === 0) ||
                    ($r === 0 && $c === $n - 1) ||
                    ($r === $n - 1 && $c === 0)) {
                    continue;
                }
                $this->placeAlignment($matrix, $reserved, $positions[$c], $positions[$r]);
            }
        }

        // 5. Dark module at (8, 4*ver + 9).
        $darkY = 4 * $version + 9;
        $matrix[$darkY][8] = true;
        $reserved[$darkY][8] = true;

        // 6. Reserve format info regions (15 bits around TL finder + по 7
        //    bits возле TR/BL finder'ов).
        $this->reserveFormatInfo($reserved, $size);

        // 7. Place data bits using zigzag pattern.
        $this->placeData($matrix, $reserved, $codewords);

        // 8. Apply mask 0 (i+j mod 2 == 0) к non-reserved модулям.
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                if (($x + $y) % 2 === 0) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }

        // 9. Write format info bits (ECC level L = 01, mask 0 = 000).
        $this->writeFormatInfo($matrix, $size);

        return $matrix;
    }

    /**
     * @param  array<int, array<int, bool>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function placeFinder(array &$matrix, array &$reserved, int $x, int $y): void
    {
        for ($dy = 0; $dy < 7; $dy++) {
            for ($dx = 0; $dx < 7; $dx++) {
                $isBlack = $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 ||
                    ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4);
                $matrix[$y + $dy][$x + $dx] = $isBlack;
                $reserved[$y + $dy][$x + $dx] = true;
            }
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function reserveSeparators(array &$reserved, int $size): void
    {
        // TL: row 7, col 0-7; col 7, row 0-7.
        for ($i = 0; $i < 8; $i++) {
            $reserved[7][$i] = true;
            $reserved[$i][7] = true;
        }
        // TR: row 7, col size-8..size-1; col size-8, row 0-7.
        for ($i = 0; $i < 8; $i++) {
            $reserved[7][$size - 1 - $i] = true;
            $reserved[$i][$size - 8] = true;
        }
        // BL: row size-8, col 0-7; col 7, row size-8..size-1.
        for ($i = 0; $i < 8; $i++) {
            $reserved[$size - 8][$i] = true;
            $reserved[$size - 1 - $i][7] = true;
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function placeAlignment(array &$matrix, array &$reserved, int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $absX = $cx + $dx;
                $absY = $cy + $dy;
                if ($reserved[$absY][$absX] ?? false) {
                    continue;
                }
                $isBlack = max(abs($dx), abs($dy)) !== 1;
                $matrix[$absY][$absX] = $isBlack;
                $reserved[$absY][$absX] = true;
            }
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function reserveFormatInfo(array &$reserved, int $size): void
    {
        // TL format: row 8 cols 0..8 + col 8 rows 0..8.
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        // TR format: row 8 cols size-8..size-1.
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
        }
        // BL format: col 8 rows size-7..size-1.
        for ($i = 0; $i < 7; $i++) {
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    /**
     * Data placement: zigzag right-to-left, 2-col strips, alternating up/down
     * direction, skip col 6 (timing).
     *
     * @param  array<int, array<int, bool>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     * @param  list<int>  $codewords
     */
    private function placeData(array &$matrix, array &$reserved, array $codewords): void
    {
        $size = $this->size;
        // Convert codewords → bitstream.
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $bitIdx = 0;
        $upward = true;
        for ($colRight = $size - 1; $colRight >= 1; $colRight -= 2) {
            if ($colRight === 6) {
                $colRight--; // Skip timing column.
            }
            $rowRange = $upward ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($rowRange as $y) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $x = $colRight - $dx;
                    if ($reserved[$y][$x]) {
                        continue;
                    }
                    if ($bitIdx < strlen($bits)) {
                        $matrix[$y][$x] = $bits[$bitIdx] === '1';
                        $bitIdx++;
                    }
                    // Reserved=false означает данные. Mark теперь reserved,
                    // чтобы mask только применился к data.
                    // (Mark applied separately.)
                }
            }
            $upward = ! $upward;
        }
    }

    /**
     * Format info: ECC L = 01, mask = 000. 5 bits → BCH(15,5) → 15 bits;
     * XOR с mask 101010000010010 (spec).
     *
     * @param  array<int, array<int, bool>>  $matrix
     */
    private function writeFormatInfo(array &$matrix, int $size): void
    {
        // ECC level (2 bits) + mask 000 (3 bits) = 5 bits.
        // L=01, M=00, Q=11, H=10 — left-shifted в high 2 bits.
        $data = ($this->eccLevel->formatBits() << 3) | 0b000;
        // BCH(15,5) — divide by generator 0b10100110111.
        $bits = $data << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($bits & (1 << ($i + 10))) {
                $bits ^= 0b10100110111 << $i;
            }
        }
        $format = (($data << 10) | $bits) ^ 0b101010000010010;

        // Write 15 bits в two locations (TL и TR/BL).
        $bitArr = [];
        for ($i = 14; $i >= 0; $i--) {
            $bitArr[] = (($format >> $i) & 1) === 1;
        }

        // TL: bits 0..5 в col 8 rows 0..5 (skip row 6),
        //     bit 6 в col 8 row 7, bits 7..8 в col 8 row 8 и row 7? Spec:
        // Spec exact mapping:
        // bits 0..7: TL — col 8 + TR row 8.
        // bits 8..14: TL — row 8 + BL col 8.
        // Здесь делаю по spec mapping ISO/IEC 18004 §8.9 Table 23.

        // TL row 8 cols (right to left от col 8 to col 0, skip col 6):
        // bit 0 -> (8, 0), bit 1 -> (8, 1), ..., bit 5 -> (8, 5),
        // bit 6 -> (8, 7), bit 7 -> (8, 8), bit 8 -> (7, 8),
        // bit 9 -> (5, 8), bit 10 -> (4, 8), ..., bit 14 -> (0, 8).
        //
        // We'll just hard-code position list.
        $tlPositions = [
            [0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8],
            [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0],
        ];
        // Bits 0..14 → tlPositions in order.
        // bitArr[0] = highest bit (14), reverse to get low-to-high.
        $bitsLowToHigh = array_reverse($bitArr);
        foreach ($tlPositions as $i => [$x, $y]) {
            $matrix[$y][$x] = $bitsLowToHigh[$i];
        }

        // TR + BL duplicate.
        // Bits 0..7 → BL col 8 from bottom: (8, size-1) down to (8, size-7).
        // Bits 8..14 → TR row 8: (size-8, 8) to (size-1, 8).
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = $bitsLowToHigh[$i];
        }
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 8 + $i] = $bitsLowToHigh[7 + $i];
        }
    }
}
