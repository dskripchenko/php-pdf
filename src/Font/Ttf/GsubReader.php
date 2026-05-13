<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * GSUB table parser — извлекает ligature substitutions для basic Latin
 * `liga` feature (fi, fl, ffi, ffl, etc.).
 *
 * Scope (Phase 2d):
 *  - lookup type 4 (Ligature Substitution) format 1 — единственный format
 *  - Только feature 'liga' (basic Latin ligatures fi, fl, ffi, ffl, ff)
 *  - Если 'liga' не найден — пустой LigatureSubstitutions (no fallback).
 *    Это сознательное решение для безопасности: discretionary ('dlig') и
 *    contextual ('ccmp') ligatures могут давать визуально неожиданные
 *    результаты в обычном тексте — applying their automatically wrong.
 *    Liberation fonts, например, не имеют 'liga' (metric-compatible
 *    с MS Arial который тоже без него); это OK — пользователь получит
 *    текст без ligatures, но width-measurement точно совпадёт с Arial.
 *
 * Не покрывает:
 *  - 'dlig' (discretionary ligatures — st, ct и т.п.); 'hlig' (historical);
 *    'rlig' (required для Arabic/Indic); 'clig' (contextual)
 *  - lookup types 1-3 (single/multiple/alternate substitution)
 *  - lookup types 5-8 (contextual, chaining contextual, extension, reverse)
 *  - Script/langSys filtering — мы grab'аем default langSys для default
 *    script
 *
 * Reference: OpenType GSUB spec, https://docs.microsoft.com/typography/opentype/spec/gsub
 */
final class GsubReader
{
    /**
     * @param  array{offset: int, length: int}  $gsubTableInfo
     */
    public function read(string $sourceBytes, array $gsubTableInfo): LigatureSubstitutions
    {
        $sub = new LigatureSubstitutions;
        $base = $gsubTableInfo['offset'];
        $reader = new BinaryReader($sourceBytes);

        // GSUB Header (тот же layout что и GPOS):
        //   uint16 majorVersion
        //   uint16 minorVersion
        //   uint16 scriptListOffset
        //   uint16 featureListOffset
        //   uint16 lookupListOffset
        $reader->seek($base);
        $reader->skip(4); // major + minor version
        $reader->skip(2); // scriptListOffset — мы не filter'им по script
        $featureListOffset = $reader->readUInt16();
        $lookupListOffset = $reader->readUInt16();

        // Найти lookup indexes для feature 'liga'.
        $ligaLookups = $this->findLigaLookupIndices($sourceBytes, $base + $featureListOffset);
        if ($ligaLookups !== []) {
            $this->readLookupListWithFilter($sourceBytes, $base + $lookupListOffset, $ligaLookups, $sub);
        }
        // Если 'liga' нет — sub остаётся empty (no fallback на dlig/ccmp).

        return $sub;
    }

    /**
     * FeatureList:
     *   uint16 featureCount
     *   FeatureRecord featureRecords[featureCount]:
     *     Tag (4 bytes ASCII) featureTag
     *     Offset16 featureOffset (от FeatureList start)
     *
     * FeatureTable:
     *   uint16 featureParamsOffset (мы не используем)
     *   uint16 lookupIndexCount
     *   uint16 lookupListIndices[lookupIndexCount]
     *
     * Возвращаем set lookup-indices для всех 'liga' features.
     *
     * @return array<int, true>
     */
    private function findLigaLookupIndices(string $bytes, int $featureListOffset): array
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($featureListOffset);
        $featureCount = $reader->readUInt16();

        $ligaLookupIndices = [];
        for ($i = 0; $i < $featureCount; $i++) {
            $tag = $reader->readTag();
            $offset = $reader->readUInt16();
            if (trim($tag) !== 'liga') {
                continue;
            }
            // Resolve feature table at $featureListOffset + $offset.
            $featurePos = $featureListOffset + $offset;
            $reader2 = new BinaryReader($bytes);
            $reader2->seek($featurePos);
            $reader2->skip(2); // featureParamsOffset
            $count = $reader2->readUInt16();
            for ($j = 0; $j < $count; $j++) {
                $idx = $reader2->readUInt16();
                $ligaLookupIndices[$idx] = true;
            }
        }

        return $ligaLookupIndices;
    }

    /**
     * @param  array<int, true>  $filterIndices
     */
    private function readLookupListWithFilter(string $bytes, int $listOffset, array $filterIndices, LigatureSubstitutions $sub): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($listOffset);
        $lookupCount = $reader->readUInt16();

        for ($i = 0; $i < $lookupCount; $i++) {
            if (! isset($filterIndices[$i])) {
                continue;
            }
            $reader->seek($listOffset + 2 + $i * 2);
            $lookupOffset = $reader->readUInt16();
            $this->readLookup($bytes, $listOffset + $lookupOffset, $sub);
        }
    }

    /**
     * Lookup header:
     *   uint16 lookupType
     *   uint16 lookupFlag
     *   uint16 subTableCount
     *   Offset16 subtableOffsets[subTableCount]
     */
    private function readLookup(string $bytes, int $lookupOffset, LigatureSubstitutions $sub): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($lookupOffset);
        $lookupType = $reader->readUInt16();
        $reader->skip(2); // lookupFlag
        $subTableCount = $reader->readUInt16();

        if ($lookupType !== 4) {
            return; // нас интересуют только ligature substitutions
        }

        for ($i = 0; $i < $subTableCount; $i++) {
            $reader->seek($lookupOffset + 6 + $i * 2);
            $subtableOffset = $reader->readUInt16();
            $this->readLigatureSubst($bytes, $lookupOffset + $subtableOffset, $sub);
        }
    }

    /**
     * LigatureSubstFormat1:
     *   uint16 substFormat (= 1)
     *   Offset16 coverageOffset
     *   uint16 ligatureSetCount
     *   Offset16 ligatureSetOffsets[ligatureSetCount]
     *
     * LigatureSet (для одного «first» glyph'а — coverage[i]):
     *   uint16 ligatureCount
     *   Offset16 ligatureOffsets[ligatureCount]
     *
     * Ligature:
     *   uint16 ligatureGlyph
     *   uint16 componentCount
     *   uint16 componentGlyphIDs[componentCount - 1]   ← без first glyph'а
     */
    private function readLigatureSubst(string $bytes, int $subOffset, LigatureSubstitutions $sub): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($subOffset);
        $format = $reader->readUInt16();
        if ($format !== 1) {
            return;
        }
        $coverageOffset = $reader->readUInt16();
        $ligatureSetCount = $reader->readUInt16();

        $coverageGlyphs = $this->readCoverage($bytes, $subOffset + $coverageOffset);
        if (count($coverageGlyphs) !== $ligatureSetCount) {
            return; // malformed
        }

        for ($i = 0; $i < $ligatureSetCount; $i++) {
            $firstGlyph = $coverageGlyphs[$i];
            $reader->seek($subOffset + 6 + $i * 2);
            $setOffset = $reader->readUInt16();
            $this->readLigatureSet($bytes, $subOffset + $setOffset, $firstGlyph, $sub);
        }
    }

    private function readLigatureSet(string $bytes, int $setOffset, int $firstGlyph, LigatureSubstitutions $sub): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($setOffset);
        $ligatureCount = $reader->readUInt16();

        for ($i = 0; $i < $ligatureCount; $i++) {
            $reader->seek($setOffset + 2 + $i * 2);
            $ligOffset = $reader->readUInt16();
            $reader->seek($setOffset + $ligOffset);
            $ligatureGlyph = $reader->readUInt16();
            $componentCount = $reader->readUInt16();
            $components = [];
            // componentGlyphIDs — это components[1..componentCount-1].
            // Component 0 = first glyph (известен из coverage).
            for ($c = 1; $c < $componentCount; $c++) {
                $components[] = $reader->readUInt16();
            }
            if ($components !== []) {
                $sub->add($firstGlyph, $components, $ligatureGlyph);
            }
        }
    }

    /**
     * @return list<int>
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
}
