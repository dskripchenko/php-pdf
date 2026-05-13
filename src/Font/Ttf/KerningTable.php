<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Kerning lookup table — pair-wise advance adjustments.
 *
 * Запрос:
 *   $kern->lookup(leftGid, rightGid): int  // xAdvance adjustment в FUnits
 *                                            // (negative = pair closer)
 *
 * Источник:
 *  - GPOS lookup type 2 «Pair Adjustment Positioning», format 1 (specific
 *    pairs) и format 2 (class-based pairs). Парсится GposReader'ом.
 *  - Legacy `kern` table (Apple TT) — not supported here; modern fonts
 *    используют GPOS exclusively.
 *
 * Use cases:
 *  - TextMeasurer: учитывает kerning при measure pt-width строки
 *  - PdfFont: эмитит TJ-operator с position adjustment'ами в content stream
 *
 * Performance: lookup O(1) через nested-array indexing.
 */
final class KerningTable
{
    /** @var array<int, array<int, int>>  leftGid → rightGid → xAdvance FU */
    private array $pairs = [];

    private int $pairCount = 0;

    public function add(int $leftGid, int $rightGid, int $xAdvanceFu): void
    {
        if ($xAdvanceFu === 0) {
            return; // не сохраняем нулевые pair'ы
        }
        if (! isset($this->pairs[$leftGid])) {
            $this->pairs[$leftGid] = [];
        }
        if (! isset($this->pairs[$leftGid][$rightGid])) {
            $this->pairCount++;
        }
        $this->pairs[$leftGid][$rightGid] = $xAdvanceFu;
    }

    /**
     * Lookup xAdvance adjustment для pair (left, right). Возвращает 0
     * если pair не задан (no kerning).
     */
    public function lookup(int $leftGid, int $rightGid): int
    {
        return $this->pairs[$leftGid][$rightGid] ?? 0;
    }

    public function isEmpty(): bool
    {
        return $this->pairCount === 0;
    }

    public function pairCount(): int
    {
        return $this->pairCount;
    }
}
