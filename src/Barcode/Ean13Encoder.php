<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Barcode;

/**
 * Phase 35: EAN-13 / UPC-A barcode encoder.
 *
 * EAN-13: 12 digit input + computed checksum (13th digit).
 * UPC-A: 11 digit input + computed checksum (12th digit); рендерится
 * как EAN-13 с leading zero ("0" + UPC-A = valid EAN-13).
 *
 * Structure (EAN-13):
 *   [LeftQuiet 9 modules]
 *   [Start guard 101]
 *   [6 left digits — 7 modules each, encoded L or G по first-digit pattern]
 *   [Center guard 01010]
 *   [6 right digits — 7 modules each, R encoding]
 *   [End guard 101]
 *   [RightQuiet 9 modules]
 *
 * Total = 95 modules + 18 quiet = 113.
 *
 * ISO/IEC 15420.
 */
final class Ean13Encoder
{
    /** L-code patterns (left half, odd parity) — 7-module strings. */
    private const L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** G-code patterns (left half, even parity). */
    private const G = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** R-code patterns (right half). */
    private const R = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /**
     * First-digit pattern table (which left-half digits use L vs G).
     * Index 0..9 = first digit; value — 6-char string of 'L'/'G'.
     */
    private const FIRST_DIGIT_PATTERN = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    private const START_GUARD = '101';

    private const CENTER_GUARD = '01010';

    private const END_GUARD = '101';

    /** @var list<bool> */
    private array $modules = [];

    /**
     * Full canonical 13-digit string (with computed checksum).
     */
    public readonly string $canonical;

    /**
     * @param  string  $digits  12 or 13 digits для EAN-13, или 11/12 для UPC-A.
     * @param  bool  $upcA  если true, input = 11/12 digits UPC-A → конвертируется
     *                       в EAN-13 prepending '0'.
     */
    public function __construct(string $digits, bool $upcA = false)
    {
        if (! preg_match('@^\d+$@', $digits)) {
            throw new \InvalidArgumentException('EAN-13/UPC-A input must be digits only');
        }
        if ($upcA) {
            if (strlen($digits) !== 11 && strlen($digits) !== 12) {
                throw new \InvalidArgumentException('UPC-A input must be 11 or 12 digits');
            }
            $digits = '0'.$digits;
        }
        if (strlen($digits) === 12) {
            $digits .= (string) self::computeCheckDigit($digits);
        }
        if (strlen($digits) !== 13) {
            throw new \InvalidArgumentException('EAN-13 input must be 12 or 13 digits');
        }
        // Validate checksum если 13-digit input.
        $expected = self::computeCheckDigit(substr($digits, 0, 12));
        if ((int) $digits[12] !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'EAN-13 checksum mismatch: expected %d, got %s',
                $expected,
                $digits[12],
            ));
        }

        $this->canonical = $digits;
        $this->encode($digits);
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
     * @return list<bool>
     */
    public function modulesWithQuietZone(int $quietModules = 9): array
    {
        $quiet = array_fill(0, $quietModules, false);

        return [...$quiet, ...$this->modules, ...$quiet];
    }

    /**
     * Mod 10 weighted checksum: sum(digits at odd positions × 1 + even × 3),
     * then check digit = (10 - sum % 10) % 10.
     */
    public static function computeCheckDigit(string $first12): int
    {
        if (strlen($first12) !== 12 || ! preg_match('@^\d+$@', $first12)) {
            throw new \InvalidArgumentException('computeCheckDigit expects 12 digits');
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $first12[$i];
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }

        return (10 - $sum % 10) % 10;
    }

    private function encode(string $digits): void
    {
        $first = (int) $digits[0];
        $leftPattern = self::FIRST_DIGIT_PATTERN[$first];

        $bits = self::START_GUARD;
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $digits[$i + 1];
            $bits .= $leftPattern[$i] === 'L' ? self::L[$d] : self::G[$d];
        }
        $bits .= self::CENTER_GUARD;
        for ($i = 7; $i < 13; $i++) {
            $d = (int) $digits[$i];
            $bits .= self::R[$d];
        }
        $bits .= self::END_GUARD;

        // Convert bit string to bool array.
        $this->modules = array_map(fn (string $c): bool => $c === '1', str_split($bits));
    }
}
