<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * GPOS table parser — extracts pair-wise kerning data into a KerningTable.
 *
 * Scope:
 *  - lookup type 2 (Pair Adjustment Positioning) format 1 + format 2
 *  - valueFormat: only the X_ADVANCE bit (0x0004). Other positional
 *    adjustments (xPlacement, yAdvance, yPlacement, device tables) are
 *    not critical for Western European typography and are ignored.
 *
 * Not covered:
 *  - GPOS lookup type 1 (single positioning) — usually for diacritic
 *    placement, not for kerning
 *  - lookup type 3 (cursive attachment) — for script fonts
 *  - lookup type 4-8 (mark positioning, contextual, etc.) — Arabic/Indic
 *  - Script/feature filtering — we grab ALL pair-adjustment lookups
 *    as kerning (most fonts have a single lookup type 2 = kern)
 *
 * Reference: OpenType GPOS spec, https://docs.microsoft.com/typography/opentype/spec/gpos
 */
final class GposReader
{
    private const VALUE_X_PLACEMENT = 0x0001;

    private const VALUE_Y_PLACEMENT = 0x0002;

    private const VALUE_X_ADVANCE = 0x0004;

    /**
     * @param  array{offset: int, length: int}  $gposTableInfo
     */
    public function read(string $sourceBytes, array $gposTableInfo): KerningTable
    {
        $table = new KerningTable;
        $base = $gposTableInfo['offset'];
        $reader = new BinaryReader($sourceBytes);

        // GPOS Header:
        //   uint16 majorVersion
        //   uint16 minorVersion
        //   uint16 scriptListOffset       (from base)
        //   uint16 featureListOffset
        //   uint16 lookupListOffset
        $reader->seek($base);
        $reader->skip(2); // majorVersion
        $reader->skip(2); // minorVersion
        $reader->skip(2); // scriptListOffset — we do not filter by script
        $reader->skip(2); // featureListOffset — we do not filter by feature
        $lookupListOffset = $reader->readUInt16();

        $this->readLookupList($sourceBytes, $base + $lookupListOffset, $table);

        return $table;
    }

    /**
     * LookupList:
     *   uint16 lookupCount
     *   Offset16 lookups[lookupCount]  (from start of LookupList)
     */
    private function readLookupList(string $bytes, int $listOffset, KerningTable $table): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($listOffset);
        $lookupCount = $reader->readUInt16();

        for ($i = 0; $i < $lookupCount; $i++) {
            $reader->seek($listOffset + 2 + $i * 2);
            $lookupOffset = $reader->readUInt16();
            $this->readLookup($bytes, $listOffset + $lookupOffset, $table);
        }
    }

    /**
     * Lookup table:
     *   uint16 lookupType (we care about 2 = Pair Adjustment)
     *   uint16 lookupFlag
     *   uint16 subTableCount
     *   Offset16 subtableOffsets[subTableCount]   (from Lookup start)
     *   (optional uint16 markFilteringSet if flag & 0x10)
     */
    private function readLookup(string $bytes, int $lookupOffset, KerningTable $table): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($lookupOffset);
        $lookupType = $reader->readUInt16();
        $reader->skip(2); // lookupFlag
        $subTableCount = $reader->readUInt16();

        if ($lookupType !== 2 && $lookupType !== 9) {
            return; // not pair adjustment — ignore
        }

        for ($i = 0; $i < $subTableCount; $i++) {
            $reader->seek($lookupOffset + 6 + $i * 2);
            $subtableOffset = $lookupOffset + $reader->readUInt16();
            if ($lookupType === 9) {
                // Extension subtable: uint16 format, uint16 extensionLookupType,
                // Offset32 to the real subtable (from this subtable's start).
                // Large fonts (e.g. Adobe's) keep their kerning there.
                $reader->seek($subtableOffset + 2);
                if ($reader->readUInt16() !== 2) {
                    continue;
                }
                $subtableOffset += $reader->readUInt32();
            }
            $this->readPairAdjustmentSubtable($bytes, $subtableOffset, $table);
        }
    }

    /**
     * Pair Adjustment subtable — dispatcher on format 1 or 2.
     *
     * Format 1 (specific pairs):
     *   uint16 posFormat (= 1)
     *   Offset16 coverage  (glyphs that participate as 'first')
     *   uint16 valueFormat1  (bits)
     *   uint16 valueFormat2
     *   uint16 pairSetCount
     *   Offset16 pairSetOffsets[pairSetCount]
     *
     * Format 2 (class-based):
     *   uint16 posFormat (= 2)
     *   Offset16 coverage
     *   uint16 valueFormat1
     *   uint16 valueFormat2
     *   Offset16 classDef1
     *   Offset16 classDef2
     *   uint16 class1Count
     *   uint16 class2Count
     *   Class1Record class1Records[class1Count]:
     *     Class2Record class2Records[class2Count]:
     *       ValueRecord value1
     *       ValueRecord value2
     */
    private function readPairAdjustmentSubtable(string $bytes, int $subOffset, KerningTable $table): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($subOffset);
        $posFormat = $reader->readUInt16();
        $coverageOffset = $reader->readUInt16();
        $valueFormat1 = $reader->readUInt16();
        $valueFormat2 = $reader->readUInt16();

        if (($valueFormat1 & self::VALUE_X_ADVANCE) === 0) {
            return; // No X_ADVANCE for the first glyph — not kerning in our sense
        }

        if ($posFormat === 1) {
            // Coverage list — glyph IDs that can be 'first', in pair set order.
            $coverageGlyphs = $this->readCoverage($bytes, $subOffset + $coverageOffset);
            if ($coverageGlyphs !== []) {
                $this->readFormat1($bytes, $reader, $subOffset, $coverageGlyphs, $valueFormat1, $valueFormat2, $table);
            }
        } elseif ($posFormat === 2) {
            $coverage = $this->coverageMask($bytes, $subOffset + $coverageOffset);
            if ($coverage !== '') {
                $this->readFormat2($bytes, $reader, $subOffset, $coverage, $valueFormat1, $valueFormat2, $table);
            }
        }
    }

    /**
     * @param  list<int>  $coverageGlyphs
     */
    private function readFormat1(
        string $bytes,
        BinaryReader $reader,
        int $subOffset,
        array $coverageGlyphs,
        int $valueFormat1,
        int $valueFormat2,
        KerningTable $table,
    ): void {
        // Reader position: after valueFormat2. Next:
        //   uint16 pairSetCount
        //   Offset16 pairSetOffsets[pairSetCount]
        $pairSetCount = $reader->readUInt16();
        if ($pairSetCount !== count($coverageGlyphs)) {
            return; // malformed
        }

        $valueSize1 = $this->valueRecordSize($valueFormat1);
        $valueSize2 = $this->valueRecordSize($valueFormat2);

        for ($i = 0; $i < $pairSetCount; $i++) {
            $firstGid = $coverageGlyphs[$i];
            $reader->seek($subOffset + 10 + $i * 2);
            $pairSetOffset = $reader->readUInt16();
            $this->readPairSet(
                $bytes,
                $subOffset + $pairSetOffset,
                $firstGid,
                $valueFormat1,
                $valueFormat2,
                $valueSize1,
                $valueSize2,
                $table,
            );
        }
    }

    private function readPairSet(
        string $bytes,
        int $pairSetOffset,
        int $firstGid,
        int $valueFormat1,
        int $valueFormat2,
        int $valueSize1,
        int $valueSize2,
        KerningTable $table,
    ): void {
        $pairValueCount = (new BinaryReader($bytes))->peekUInt16($pairSetOffset);
        if ($pairValueCount === 0) {
            return;
        }

        // PairValueRecord: uint16 secondGlyph, ValueRecord value1, ValueRecord value2.
        // With xAdvance as the only value (the usual kerning format) the set
        // already is (secondGlyph, xAdvance) words: it is kept as it is.
        if ($valueFormat1 === self::VALUE_X_ADVANCE && $valueFormat2 === 0) {
            $table->addPairs($firstGid, substr($bytes, $pairSetOffset + 2, $pairValueCount * 4));

            return;
        }
        $words = 1 + (($valueSize1 + $valueSize2) >> 1);
        $xAt = 1 + ($valueFormat1 & self::VALUE_X_PLACEMENT ? 1 : 0) + ($valueFormat1 & self::VALUE_Y_PLACEMENT ? 1 : 0);
        $set = unpack('n*', substr($bytes, $pairSetOffset + 2, $pairValueCount * $words * 2));
        if ($set === false || count($set) < $pairValueCount * $words) {
            return; // truncated
        }
        $pairs = [];
        for ($j = 0, $i = 1; $j < $pairValueCount; $j++, $i += $words) {
            $pairs[] = $set[$i];
            $pairs[] = $set[$i + $xAt];
        }
        $table->addPairs($firstGid, pack('n*', ...$pairs));
    }

    /**
     * @param  string  $coverage  one byte per glyph id, "\1" for a first glyph the subtable covers
     */
    private function readFormat2(
        string $bytes,
        BinaryReader $reader,
        int $subOffset,
        string $coverage,
        int $valueFormat1,
        int $valueFormat2,
        KerningTable $table,
    ): void {
        // Reader position: after valueFormat2.
        //   Offset16 classDef1
        //   Offset16 classDef2
        //   uint16 class1Count
        //   uint16 class2Count
        $classDef1Offset = $reader->readUInt16();
        $classDef2Offset = $reader->readUInt16();
        $class1Count = $reader->readUInt16();
        $class2Count = $reader->readUInt16();

        $classDef1 = $this->classString($bytes, $subOffset + $classDef1Offset);
        $classDef2 = $this->classString($bytes, $subOffset + $classDef2Offset);

        $valueSize1 = $this->valueRecordSize($valueFormat1);
        $valueSize2 = $this->valueRecordSize($valueFormat2);
        $recordSize = $valueSize1 + $valueSize2;

        // Class records — array class1Count × class2Count.
        // Each record contains value1 + value2 for the (class1, class2) pair.
        $classRecordsBase = $reader->tell();

        // The class1 × class2 matrix of xAdvance values. With xAdvance as the
        // only value it is a slice of the font; otherwise it is read with one
        // unpack (every value is a 16-bit word) and the xAdvance words kept.
        $cells = $class1Count * $class2Count;
        if ($valueFormat1 === self::VALUE_X_ADVANCE && $valueFormat2 === 0) {
            $matrix = substr($bytes, $classRecordsBase, $cells * 2);
            if (strlen($matrix) < $cells * 2) {
                return; // truncated
            }
        } else {
            $words = $recordSize >> 1;
            $xAt = ($valueFormat1 & self::VALUE_X_PLACEMENT ? 1 : 0) + ($valueFormat1 & self::VALUE_Y_PLACEMENT ? 1 : 0);
            $all = $cells === 0 ? [] : unpack('n*', substr($bytes, $classRecordsBase, $cells * $recordSize));
            if ($all === false || count($all) < $cells * $words) {
                return; // truncated
            }
            $values = [];
            for ($i = 1 + $xAt, $end = $cells * $words; $i <= $end; $i += $words) {
                $values[] = $all[$i];
            }
            $matrix = $values === [] ? '' : pack('n*', ...$values);
        }

        // Kept as classes rather than expanded into (gid1, gid2) pairs.
        // Class 0 = "default class", contains all glyphs that are not
        // assigned an explicit class: it applies to covered first glyphs,
        // never to second glyphs.
        $table->addClasses($coverage, $classDef1, $classDef2, $class2Count, $matrix);
    }

    /**
     * Coverage table (referenced by GPOS subtables) — two formats:
     *
     * Format 1: explicit list
     *   uint16 coverageFormat (= 1)
     *   uint16 glyphCount
     *   uint16 glyphArray[glyphCount]
     *
     * Format 2: ranges
     *   uint16 coverageFormat (= 2)
     *   uint16 rangeCount
     *   RangeRecord rangeRecords[rangeCount]:
     *     uint16 startGlyphID
     *     uint16 endGlyphID
     *     uint16 startCoverageIndex  (we do not use this; needed for GSUB)
     *
     * @return list<int>  ordered list of glyph IDs in coverage
     */
    private function readCoverage(string $bytes, int $offset): array
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($offset);
        $format = $reader->readUInt16();
        $out = [];
        if ($format === 1) {
            $count = $reader->readUInt16();
            for ($i = 0; $i < $count; $i++) {
                $out[] = $reader->readUInt16();
            }
        } elseif ($format === 2) {
            $rangeCount = $reader->readUInt16();
            for ($i = 0; $i < $rangeCount; $i++) {
                $start = $reader->readUInt16();
                $end = $reader->readUInt16();
                $reader->skip(2); // startCoverageIndex
                for ($g = $start; $g <= $end; $g++) {
                    $out[] = $g;
                }
            }
        }

        return $out;
    }

    /**
     * A Coverage table as one byte per glyph id: "\1" covered, "\0" not.
     * Format 2 ranges are written whole, never glyph by glyph.
     */
    private function coverageMask(string $bytes, int $offset): string
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($offset);
        $format = $reader->readUInt16();
        $count = $reader->readUInt16();
        if ($count === 0 || ($format !== 1 && $format !== 2)) {
            return '';
        }
        $words = unpack('n*', substr($bytes, $offset + 4, $count * ($format === 1 ? 2 : 6)));
        if ($words === false || count($words) < $count * ($format === 1 ? 1 : 3)) {
            return ''; // truncated
        }
        if ($format === 1) {
            $mask = str_repeat("\0", max($words) + 1);
            foreach ($words as $gid) {
                $mask[$gid] = "\1";
            }

            return $mask;
        }
        $last = 0;
        for ($i = 1; $i <= $count * 3; $i += 3) {
            $last = max($last, $words[$i + 1]);
        }
        $mask = str_repeat("\0", $last + 1);
        for ($i = 1; $i <= $count * 3; $i += 3) {
            if ($words[$i + 1] >= $words[$i]) {
                $mask = substr_replace($mask, str_repeat("\1", $words[$i + 1] - $words[$i] + 1), $words[$i], $words[$i + 1] - $words[$i] + 1);
            }
        }

        return $mask;
    }

    /**
     * A ClassDef table as a big-endian uint16 class per glyph id (0 past the
     * end). Format 1 is a slice of the font behind zero padding; format 2
     * ranges are written whole when sorted and disjoint, as the spec requires.
     */
    private function classString(string $bytes, int $offset): string
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($offset);
        $format = $reader->readUInt16();
        if ($format === 1) {
            $start = $reader->readUInt16();
            $count = $reader->readUInt16();
            $values = substr($bytes, $offset + 6, $count * 2);
            if (strlen($values) < $count * 2) {
                return $this->packClasses($this->readClassDef($bytes, $offset));
            }

            return str_repeat("\0\0", $start).$values;
        }
        if ($format !== 2) {
            return '';
        }
        $count = $reader->readUInt16();
        $words = $count === 0 ? [] : unpack('n*', substr($bytes, $offset + 4, $count * 6));
        if ($words === false || count($words) < $count * 3) {
            return $this->packClasses($this->readClassDef($bytes, $offset));
        }
        $out = '';
        $next = 0;
        for ($i = 1; $i <= $count * 3; $i += 3) {
            [$start, $end, $class] = [$words[$i], $words[$i + 1], $words[$i + 2]];
            if ($start < $next || $end < $start) {
                // unsorted or overlapping ranges: glyph by glyph, as before
                return $this->packClasses($this->readClassDef($bytes, $offset));
            }
            $out .= str_repeat("\0\0", $start - $next).str_repeat(pack('n', $class), $end - $start + 1);
            $next = $end + 1;
        }

        return $out;
    }

    /** @param  array<int, int>  $classes  glyph id → class */
    private function packClasses(array $classes): string
    {
        if ($classes === []) {
            return '';
        }
        $words = array_fill(0, max(array_keys($classes)) + 1, 0);
        foreach ($classes as $gid => $class) {
            $words[$gid] = $class;
        }

        return pack('n*', ...$words);
    }

    /**
     * ClassDef table — two formats:
     *
     * Format 1: consecutive glyph range
     *   uint16 classFormat (= 1)
     *   uint16 startGlyphID
     *   uint16 glyphCount
     *   uint16 classValueArray[glyphCount]
     *
     * Format 2: ranges with class
     *   uint16 classFormat (= 2)
     *   uint16 classRangeCount
     *   ClassRangeRecord rangeRecords[classRangeCount]:
     *     uint16 startGlyphID
     *     uint16 endGlyphID
     *     uint16 class
     *
     * Glyphs not in ClassDef → class 0 (default).
     *
     * @return array<int, int>  glyphId → class (only non-zero classes)
     */
    private function readClassDef(string $bytes, int $offset): array
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($offset);
        $format = $reader->readUInt16();
        $out = [];
        if ($format === 1) {
            $startGid = $reader->readUInt16();
            $count = $reader->readUInt16();
            for ($i = 0; $i < $count; $i++) {
                $class = $reader->readUInt16();
                if ($class !== 0) {
                    $out[$startGid + $i] = $class;
                }
            }
        } elseif ($format === 2) {
            $rangeCount = $reader->readUInt16();
            for ($i = 0; $i < $rangeCount; $i++) {
                $start = $reader->readUInt16();
                $end = $reader->readUInt16();
                $class = $reader->readUInt16();
                if ($class === 0) {
                    continue;
                }
                for ($g = $start; $g <= $end; $g++) {
                    $out[$g] = $class;
                }
            }
        }

        return $out;
    }

    /**
     * ValueRecord size in bytes — depends on valueFormat bits.
     * Each set bit = 2 bytes (int16 value or uint16 device table offset).
     */
    private function valueRecordSize(int $valueFormat): int
    {
        $size = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            if ($valueFormat & (1 << $bit)) {
                $size += 2;
            }
        }

        return $size;
    }
}
