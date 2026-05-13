<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 32: Code 128 linear barcode encoder.
 *
 * ISO/IEC 15417. Implements Code 128 Set B (printable ASCII 32..126),
 * который покрывает alphanumeric + punctuation — типичный use case для
 * бизнес-документов (SKU, invoice numbers, tracking IDs).
 *
 * Code 128 structure:
 *
 *   [Start B] [data chars...] [checksum] [Stop]
 *
 * Каждый character = 11 modules (3 bars + 3 spaces). Bars alternate
 * starting with black. Stop pattern имеет 13 modules.
 *
 * Не реализовано в этой фазе (deferred):
 *  - Code A (control chars 00..31, специальные функции FNC).
 *  - Code C (numeric pairs — 2× compression для digit-only).
 *  - Auto-mode switching между A/B/C для оптимальной длины.
 *  - GS1-128 (Application Identifiers).
 *
 * Использование:
 *   $enc = new Code128Encoder('ABC-123');
 *   $modules = $enc->modules();      // list of bool: true = black
 *   $width = $enc->moduleCount();    // total module width
 */
final class Code128Encoder
{
    /**
     * Code 128 patterns: index = code value (0..106), value = 6-digit
     * string describing module widths: bar/space/bar/space/bar/space.
     *
     * Example: value 0 "212222" = bar-2, space-1, bar-2, space-2, bar-2,
     * space-2. Total 11 modules.
     *
     * Index 106 = Stop+Termination bar (2+3+1+1+1+2+3 = 13 modules).
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232',
        // Stop pattern (index 106) — 13 modules: 2 1 1 4 1 3 1 (7 entries).
        '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * Resulting modules: each entry true = black, false = white.
     *
     * @var list<bool>
     */
    private array $modules = [];

    public function __construct(public readonly string $data)
    {
        $this->encode($data);
    }

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    public function moduleCount(): int
    {
        return count($this->modules);
    }

    /**
     * Quiet zone — 10 modules рекомендованной spec; minimum для readability.
     * Returns модули с pre/post-padded white space.
     *
     * @return list<bool>
     */
    public function modulesWithQuietZone(int $quietModules = 10): array
    {
        $quiet = array_fill(0, $quietModules, false);

        return [...$quiet, ...$this->modules, ...$quiet];
    }

    private function encode(string $data): void
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Code 128 input must be non-empty');
        }

        $codes = [self::START_B];
        $bytes = array_values(unpack('C*', $data) ?: []);
        foreach ($bytes as $byte) {
            if ($byte < 32 || $byte > 126) {
                throw new \InvalidArgumentException(sprintf(
                    'Code 128 Set B supports ASCII 32..126 only; got byte 0x%02X',
                    $byte,
                ));
            }
            $codes[] = $byte - 32;
        }

        // Checksum: (start + Σ(value × position)) mod 103. Position 1-based
        // для data; start counts as position 0 (т.е. multiplier 1).
        $sum = self::START_B;
        $position = 1;
        for ($i = 1; $i < count($codes); $i++) {
            $sum += $codes[$i] * $position;
            $position++;
        }
        $checksum = $sum % 103;
        $codes[] = $checksum;
        $codes[] = self::STOP;

        // Render каждый code value в modules.
        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code];
            $bar = true; // Каждый pattern starts with black bar.
            foreach (str_split($pattern) as $widthStr) {
                $width = (int) $widthStr;
                for ($j = 0; $j < $width; $j++) {
                    $this->modules[] = $bar;
                }
                $bar = ! $bar;
            }
        }
    }
}
