<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Kerning lookup table — pair-wise advance adjustments.
 *
 * Query:
 *   $kern->lookup(leftGid, rightGid): int  // xAdvance adjustment in FUnits
 *                                            // (negative = pair closer)
 *
 * Source:
 *  - GPOS lookup type 2 "Pair Adjustment Positioning", format 1 (specific
 *    pairs) and format 2 (class-based pairs). Parsed by GposReader.
 *  - Legacy `kern` table (Apple TT) — not supported here; modern fonts
 *    use GPOS exclusively.
 *
 * Storage is packed binary strings rather than nested arrays, in layers kept
 * in the order they were added:
 *  - pairs: per first glyph, big-endian uint16 (secondGlyph, xAdvance) words —
 *    for the common GPOS value format this is a slice of the font itself;
 *  - classes (format 2): a byte per glyph marking the covered first glyphs,
 *    a uint16 class per glyph for each side (0 = default class), and the
 *    class1 × class2 matrix of int16 xAdvance values — the ClassDef and
 *    matrix are slices of the font, or built range by range.
 * Nothing is expanded into glyph pairs or unpacked: a lookup searches a row
 * in place with strrpos() and reads classes and matrix cells by offset, and
 * its answer is memoized. A lookup asks the newest layer first, so a later
 * non-zero adjustment wins over an earlier one; a zero adjustment is never
 * an answer, as it was never stored.
 *
 * A font such as Inter holds about half a million pairs once classes are
 * expanded: tens of milliseconds to build and tens of megabytes to hold.
 * Packed, the same table is a few hundred kilobytes of strings.
 *
 * Use cases:
 *  - TextMeasurer: accounts for kerning when measuring pt-width of a string
 *  - PdfFont: emits TJ-operator with position adjustments in content stream
 */
final class KerningTable
{
    /**
     * @var list<array{0: 'pairs', 1: array<int, string>}|array{0: 'classes', 1: string, 2: string, 3: string, 4: int, 5: string}>
     */
    private array $layers = [];

    /** @var array<int, array<int, int>> answered lookups */
    private array $memo = [];

    private ?int $pairCount = null;

    public function add(int $leftGid, int $rightGid, int $xAdvanceFu): void
    {
        if ($xAdvanceFu === 0) {
            return; // do not store zero-adjustment pairs
        }
        $this->addPairs($leftGid, pack('nn', $rightGid, $xAdvanceFu & 0xFFFF));
    }

    /**
     * Adds pairs for one first glyph: big-endian uint16 words, alternately the
     * second glyph and its xAdvance (two's complement). Later pairs win over
     * earlier ones; zero adjustments are ignored.
     */
    public function addPairs(int $leftGid, string $packed): void
    {
        if ($packed === '' || strlen($packed) % 4 !== 0) {
            return;
        }
        $last = array_key_last($this->layers);
        if ($last === null || $this->layers[$last][0] !== 'pairs') {
            $this->layers[] = ['pairs', []];
            $last = array_key_last($this->layers);
        }
        $this->layers[$last][1][$leftGid] = ($this->layers[$last][1][$leftGid] ?? '').$packed;
        $this->changed();
    }

    /**
     * Adds a class-based subtable (GPOS PairPos format 2) without expanding it.
     *
     * @param  string  $coverage  a byte per glyph id: "\1" for a first glyph the subtable covers
     * @param  string  $firstClasses  uint16 per glyph id: the first glyph's class (0 = default class)
     * @param  string  $secondClasses  uint16 per glyph id: the second glyph's class (0 = none)
     * @param  string  $matrix  int16 xAdvance per (class1, class2), row-major, class2Count wide
     */
    public function addClasses(string $coverage, string $firstClasses, string $secondClasses, int $class2Count, string $matrix): void
    {
        if ($class2Count < 1 || trim($matrix, "\0") === '' || trim($coverage, "\0") === '' || trim($secondClasses, "\0") === '') {
            return;
        }
        $this->layers[] = ['classes', $coverage, $firstClasses, $secondClasses, $class2Count, $matrix];
        $this->changed();
    }

    /**
     * The table as plain data, for TtfFile::exportState(): its packed layers.
     *
     * @experimental As TtfFile::exportState(); the layout follows TtfFile::STATE_FORMAT.
     *
     * @return list<array{0: 'pairs', 1: array<int, string>}|array{0: 'classes', 1: string, 2: string, 3: string, 4: int, 5: string}>
     */
    public function exportState(): array
    {
        return $this->layers;
    }

    /**
     * Restores a table from exportState() of a trusted cache.
     *
     * @experimental As TtfFile::fromState().
     *
     * @param  array<array-key, mixed>  $layers
     *
     * @throws \InvalidArgumentException for layers of any other shape
     */
    public static function fromState(array $layers): self
    {
        $table = new self;
        foreach ($layers as $layer) {
            if (! is_array($layer) || ! array_is_list($layer)) {
                throw new \InvalidArgumentException('Kerning state is damaged.');
            }
            if (count($layer) === 2 && $layer[0] === 'pairs' && is_array($layer[1])) {
                $rows = [];
                foreach ($layer[1] as $left => $row) {
                    if (! is_int($left) || ! is_string($row) || strlen($row) % 4 !== 0) {
                        throw new \InvalidArgumentException('Kerning state is damaged.');
                    }
                    $rows[$left] = $row;
                }
                $table->layers[] = ['pairs', $rows];

                continue;
            }
            if (count($layer) === 6 && $layer[0] === 'classes' && is_string($layer[1]) && is_string($layer[2])
                && is_string($layer[3]) && is_int($layer[4]) && $layer[4] >= 1 && is_string($layer[5])) {
                $table->layers[] = ['classes', $layer[1], $layer[2], $layer[3], $layer[4], $layer[5]];

                continue;
            }
            throw new \InvalidArgumentException('Kerning state is damaged.');
        }

        return $table;
    }

    /**
     * Lookup xAdvance adjustment for pair (left, right). Returns 0 if
     * the pair is not defined (no kerning).
     */
    public function lookup(int $leftGid, int $rightGid): int
    {
        return $this->memo[$leftGid][$rightGid] ??= $this->find($leftGid, $rightGid);
    }

    private function find(int $leftGid, int $rightGid): int
    {
        for ($i = count($this->layers) - 1; $i >= 0; $i--) {
            $layer = $this->layers[$i];
            if ($layer[0] === 'pairs') {
                if (! isset($layer[1][$leftGid])) {
                    continue;
                }
                $value = self::findInRow($layer[1][$leftGid], $rightGid);
                if ($value !== 0) {
                    return $value;
                }

                continue;
            }
            if (($layer[1][$leftGid] ?? "\0") !== "\1") {
                continue; // first glyph not covered
            }
            $at = $rightGid << 1;
            $class2 = isset($layer[3][$at + 1]) ? (ord($layer[3][$at]) << 8) | ord($layer[3][$at + 1]) : 0;
            if ($class2 === 0 || $class2 >= $layer[4]) {
                continue; // no class, or one the matrix has no column for (malformed)
            }
            $at = $leftGid << 1;
            $class1 = isset($layer[2][$at + 1]) ? (ord($layer[2][$at]) << 8) | ord($layer[2][$at + 1]) : 0;
            $at = ($class1 * $layer[4] + $class2) << 1;
            $value = isset($layer[5][$at + 1]) ? (ord($layer[5][$at]) << 8) | ord($layer[5][$at + 1]) : 0;
            if ($value !== 0) {
                return $value >= 0x8000 ? $value - 0x10000 : $value;
            }
        }

        return 0;
    }

    /** The uint16 at word $index of $packed, or 0 past its end. */
    private static function u16(string $packed, int $index): int
    {
        $at = $index << 1;

        return isset($packed[$at + 1]) ? (ord($packed[$at]) << 8) | ord($packed[$at + 1]) : 0;
    }

    /**
     * The last non-zero xAdvance for $rightGid in a packed row, or 0. Searches
     * from the end with strrpos (C speed, no unpacking); a hit must sit on a
     * (secondGlyph, xAdvance) boundary, as glyph ids can also match inside values.
     */
    private static function findInRow(string $row, int $rightGid): int
    {
        $needle = chr($rightGid >> 8).chr($rightGid & 0xFF);
        $length = strlen($row);
        $before = $length;
        while ($before > 0 && ($at = strrpos($row, $needle, $before - $length - 1)) !== false) {
            if ($at % 4 === 0) {
                $value = (ord($row[$at + 2]) << 8) | ord($row[$at + 3]);
                if ($value !== 0) {
                    return $value >= 0x8000 ? $value - 0x10000 : $value;
                }
            }
            $before = $at;
        }

        return 0;
    }

    /** @return array<int, int> second glyph → non-zero xAdvance */
    private static function unpackRow(string $packed): array
    {
        $words = unpack('n*', $packed) ?: [];
        $row = [];
        for ($i = 1, $n = count($words); $i < $n; $i += 2) {
            $value = $words[$i + 1];
            if ($value !== 0) {
                $row[$words[$i]] = $value >= 0x8000 ? $value - 0x10000 : $value;
            }
        }

        return $row;
    }

    private function changed(): void
    {
        $this->memo = [];
        $this->pairCount = null;
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    /**
     * Number of distinct glyph pairs with an adjustment. This expands every
     * layer, so it is meant for diagnostics, not the render path.
     */
    public function pairCount(): int
    {
        if ($this->pairCount !== null) {
            return $this->pairCount;
        }
        $seen = [];
        foreach ($this->layers as $layer) {
            if ($layer[0] === 'pairs') {
                foreach ($layer[1] as $left => $packed) {
                    foreach (self::unpackRow($packed) as $right => $_) {
                        $seen[$left][$right] = true;
                    }
                }

                continue;
            }
            $members = [];
            foreach ($layer[3] === '' ? [] : (unpack('n*', $layer[3]) ?: []) as $i => $class) {
                if ($class !== 0 && $class < $layer[4]) {
                    $members[$class][] = $i - 1;
                }
            }
            for ($left = 0, $n = strlen($layer[1]); $left < $n; $left++) {
                if ($layer[1][$left] !== "\1") {
                    continue;
                }
                $class1 = self::u16($layer[2], $left);
                foreach ($members as $class2 => $rights) {
                    if (self::u16($layer[5], $class1 * $layer[4] + $class2) !== 0) {
                        foreach ($rights as $right) {
                            $seen[$left][$right] = true;
                        }
                    }
                }
            }
        }

        return $this->pairCount = array_sum(array_map('count', $seen));
    }
}
