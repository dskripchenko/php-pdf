<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 124: PDF417 stacked linear 2D barcode (ISO/IEC 15438:2006).
 *
 * Stacked rows of 17-module codewords with Reed-Solomon ECC over GF(929).
 * Каждая row: start pattern + left row indicator + N data codewords +
 * right row indicator + stop pattern.
 *
 * Supported:
 *  - Byte compaction mode (codeword 901): handles arbitrary ASCII + binary.
 *    Each input byte → 1 PDF417 codeword.
 *  - Multi-byte compaction (codeword 924): groups of 6 bytes → 5 codewords
 *    (применяется когда длина данных кратна 6).
 *  - All ECC levels 0..8 (2 to 512 ECC codewords).
 *  - Auto-dimension: chooses rows × cols based on aspect ratio target.
 *
 * Не реализовано в этой версии:
 *  - Text compaction (codeword 900): denser для alphanumeric.
 *  - Numeric compaction (codeword 902): denser для digit-only.
 *  - Macro PDF417 (multi-symbol concatenation).
 *
 * Output via {@see modules()} — 2D bool matrix.
 */
final class Pdf417Encoder
{
    /** Single-byte mode latch codeword. */
    private const MODE_LATCH_BYTE_SINGLE = 901;

    /** Multi-byte mode latch (used when data length is multiple of 6). */
    private const MODE_LATCH_BYTE_MULTI = 924;

    /** Pad codeword (Text mode latch — used here for padding). */
    private const PADDING_CODEWORD = 900;

    public readonly int $rows;

    public readonly int $cols;

    public readonly int $eccLevel;

    /** @var list<int> All codewords incl. SLD + data + ECC. */
    public readonly array $codewords;

    /** @var list<list<bool>> Final matrix; rows × (cols×17 + 17 + 17 + 18) modules. */
    private array $matrix;

    /**
     * @param  string  $data            arbitrary bytes
     * @param  int     $eccLevel        0..8 (defaults to auto based on data size)
     * @param  float   $aspectRatio     desired width/height ratio (TCPDF default 2.0)
     */
    public function __construct(
        public readonly string $data,
        ?int $eccLevel = null,
        float $aspectRatio = 2.0,
    ) {
        if ($data === '') {
            throw new \InvalidArgumentException('PDF417 input must be non-empty');
        }

        // 1. Byte-mode compaction.
        $codewords = self::byteCompaction($data);
        $numDataCw = count($codewords);

        // 2. Select ECC level (auto): scale с data size per ISO §5.3.6.
        if ($eccLevel === null) {
            $eccLevel = self::autoEccLevel($numDataCw);
        }
        if ($eccLevel < 0 || $eccLevel > 8) {
            throw new \InvalidArgumentException('PDF417 ECC level must be 0..8');
        }
        $this->eccLevel = $eccLevel;

        $eccSize = 2 << $eccLevel; // 2,4,8,16,32,64,128,256,512.

        // Total codewords: SLD (1) + data + ECC.
        $nce = $numDataCw + $eccSize + 1;
        if ($nce > 929) {
            throw new \InvalidArgumentException(sprintf(
                'PDF417 capacity exceeded: %d codewords > 929 max',
                $nce,
            ));
        }

        // 3. Choose cols/rows balancing aspect ratio.
        // Formula derived in ISO §5.3.6 — cols ≈ sqrt(4761 + 272*aspect*nce)/34 - 2.
        $rowHeightModules = 4; // ROWHEIGHT constant.
        $cols = (int) round((sqrt(4761 + 68 * $aspectRatio * $rowHeightModules * $nce) - 69) / 34);
        $cols = max(1, min(30, $cols));
        $rows = (int) ceil($nce / $cols);
        if ($rows < 3) {
            $rows = 3;
        } elseif ($rows > 90) {
            $rows = 90;
            $cols = (int) ceil($nce / $rows);
        }
        $size = $rows * $cols;

        // 4. Pad с 900 (text mode latch is the canonical padding codeword).
        $pad = $size - $nce;
        if ($pad > 0) {
            $codewords = array_merge($codewords, array_fill(0, $pad, self::PADDING_CODEWORD));
        }

        // 5. Prepend Symbol Length Descriptor.
        $sld = $size - $eccSize;
        array_unshift($codewords, $sld);

        // 6. Compute Reed-Solomon ECC поверх GF(929).
        $ecw = self::reedSolomonEncode($codewords, $eccLevel);
        $codewords = array_merge($codewords, $ecw);

        $this->codewords = $codewords;
        $this->rows = $rows;
        $this->cols = $cols;

        // 7. Build matrix.
        $this->matrix = $this->buildMatrix($codewords, $rows, $cols, $eccLevel);
    }

    /**
     * @return list<list<bool>>
     */
    public function modules(): array
    {
        return $this->matrix;
    }

    public function rowCount(): int
    {
        return count($this->matrix);
    }

    public function colCount(): int
    {
        return count($this->matrix[0]);
    }

    /**
     * PDF417 byte compaction: each byte → 1 codeword (mode 901).
     * When length divisible by 6, use 924 + groups of 6 → 5 codewords each.
     *
     * @return list<int>
     */
    public static function byteCompaction(string $data): array
    {
        $len = strlen($data);
        $cw = [];
        if ($len % 6 === 0) {
            // Multi-byte mode: groups of 6 bytes → 5 codewords each.
            $cw[] = self::MODE_LATCH_BYTE_MULTI;
            for ($i = 0; $i < $len; $i += 6) {
                $cw = array_merge($cw, self::packSixBytes(substr($data, $i, 6)));
            }
        } else {
            // Single-byte mode: 1 codeword per byte.
            $cw[] = self::MODE_LATCH_BYTE_SINGLE;
            for ($i = 0; $i < $len; $i++) {
                $cw[] = ord($data[$i]);
            }
        }

        return $cw;
    }

    /**
     * Pack 6 bytes as big-endian 48-bit integer into 5 base-900 digits.
     *
     * @return list<int>
     */
    private static function packSixBytes(string $bytes): array
    {
        // 48-bit value — fits in PHP int on 64-bit platforms.
        $value = 0;
        for ($i = 0; $i < 6; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        $digits = [0, 0, 0, 0, 0];
        for ($i = 4; $i >= 0; $i--) {
            $digits[$i] = (int) ($value % 900);
            $value = (int) ($value / 900);
        }

        return $digits;
    }

    /**
     * Reed-Solomon ECC over GF(929).
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    public static function reedSolomonEncode(array $data, int $eccLevel): array
    {
        $factors = Pdf417Patterns::RS_FACTORS[$eccLevel];
        $eccLen = count($factors);
        $ecc = array_fill(0, $eccLen, 0);
        foreach ($data as $d) {
            $t1 = ($d + $ecc[$eccLen - 1]) % 929;
            for ($j = $eccLen - 1; $j > 0; $j--) {
                $t2 = ($t1 * $factors[$j]) % 929;
                $ecc[$j] = ($ecc[$j - 1] + 929 - $t2) % 929;
            }
            $t2 = ($t1 * $factors[0]) % 929;
            $ecc[0] = (929 - $t2) % 929;
        }
        foreach ($ecc as $j => $e) {
            if ($e !== 0) {
                $ecc[$j] = 929 - $e;
            }
        }

        return array_reverse($ecc);
    }

    /**
     * Auto-select ECC level per ISO/IEC 15438 §5.3.6 Table 8 рекомендация.
     */
    private static function autoEccLevel(int $numDataCw): int
    {
        return match (true) {
            $numDataCw <= 40 => 2,
            $numDataCw <= 160 => 3,
            $numDataCw <= 320 => 4,
            $numDataCw <= 863 => 5,
            default => 6,
        };
    }

    /**
     * Build the 2D module matrix.
     *
     * Each row: 17 (start) + 17 (left RI) + cols×17 (data) + 17 (right RI) + 18 (stop).
     *
     * @param  list<int>  $codewords  size = rows × cols
     * @return list<list<bool>>
     */
    private function buildMatrix(array $codewords, int $rows, int $cols, int $ecl): array
    {
        $matrix = [];
        $k = 0;
        $totalWidth = 17 + 17 + $cols * 17 + 17 + 18;

        for ($r = 0; $r < $rows; $r++) {
            // Cluster index 0/1/2 maps to cluster IDs 0/3/6.
            $cid = $r % 3;
            $rowBits = [];

            // Start pattern (17 bits).
            self::appendPattern($rowBits, Pdf417Patterns::START_PATTERN, 17);

            // Left row indicator.
            $L = $this->leftRowIndicator($r, $cid, $rows, $cols, $ecl);
            self::appendPattern($rowBits, Pdf417Patterns::PATTERNS[$cid][$L], 17);

            // Data codewords.
            for ($c = 0; $c < $cols; $c++) {
                self::appendPattern($rowBits, Pdf417Patterns::PATTERNS[$cid][$codewords[$k++]], 17);
            }

            // Right row indicator.
            $R = $this->rightRowIndicator($r, $cid, $rows, $cols, $ecl);
            self::appendPattern($rowBits, Pdf417Patterns::PATTERNS[$cid][$R], 17);

            // Stop pattern (18 bits, ends с extra trailing bar).
            self::appendPattern($rowBits, Pdf417Patterns::STOP_PATTERN, 18);

            // Each row repeats vertically rowHeight times — typically 3 modules
            // tall (ROWHEIGHT in ISO). Returning 1 row per codeword row keeps
            // matrix compact; render layer handles vertical scale.
            $matrix[] = $rowBits;
        }

        return $matrix;
    }

    /**
     * @param  list<bool>  $rowBits
     */
    private static function appendPattern(array &$rowBits, int $pattern, int $bits): void
    {
        for ($i = $bits - 1; $i >= 0; $i--) {
            $rowBits[] = (($pattern >> $i) & 1) === 1;
        }
    }

    private function leftRowIndicator(int $row, int $cid, int $rows, int $cols, int $ecl): int
    {
        $base = 30 * intdiv($row, 3);

        return match ($cid) {
            0 => $base + intdiv($rows - 1, 3),
            1 => $base + ($ecl * 3) + (($rows - 1) % 3),
            2 => $base + ($cols - 1),
            default => throw new \LogicException('invalid cluster'),
        };
    }

    private function rightRowIndicator(int $row, int $cid, int $rows, int $cols, int $ecl): int
    {
        $base = 30 * intdiv($row, 3);

        return match ($cid) {
            0 => $base + ($cols - 1),
            1 => $base + intdiv($rows - 1, 3),
            2 => $base + ($ecl * 3) + (($rows - 1) % 3),
            default => throw new \LogicException('invalid cluster'),
        };
    }
}
