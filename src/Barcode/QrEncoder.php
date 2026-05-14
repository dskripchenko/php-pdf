<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 36-38: QR Code encoder.
 *
 * ISO/IEC 18004:2015.
 *
 * Supported:
 *  - Mode auto-detection:
 *    - Numeric mode (0-9 only) — 3 chars / 10 bits.
 *    - Alphanumeric (0-9, A-Z, space, $%*+-./:) — 2 chars / 11 bits.
 *    - Byte mode (everything else) — 1 char / 8 bits.
 *    - Kanji / ECI / structured-append — deferred.
 *  - Error correction levels L / M / Q / H:
 *    - V1..V4: все 4 levels.
 *    - V5..V10: все 4 ECC levels (L/M/Q/H) с mixed-block layout (Phase 146).
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
        5 => ['L' => 106, 'M' => 84, 'Q' => 60, 'H' => 44],
        6 => ['L' => 134, 'M' => 106, 'Q' => 74, 'H' => 58],
        7 => ['L' => 154, 'M' => 122, 'Q' => 86, 'H' => 64],
        8 => ['L' => 192, 'M' => 152, 'Q' => 108, 'H' => 84],
        9 => ['L' => 230, 'M' => 180, 'Q' => 130, 'H' => 98],
        10 => ['L' => 271, 'M' => 213, 'Q' => 151, 'H' => 119],
    ];

    /**
     * ECC parameters: [data_codewords, total_codewords, ecc_per_block, num_blocks_first_group].
     *
     * Phase 146: для mixed-block versions (e.g., V5-Q = 2×15 + 2×16), entry has
     * the SAME 4-element signature but if mixed, a 5th element appears:
     *   [..., num_blocks_first_group, num_blocks_second_group, data_per_block_second]
     * Backward compat: legacy format remains 4-element.
     *
     * Block layout convention:
     *  - 4-element [data, total, ecc, num]: всех `num` blocks have data/num size each.
     *  - 6-element [data, total, ecc, g1Count, g2Count, g2BlockSize]: group1 blocks
     *    have (data - g2Count*g2BlockSize) / g1Count size each; group2 blocks have
     *    g2BlockSize each. All blocks share same `ecc` per-block count.
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
        5 => [
            'L' => [108, 134, 26, 1],
            'M' => [86, 134, 24, 2],
            'Q' => [62, 134, 18, 2, 2, 16],   // 2×15 + 2×16
            'H' => [46, 134, 22, 2, 2, 12],   // 2×11 + 2×12
        ],
        6 => [
            'L' => [136, 172, 18, 2],
            'M' => [108, 172, 16, 4],
            'Q' => [76, 172, 24, 4],
            'H' => [60, 172, 28, 4],
        ],
        7 => [
            'L' => [156, 196, 20, 2],
            'M' => [124, 196, 18, 4],
            'Q' => [88, 196, 18, 2, 4, 15],   // 2×14 + 4×15
            'H' => [66, 196, 26, 4, 1, 14],   // 4×13 + 1×14
        ],
        8 => [
            'L' => [194, 242, 24, 2],
            'M' => [154, 242, 22, 2, 2, 39],  // 2×38 + 2×39
            'Q' => [110, 242, 22, 4, 2, 19],  // 4×18 + 2×19
            'H' => [86, 242, 26, 4, 2, 15],   // 4×14 + 2×15
        ],
        9 => [
            'L' => [232, 292, 30, 2],
            'M' => [182, 292, 22, 3, 2, 37],  // 3×36 + 2×37
            'Q' => [132, 292, 20, 4, 4, 17],  // 4×16 + 4×17
            'H' => [100, 292, 24, 4, 4, 13],  // 4×12 + 4×13
        ],
        10 => [
            'L' => [274, 346, 18, 2, 2, 69],  // 2×68 + 2×69
            'M' => [216, 346, 26, 4, 1, 44],  // 4×43 + 1×44
            'Q' => [154, 346, 24, 6, 2, 20],  // 6×19 + 2×20
            'H' => [122, 346, 28, 6, 2, 16],  // 6×15 + 2×16
        ],
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

    public readonly QrEncodingMode $mode;

    /**
     * Matrix: $modules[y][x] = bool.
     *
     * @var list<list<bool>>
     */
    private array $modules;

    /**
     * Phase 183: Structured Append factory. Splits payload across multiple
     * QR symbols. Each symbol gets 20-bit header: mode 0011 + position (4 bits,
     * 0-based) + total (4 bits, 1-based-1) + parity (8 bits = XOR of all data
     * bytes across all segments).
     *
     * Per ISO/IEC 18004 §7.4.7. Up to 16 symbols.
     *
     * Caller responsible для splitting data + computing global parity:
     *   $parity = QrEncoder::computeStructuredAppendParity($fullData);
     *   $sym1 = QrEncoder::structuredAppend('part1', 0, 3, $parity);
     *   $sym2 = QrEncoder::structuredAppend('part2', 1, 3, $parity);
     *   $sym3 = QrEncoder::structuredAppend('part3', 2, 3, $parity);
     */
    public static function structuredAppend(
        string $data,
        int $position,
        int $total,
        int $parity,
        ?QrEccLevel $eccLevel = null,
        ?QrEncodingMode $mode = null,
    ): self {
        if ($total < 2 || $total > 16) {
            throw new \InvalidArgumentException('QR Structured Append total must be 2..16');
        }
        if ($position < 0 || $position >= $total) {
            throw new \InvalidArgumentException(sprintf(
                'QR Structured Append position %d not в range 0..%d', $position, $total - 1
            ));
        }
        if ($parity < 0 || $parity > 255) {
            throw new \InvalidArgumentException('QR Structured Append parity must be 0..255');
        }

        return new self($data, $eccLevel, $mode, structuredAppend: [
            'position' => $position,
            'total' => $total,
            'parity' => $parity,
        ]);
    }

    /**
     * Phase 183: compute parity for Structured Append — XOR of all data bytes
     * across all symbol segments в concatenated original order.
     */
    public static function computeStructuredAppendParity(string $fullData): int
    {
        $parity = 0;
        for ($i = 0; $i < strlen($fullData); $i++) {
            $parity ^= ord($fullData[$i]);
        }

        return $parity;
    }

    /** @var array{position: int, total: int, parity: int}|null */
    private readonly ?array $structuredAppend;

    public readonly ?int $eciDesignator;

    public function __construct(
        public readonly string $data,
        ?QrEccLevel $eccLevel = null,
        ?QrEncodingMode $mode = null,
        ?array $structuredAppend = null,
        ?int $eciDesignator = null,
    ) {
        $this->structuredAppend = $structuredAppend;
        if ($eciDesignator !== null && ($eciDesignator < 0 || $eciDesignator > 999999)) {
            throw new \InvalidArgumentException('QR ECI designator must be 0..999999');
        }
        $this->eciDesignator = $eciDesignator;
        if ($data === '') {
            throw new \InvalidArgumentException('QR input must be non-empty');
        }
        $this->eccLevel = $eccLevel ?? QrEccLevel::L;
        // Phase 38: auto-detect mode unless explicitly specified.
        $this->mode = $mode ?? QrEncodingMode::detect($data);
        // Validate input matches chosen mode.
        if ($mode !== null) {
            $autoDetected = QrEncodingMode::detect($data);
            if ($mode === QrEncodingMode::Numeric && $autoDetected !== QrEncodingMode::Numeric) {
                throw new \InvalidArgumentException('Input contains non-digit characters; cannot use Numeric mode');
            }
            if ($mode === QrEncodingMode::Alphanumeric &&
                $autoDetected !== QrEncodingMode::Numeric &&
                $autoDetected !== QrEncodingMode::Alphanumeric) {
                throw new \InvalidArgumentException('Input contains characters outside Alphanumeric charset');
            }
        }

        $eccKey = $this->eccLevel->value;
        // Phase 101: для Kanji input, char count = bytes / 2.
        $charCount = $this->mode === QrEncodingMode::Kanji
            ? intdiv(strlen($data), 2)
            : strlen($data);

        // Phase 38: capacity check теперь bit-based, не byte-based:
        // total bits available = data_codewords * 8.
        // bits needed = 4 (mode) + charCountBits + dataBitsFor(charCount).
        $version = null;
        foreach (self::ECC_PARAMS as $v => $perLevel) {
            if (! isset($perLevel[$eccKey])) {
                continue;
            }
            $dataCodewords = $perLevel[$eccKey][0];
            $capacityBits = $dataCodewords * 8;
            // Phase 183: structured append adds 20-bit header.
            $structAppendBits = $this->structuredAppend !== null ? 20 : 0;
            // Phase 184: ECI adds 4-bit mode + 8/16/24-bit designator.
            $eciBits = 0;
            if ($this->eciDesignator !== null) {
                $eciBits = 4 + ($this->eciDesignator <= 127 ? 8 : ($this->eciDesignator <= 16383 ? 16 : 24));
            }
            $needed = $structAppendBits + $eciBits + 4 + $this->mode->charCountIndicatorBits($v) + $this->mode->dataBitsFor($charCount);
            if ($needed <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException(sprintf(
                'QR input too long (%d chars in %s mode) for ECC %s',
                $charCount,
                $this->mode->name,
                $eccKey,
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
        // Phase 183: Structured Append header — prepended до regular mode indicator.
        // Mode 0011 + position (4 bits) + (total - 1) (4 bits) + parity (8 bits) = 20 bits.
        if ($this->structuredAppend !== null) {
            $bits .= '0011';
            $bits .= str_pad(decbin($this->structuredAppend['position']), 4, '0', STR_PAD_LEFT);
            $bits .= str_pad(decbin($this->structuredAppend['total'] - 1), 4, '0', STR_PAD_LEFT);
            $bits .= str_pad(decbin($this->structuredAppend['parity']), 8, '0', STR_PAD_LEFT);
        }
        // Phase 184: ECI header — Mode 0111 + designator (8/16/24 bits depending on value).
        if ($this->eciDesignator !== null) {
            $bits .= '0111';
            $eci = $this->eciDesignator;
            if ($eci <= 127) {
                $bits .= '0'.str_pad(decbin($eci), 7, '0', STR_PAD_LEFT);
            } elseif ($eci <= 16383) {
                $bits .= '10'.str_pad(decbin($eci), 14, '0', STR_PAD_LEFT);
            } else { // up to 999999
                $bits .= '110'.str_pad(decbin($eci), 21, '0', STR_PAD_LEFT);
            }
        }
        // Mode indicator — 4 bits.
        $bits .= str_pad(decbin($this->mode->indicatorBits()), 4, '0', STR_PAD_LEFT);
        // Character count indicator. Phase 101: для Kanji char count =
        // bytes / 2 (each Kanji char encoded в 2 Shift_JIS bytes).
        $charCountBits = $this->mode->charCountIndicatorBits($version);
        $charCount = $this->mode === QrEncodingMode::Kanji
            ? intdiv(strlen($data), 2)
            : strlen($data);
        $bits .= str_pad(decbin($charCount), $charCountBits, '0', STR_PAD_LEFT);

        // Phase 38+101: data encoding по mode.
        $bits .= match ($this->mode) {
            QrEncodingMode::Numeric => self::encodeNumeric($data),
            QrEncodingMode::Alphanumeric => self::encodeAlphanumeric($data),
            QrEncodingMode::Byte => self::encodeByte($data),
            QrEncodingMode::Kanji => self::encodeKanji($data),
        };

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

    private static function encodeNumeric(string $data): string
    {
        $bits = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 3) {
            $chunk = substr($data, $i, 3);
            $value = (int) $chunk;
            $width = strlen($chunk) === 3 ? 10 : (strlen($chunk) === 2 ? 7 : 4);
            $bits .= str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    private static function encodeAlphanumeric(string $data): string
    {
        $charset = QrEncodingMode::ALPHANUMERIC_CHARSET;
        $bits = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 2) {
            $v1 = strpos($charset, $data[$i]);
            if ($v1 === false) {
                throw new \InvalidArgumentException('Alphanumeric input contains illegal char: '.$data[$i]);
            }
            if ($i + 1 < $len) {
                $v2 = strpos($charset, $data[$i + 1]);
                if ($v2 === false) {
                    throw new \InvalidArgumentException('Alphanumeric input contains illegal char: '.$data[$i + 1]);
                }
                $value = $v1 * 45 + $v2;
                $bits .= str_pad(decbin($value), 11, '0', STR_PAD_LEFT);
            } else {
                $bits .= str_pad(decbin($v1), 6, '0', STR_PAD_LEFT);
            }
        }

        return $bits;
    }

    private static function encodeByte(string $data): string
    {
        $bits = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Phase 101: Kanji mode (Shift_JIS, 13 bits per char).
     *
     * Algorithm per ISO/IEC 18004 §7.4.6:
     *  - Each Kanji char = 2 Shift_JIS bytes.
     *  - First byte 0x81..0x9F → subtract 0x8140.
     *  - First byte 0xE0..0xEB → subtract 0xC140.
     *  - delta MSB × 0xC0 + LSB = 13-bit value.
     *
     * Caller pre-converts UTF-8 → Shift_JIS bytes.
     */
    public static function encodeKanji(string $shiftJisData): string
    {
        $bits = '';
        $len = strlen($shiftJisData);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $hi = ord($shiftJisData[$i]);
            $lo = ord($shiftJisData[$i + 1]);
            $combined = ($hi << 8) | $lo;
            if ($combined >= 0x8140 && $combined <= 0x9FFC) {
                $delta = $combined - 0x8140;
            } elseif ($combined >= 0xE040 && $combined <= 0xEBBF) {
                $delta = $combined - 0xC140;
            } else {
                throw new \InvalidArgumentException(sprintf(
                    'Kanji codepoint 0x%04X out of supported Shift_JIS range', $combined,
                ));
            }
            $hiByte = ($delta >> 8) & 0xFF;
            $loByte = $delta & 0xFF;
            $value = $hiByte * 0xC0 + $loByte;
            $bits .= str_pad(decbin($value), 13, '0', STR_PAD_LEFT);
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
     * Phase 146: split data codewords into blocks per ECC layout.
     * Returns list<list<int>> (data blocks, possibly с different sizes
     * для mixed-block versions).
     *
     * @param  list<int>  $codewords
     * @return list<list<int>>
     */
    private function splitDataBlocks(array $codewords, int $version): array
    {
        $params = self::ECC_PARAMS[$version][$this->eccLevel->value];
        [$totalData, , , $g1Count] = $params;
        if (! isset($params[4])) {
            // Single-group: $g1Count blocks of (totalData / g1Count) each.
            $blockSize = intdiv($totalData, $g1Count);
            $blocks = [];
            for ($b = 0; $b < $g1Count; $b++) {
                $blocks[] = array_slice($codewords, $b * $blockSize, $blockSize);
            }

            return $blocks;
        }
        // Mixed-group: g1Count × g1BlockSize + g2Count × g2BlockSize.
        [, , , , $g2Count, $g2BlockSize] = $params;
        $g1BlockSize = intdiv($totalData - $g2Count * $g2BlockSize, $g1Count);
        $blocks = [];
        $offset = 0;
        for ($b = 0; $b < $g1Count; $b++) {
            $blocks[] = array_slice($codewords, $offset, $g1BlockSize);
            $offset += $g1BlockSize;
        }
        for ($b = 0; $b < $g2Count; $b++) {
            $blocks[] = array_slice($codewords, $offset, $g2BlockSize);
            $offset += $g2BlockSize;
        }

        return $blocks;
    }

    /**
     * Applies Reed-Solomon ECC per data block.
     *
     * @param  list<int>  $codewords  Data codewords.
     * @return list<list<int>>  ECC blocks (per data block).
     */
    private function applyReedSolomon(array $codewords, int $version): array
    {
        $eccPerBlock = self::ECC_PARAMS[$version][$this->eccLevel->value][2];
        $dataBlocks = $this->splitDataBlocks($codewords, $version);
        $eccBlocks = [];
        foreach ($dataBlocks as $dataBlock) {
            $eccBlocks[] = self::rsCompute($dataBlock, $eccPerBlock);
        }

        return $eccBlocks;
    }

    /**
     * Interleaves data + ECC blocks по spec.
     *
     * Mixed-block: shorter blocks contribute fewer columns when interleaving
     * data. ECC blocks all have same length so straightforward.
     *
     * @param  list<int>  $dataCodewords
     * @param  list<list<int>>  $eccBlocks
     * @return list<int>
     */
    private function interleaveBlocks(array $dataCodewords, array $eccBlocks, int $version): array
    {
        $dataBlocks = $this->splitDataBlocks($dataCodewords, $version);
        $maxBlockSize = 0;
        foreach ($dataBlocks as $blk) {
            $maxBlockSize = max($maxBlockSize, count($blk));
        }

        // Interleave data columnwise — shorter blocks skip after their length.
        $result = [];
        for ($i = 0; $i < $maxBlockSize; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) {
                    $result[] = $blk[$i];
                }
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

        // Phase 105: try all 8 mask patterns, pick min penalty.
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = $matrix;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $matrix;
            self::applyMask($candidate, $reserved, $mask, $size);
            $this->writeFormatInfo($candidate, $size, $mask);
            $penalty = self::computeMaskPenalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $candidate;
            }
        }
        $this->selectedMask = $bestMask;

        return $bestMatrix;
    }

    public readonly int $selectedMask;

    /**
     * Phase 105: Apply mask pattern N к non-reserved modules.
     *
     * @param  array<int, array<int, bool>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private static function applyMask(array &$matrix, array $reserved, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => false,
                };
                if ($invert) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }
    }

    /**
     * Phase 105: penalty score per ISO/IEC 18004 §7.8.3. Sum 4 rules:
     *  N1 = 3 + (run - 5) for run ≥ 5 consecutive same-color в row/col.
     *  N2 = 3 per 2×2 block of same color.
     *  N3 = 40 per finder-pattern-like sequence (1:1:3:1:1).
     *  N4 = 10 × steps_of_5pct от 50% dark.
     *
     * @param  list<list<bool>>  $matrix
     */
    private static function computeMaskPenalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Rule 1: row runs.
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($matrix[$y][$x] === $matrix[$y][$x - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
        }
        // Rule 1: column runs.
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($matrix[$y][$x] === $matrix[$y - 1][$x]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
        }

        // Rule 2: 2×2 blocks.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $matrix[$y][$x];
                if ($matrix[$y][$x + 1] === $c && $matrix[$y + 1][$x] === $c && $matrix[$y + 1][$x + 1] === $c) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: 1:1:3:1:1 patterns.
        $pattern = [true, false, true, true, true, false, true]; // BWBBBWB
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 7; $x++) {
                $match = true;
                for ($k = 0; $k < 7; $k++) {
                    if ($matrix[$y][$x + $k] !== $pattern[$k]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $penalty += 40;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 7; $y++) {
                $match = true;
                for ($k = 0; $k < 7; $k++) {
                    if ($matrix[$y + $k][$x] !== $pattern[$k]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $penalty += 40;
                }
            }
        }

        // Rule 4: percentage dark.
        $darkCount = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $darkCount++;
                }
            }
        }
        $total = $size * $size;
        $pct = ($darkCount * 100) / $total;
        $deviation = abs($pct - 50);
        $penalty += (int) (floor($deviation / 5) * 10);

        return $penalty;
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
    private function writeFormatInfo(array &$matrix, int $size, int $mask = 0): void
    {
        // ECC level (2 bits) + mask (3 bits) = 5 bits.
        // L=01, M=00, Q=11, H=10 — left-shifted в high 2 bits.
        $data = ($this->eccLevel->formatBits() << 3) | ($mask & 0b111);
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
