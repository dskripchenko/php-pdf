<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Минимальный TTF subsetter — вырезает unused glyph outlines из `glyf`
 * table, обнуляет соответствующие `loca` entries. Это даёт основной
 * size-win при embedding в PDF (от ~410KB Liberation Sans Regular
 * до ~5-30KB в зависимости от количества unique glyph'ов).
 *
 * Алгоритм (упрощённый for Phase 2):
 *
 *   1. Парсим `loca` table (offsets в `glyf`).
 *   2. Для каждого glyphId:
 *      - if glyphId == 0 (.notdef) OR в $usedGlyphs → выбираем выкинуть outline
 *      - else → outline становится 0 байт (loca[i] == loca[i+1])
 *   3. Для glyph'ов, которые остались, проверяем composite-references
 *      (component pointers): включаем referenced glyph'и тоже.
 *   4. Перепаковываем `glyf` со cleaned-up entries + новые `loca` offsets.
 *   5. Эмитим subset TTF (все остальные tables копируем as-is).
 *
 * Ограничения этого implementation'а:
 *  - НЕ recompute'им table checksums и whole-file checksum в `head`.
 *    PDF reader'ы tolerant — most don't check. Adobe Acrobat и Preview
 *    точно нет, по empirical опыту mpdf-кода.
 *  - НЕ subset'им hmtx (numHMetrics остаётся прежний; widths для
 *    unused glyph'ов остаются в hmtx). Это даёт небольшой overhead
 *    (~5-15 KB обычно), но избегает renumbering complexity.
 *  - НЕ обрабатываем GPOS/GSUB tables (Phase 2c+ subset'или их если
 *    kerning/ligatures enabled). Для Phase 2b они копируются as-is.
 *  - Composite glyph dependencies — следуем по компонентам через
 *    flag bit 0x20 (MORE_COMPONENTS); track'им транзитивные refs.
 */
final class TtfSubsetter
{
    /**
     * Subset full TTF в minimal version с only указанными glyph IDs.
     *
     * @param  array<int, bool>|list<int>  $usedGlyphIds  Если list — преобразуется
     *                                            во flipped map для O(1) lookup.
     * @return string  Subset TTF bytes (для embedding'а как FontFile2 stream).
     */
    public function subset(TtfFile $source, array $usedGlyphIds): string
    {
        // Normalize input в lookup map.
        $used = $this->normalizeUsedGlyphs($usedGlyphIds);
        // Glyph 0 (.notdef) — ВСЕГДА.
        $used[0] = true;

        // Чтение глобальных параметров TTF.
        $sourceBytes = $source->rawBytes();
        $reader = new BinaryReader($sourceBytes);

        // Парсим table directory из source.
        $tables = $this->readTableDirectory($reader);

        $headInfo = $this->readHead($reader, $tables);
        $indexToLocFormat = $headInfo['indexToLocFormat']; // 0 = uint16, 1 = uint32

        $maxpInfo = $this->readMaxp($reader, $tables);
        $numGlyphs = $maxpInfo['numGlyphs'];

        // Парсим loca offsets.
        $locaOffsets = $this->readLocaOffsets($reader, $tables, $numGlyphs, $indexToLocFormat);

        // Включаем composite-references транзитивно.
        $this->addCompositeComponents($sourceBytes, $tables['glyf']['offset'], $locaOffsets, $used);

        // Строим новый glyf + loca.
        [$newGlyf, $newLocaOffsets] = $this->buildSubsetGlyf(
            $sourceBytes,
            $tables['glyf']['offset'],
            $locaOffsets,
            $used,
        );
        $newLoca = $this->packLoca($newLocaOffsets, $indexToLocFormat);

        // Эмитим subset TTF. Все tables (кроме glyf/loca) копируются as-is.
        return $this->buildOutputTtf($sourceBytes, $tables, $newGlyf, $newLoca);
    }

    /**
     * @param  array<int, bool>|list<int>  $input
     * @return array<int, bool>
     */
    private function normalizeUsedGlyphs(array $input): array
    {
        if ($input === []) {
            return [];
        }
        $first = array_key_first($input);
        if (is_int($first) && is_bool($input[$first])) {
            return $input;
        }
        // List form → flip values to keys.
        $out = [];
        foreach ($input as $gid) {
            $out[(int) $gid] = true;
        }

        return $out;
    }

    /**
     * @return array<string, array{offset: int, length: int, tag: string}>
     */
    private function readTableDirectory(BinaryReader $reader): array
    {
        $reader->seek(0);
        $reader->skip(4); // scaler
        $numTables = $reader->readUInt16();
        $reader->skip(6); // searchRange + entrySelector + rangeShift

        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $tag = $reader->readTag();
            $reader->skip(4); // checksum
            $offset = $reader->readUInt32();
            $length = $reader->readUInt32();
            $tables[$tag] = ['offset' => $offset, 'length' => $length, 'tag' => $tag];
        }

        return $tables;
    }

    /**
     * @param  array<string, array{offset: int, length: int, tag: string}>  $tables
     * @return array{indexToLocFormat: int}
     */
    private function readHead(BinaryReader $reader, array $tables): array
    {
        $headOffset = $tables['head']['offset'];
        // indexToLocFormat is at offset 50 (head version 1.0).
        return ['indexToLocFormat' => $reader->peekUInt16($headOffset + 50)];
    }

    /**
     * @param  array<string, array{offset: int, length: int, tag: string}>  $tables
     * @return array{numGlyphs: int}
     */
    private function readMaxp(BinaryReader $reader, array $tables): array
    {
        // numGlyphs at offset 4 in maxp table.
        return ['numGlyphs' => $reader->peekUInt16($tables['maxp']['offset'] + 4)];
    }

    /**
     * loca содержит numGlyphs+1 offsets (last sentinel marks end of glyf).
     * Format 0: offsets — uint16, multiplied by 2 для actual byte offset.
     * Format 1: uint32 (direct byte offset).
     *
     * @param  array<string, array{offset: int, length: int, tag: string}>  $tables
     * @return list<int>
     */
    private function readLocaOffsets(BinaryReader $reader, array $tables, int $numGlyphs, int $format): array
    {
        $locaOffset = $tables['loca']['offset'];
        $offsets = [];
        $reader->seek($locaOffset);
        for ($i = 0; $i <= $numGlyphs; $i++) {
            $offsets[] = $format === 0
                ? $reader->readUInt16() * 2
                : $reader->readUInt32();
        }

        return $offsets;
    }

    /**
     * Composite glyph — содержит references на другие glyph'и через
     * `compositeGlyphHeader.glyphIndex` field. flag bit 0x20 (MORE_COMPONENTS)
     * означает что следующая component'а пойдёт.
     *
     * Glyph header (single + composite):
     *   int16 numberOfContours (negative = composite)
     *   int16 × 4 bbox
     *   Если composite: повторяющиеся entries:
     *     uint16 flags
     *     uint16 glyphIndex
     *     ...args (var size depending on flags)
     *     while flags & 0x20
     *
     * Мы lazily обходим composite refs и добавляем все referenced glyph'и
     * в $used. Recursion'но (composite of composite).
     *
     * @param  list<int>  $locaOffsets
     * @param  array<int, bool>  $used   modified in place
     */
    private function addCompositeComponents(
        string $sourceBytes,
        int $glyfBaseOffset,
        array $locaOffsets,
        array &$used,
    ): void {
        $stack = array_keys($used);
        $seen = $used;
        while ($stack !== []) {
            $gid = array_pop($stack);
            $start = $glyfBaseOffset + $locaOffsets[$gid];
            $end = $glyfBaseOffset + $locaOffsets[$gid + 1];
            if ($end - $start < 10) {
                continue; // пустой/simple glyph; нет composite refs
            }
            // numberOfContours at offset 0.
            $nc = unpack('n', substr($sourceBytes, $start, 2))[1];
            $nc = $nc >= 0x8000 ? $nc - 0x10000 : $nc; // sign extend
            if ($nc >= 0) {
                continue; // simple glyph — no components
            }
            // Composite — итерация components.
            $pos = $start + 10; // skip header + bbox
            while (true) {
                $flags = unpack('n', substr($sourceBytes, $pos, 2))[1];
                $componentGid = unpack('n', substr($sourceBytes, $pos + 2, 2))[1];
                if (! isset($seen[$componentGid])) {
                    $used[$componentGid] = true;
                    $seen[$componentGid] = true;
                    $stack[] = $componentGid;
                }
                $pos += 4;
                // Skip args (variable size; см. OpenType spec для bit-flags layout).
                // ARG_1_AND_2_ARE_WORDS (0x01) → +4 bytes; else +2.
                $pos += ($flags & 0x01) ? 4 : 2;
                if ($flags & 0x08) {
                    $pos += 2; // WE_HAVE_A_SCALE
                } elseif ($flags & 0x40) {
                    $pos += 4; // WE_HAVE_AN_X_AND_Y_SCALE
                } elseif ($flags & 0x80) {
                    $pos += 8; // WE_HAVE_A_TWO_BY_TWO
                }
                if (! ($flags & 0x20)) { // MORE_COMPONENTS
                    break;
                }
            }
        }
    }

    /**
     * Строит новый glyf bytes и corresponding loca offsets.
     *
     * Used glyphs → copy outline bytes from original glyf.
     * Unused → 0-byte entry (loca[i] == loca[i+1]).
     *
     * @param  list<int>  $locaOffsets
     * @param  array<int, bool>  $used
     * @return array{0: string, 1: list<int>}  newGlyf bytes + new loca offsets
     */
    private function buildSubsetGlyf(
        string $sourceBytes,
        int $glyfBaseOffset,
        array $locaOffsets,
        array $used,
    ): array {
        $newGlyf = '';
        $newOffsets = [];
        $cursor = 0;
        $numGlyphs = count($locaOffsets) - 1;
        for ($gid = 0; $gid < $numGlyphs; $gid++) {
            $newOffsets[] = $cursor;
            if (! isset($used[$gid])) {
                continue; // 0-byte entry
            }
            $start = $glyfBaseOffset + $locaOffsets[$gid];
            $length = $locaOffsets[$gid + 1] - $locaOffsets[$gid];
            if ($length === 0) {
                continue;
            }
            // Pad до 2-byte alignment (TTF requirement для glyf entries).
            $glyphBytes = substr($sourceBytes, $start, $length);
            if (strlen($glyphBytes) % 2 !== 0) {
                $glyphBytes .= "\x00";
            }
            $newGlyf .= $glyphBytes;
            $cursor += strlen($glyphBytes);
        }
        $newOffsets[] = $cursor; // sentinel

        return [$newGlyf, $newOffsets];
    }

    /**
     * Packs loca offsets back в binary (format 0 = uint16×2, format 1 = uint32).
     *
     * @param  list<int>  $offsets
     */
    private function packLoca(array $offsets, int $format): string
    {
        $out = '';
        foreach ($offsets as $offset) {
            if ($format === 0) {
                $out .= pack('n', $offset / 2);
            } else {
                $out .= pack('N', $offset);
            }
        }

        return $out;
    }

    /**
     * Эмитит final TTF с заменёнными glyf + loca tables.
     * Все остальные tables копируются bytes-as-is.
     *
     * Table directory: каждый entry — 16 bytes (tag + checksum + offset + length).
     * Tables aligned по 4-byte boundary в файле.
     *
     * @param  array<string, array{offset: int, length: int, tag: string}>  $tables
     */
    private function buildOutputTtf(string $sourceBytes, array $tables, string $newGlyf, string $newLoca): string
    {
        // Tables, которые PDF reader'у не нужны — мы их не embed'им
        // и значительно уменьшаем итоговый bundle:
        //  - GPOS/GSUB/GDEF — kerning/ligatures/glyph classes. PDF reader
        //    их не применяет (kerning делает caller через TJ-operator).
        //    Phase 2c+ parse'ит их ВНЕ subset'а, в TextMeasurer time.
        //  - DSIG — digital signature. Не нужно.
        //  - kern (legacy) — same как GPOS-kerning, не нужно для PDF.
        //  - hdmx, VDMX, vhea, vmtx — для horizontal/vertical metrics,
        //    специфичны для screen rendering. Не нужно PDF reader'у.
        //
        // cmap и name оставляем — formally needed для TTF-validity
        // (некоторые tools требуют, плюс наш собственный parser).
        $dropTables = ['GPOS', 'GSUB', 'GDEF', 'DSIG',
            'kern', 'hdmx', 'VDMX', 'vhea', 'vmtx', 'BASE', 'JSTF',
            'LTSH', 'PCLT', 'gasp', 'FFTM'];

        $newTableData = [];
        foreach ($tables as $tag => $info) {
            if (in_array(trim($tag), $dropTables, true)) {
                continue;
            }
            $bytes = match ($tag) {
                'glyf' => $newGlyf,
                'loca' => $newLoca,
                default => substr($sourceBytes, $info['offset'], $info['length']),
            };
            $newTableData[$tag] = $bytes;
        }

        $numTables = count($newTableData);

        // Compute searchRange / entrySelector / rangeShift для header.
        $entrySelector = (int) floor(log($numTables, 2));
        $searchRange = (1 << $entrySelector) * 16;
        $rangeShift = $numTables * 16 - $searchRange;

        // Заголовок: scaler (4) + numTables (2) + 3 × uint16 = 12 bytes.
        // Формат N (uint32) + n4 (4 × uint16) = 4 + 8 = 12 bytes total.
        $header = pack('Nn4', 0x00010000, $numTables, $searchRange, $entrySelector, $rangeShift);

        // Build table directory + table data.
        $directorySize = $numTables * 16;
        $tableDataOffset = 12 + $directorySize;

        $directory = '';
        $tableData = '';
        $currentOffset = $tableDataOffset;
        foreach ($newTableData as $tag => $bytes) {
            $length = strlen($bytes);
            // Checksum: sum of 32-bit big-endian dwords.
            $checksum = $this->tableChecksum($bytes);

            $directory .= str_pad($tag, 4, ' ').pack('N3', $checksum, $currentOffset, $length);

            $tableData .= $bytes;
            // Padding до 4-byte alignment.
            $pad = (4 - ($length % 4)) % 4;
            if ($pad > 0) {
                $tableData .= str_repeat("\x00", $pad);
            }
            $currentOffset += $length + $pad;
        }

        return $header.$directory.$tableData;
    }

    /**
     * Sum of big-endian uint32 values (zero-padded если нужно).
     */
    private function tableChecksum(string $bytes): int
    {
        $pad = (4 - (strlen($bytes) % 4)) % 4;
        if ($pad > 0) {
            $bytes .= str_repeat("\x00", $pad);
        }
        $sum = 0;
        $numDwords = strlen($bytes) / 4;
        for ($i = 0; $i < $numDwords; $i++) {
            $sum = ($sum + unpack('N', substr($bytes, $i * 4, 4))[1]) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
