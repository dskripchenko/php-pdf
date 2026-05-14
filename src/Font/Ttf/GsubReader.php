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
     * Backwards-compatible API: returns LigatureSubstitutions for 'liga'.
     *
     * @param  array{offset: int, length: int}  $gsubTableInfo
     */
    public function read(string $sourceBytes, array $gsubTableInfo): LigatureSubstitutions
    {
        $result = $this->readByFeature($sourceBytes, $gsubTableInfo, 'liga');

        return $result['liga'];
    }

    /**
     * Phase 143: read GSUB lookups for a specific feature tag, returning
     * both ligature (Type 4) и single (Type 1) substitutions found.
     *
     * @param  array{offset: int, length: int}  $gsubTableInfo
     * @return array{liga: LigatureSubstitutions, single: SingleSubstitutions}
     */
    public function readByFeature(string $sourceBytes, array $gsubTableInfo, string $featureTag): array
    {
        $liga = new LigatureSubstitutions;
        $single = new SingleSubstitutions;
        $base = $gsubTableInfo['offset'];
        $reader = new BinaryReader($sourceBytes);

        $reader->seek($base);
        $reader->skip(4); // major + minor version
        $reader->skip(2); // scriptListOffset
        $featureListOffset = $reader->readUInt16();
        $lookupListOffset = $reader->readUInt16();

        $featureLookups = $this->findFeatureLookupIndices($sourceBytes, $base + $featureListOffset, $featureTag);
        if ($featureLookups !== []) {
            $this->readLookupListWithFilter($sourceBytes, $base + $lookupListOffset, $featureLookups, $liga, $single);
        }

        return ['liga' => $liga, 'single' => $single];
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
        return $this->findFeatureLookupIndices($bytes, $featureListOffset, 'liga');
    }

    /**
     * Phase 143: generic feature-tag lookup finder.
     *
     * @return array<int, true>
     */
    private function findFeatureLookupIndices(string $bytes, int $featureListOffset, string $featureTag): array
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($featureListOffset);
        $featureCount = $reader->readUInt16();

        $lookupIndices = [];
        for ($i = 0; $i < $featureCount; $i++) {
            $tag = $reader->readTag();
            $offset = $reader->readUInt16();
            if (rtrim($tag) !== $featureTag) {
                continue;
            }
            $featurePos = $featureListOffset + $offset;
            $reader2 = new BinaryReader($bytes);
            $reader2->seek($featurePos);
            $reader2->skip(2); // featureParamsOffset
            $count = $reader2->readUInt16();
            for ($j = 0; $j < $count; $j++) {
                $idx = $reader2->readUInt16();
                $lookupIndices[$idx] = true;
            }
        }

        return $lookupIndices;
    }

    /**
     * @param  array<int, true>  $filterIndices
     */
    private function readLookupListWithFilter(
        string $bytes, int $listOffset, array $filterIndices,
        LigatureSubstitutions $liga, ?SingleSubstitutions $single = null,
    ): void {
        $reader = new BinaryReader($bytes);
        $reader->seek($listOffset);
        $lookupCount = $reader->readUInt16();

        for ($i = 0; $i < $lookupCount; $i++) {
            if (! isset($filterIndices[$i])) {
                continue;
            }
            $reader->seek($listOffset + 2 + $i * 2);
            $lookupOffset = $reader->readUInt16();
            $this->readLookup($bytes, $listOffset + $lookupOffset, $liga, $single);
        }
    }

    /**
     * Lookup header:
     *   uint16 lookupType
     *   uint16 lookupFlag
     *   uint16 subTableCount
     *   Offset16 subtableOffsets[subTableCount]
     */
    private function readLookup(string $bytes, int $lookupOffset, LigatureSubstitutions $liga, ?SingleSubstitutions $single = null): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($lookupOffset);
        $lookupType = $reader->readUInt16();
        $reader->skip(2); // lookupFlag
        $subTableCount = $reader->readUInt16();

        if ($lookupType !== 4 && $lookupType !== 1) {
            return; // currently only Type 1 (Single) и Type 4 (Ligature) supported
        }

        for ($i = 0; $i < $subTableCount; $i++) {
            $reader->seek($lookupOffset + 6 + $i * 2);
            $subtableOffset = $reader->readUInt16();
            if ($lookupType === 4) {
                $this->readLigatureSubst($bytes, $lookupOffset + $subtableOffset, $liga);
            } else { // type 1
                if ($single !== null) {
                    $this->readSingleSubst($bytes, $lookupOffset + $subtableOffset, $single);
                }
            }
        }
    }

    /**
     * Phase 143: GSUB Lookup Type 1 (Single Substitution).
     *
     * Format 1:
     *   uint16 substFormat (= 1)
     *   Offset16 coverageOffset
     *   int16 deltaGlyphID  → output = (coverage_glyph + delta) & 0xFFFF
     *
     * Format 2:
     *   uint16 substFormat (= 2)
     *   Offset16 coverageOffset
     *   uint16 glyphCount
     *   uint16 substituteGlyphIDs[glyphCount]
     */
    private function readSingleSubst(string $bytes, int $subOffset, SingleSubstitutions $sub): void
    {
        $reader = new BinaryReader($bytes);
        $reader->seek($subOffset);
        $format = $reader->readUInt16();
        $coverageOffset = $reader->readUInt16();
        $coverageGlyphs = $this->readCoverage($bytes, $subOffset + $coverageOffset);
        if ($format === 1) {
            $delta = $reader->readInt16();
            foreach ($coverageGlyphs as $gid) {
                $sub->add($gid, ($gid + $delta) & 0xFFFF);
            }
        } elseif ($format === 2) {
            $glyphCount = $reader->readUInt16();
            if ($glyphCount !== count($coverageGlyphs)) {
                return; // malformed
            }
            foreach ($coverageGlyphs as $i => $gid) {
                $substituteGid = $reader->readUInt16();
                $sub->add($gid, $substituteGid);
            }
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
