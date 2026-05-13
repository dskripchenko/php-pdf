<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Substitution rules для basic ligatures (GSUB lookup type 4).
 *
 * Структура:
 *   firstGlyph → list<LigatureRule>
 *
 * LigatureRule = {components: [g2, g3, ...], result: gN}
 * означает: если после firstGlyph следуют g2, g3, ... → заменить
 * (firstGlyph + g2 + g3 + ...) на single ligature glyph gN.
 *
 * Multiple rules per firstGlyph (e.g., «f» имеет правила для «fi», «fl»,
 * «ffi», «ffl») — длинные правила сортируются впереди, чтобы матчить
 * «ffi» раньше «fi».
 *
 * Apply algorithm: greedy longest-match per position.
 */
final class LigatureSubstitutions
{
    /** @var array<int, list<array{components: list<int>, result: int, sources: list<int>}>> */
    private array $byFirst = [];

    private int $ruleCount = 0;

    /**
     * @param  list<int>  $components  Glyph'ы AFTER first (т.е. для «fi»
     *                           передаём [i_gid]).
     */
    public function add(int $firstGlyph, array $components, int $resultGlyph): void
    {
        if ($components === []) {
            return;
        }
        $this->byFirst[$firstGlyph][] = [
            'components' => $components,
            'result' => $resultGlyph,
            // 'sources' — массив glyph ID'ов которые составляют ligature
            // (включая first). Используется PdfFont'ом для построения
            // multi-codepoint ToUnicode CMap.
            'sources' => array_merge([$firstGlyph], $components),
        ];
        $this->ruleCount++;

        // Сортируем rules для firstGlyph longest-first.
        usort($this->byFirst[$firstGlyph], static fn ($a, $b) => count($b['components']) - count($a['components']));
    }

    /**
     * Применяет ligature substitution к list'у glyph ID'ов. Greedy longest-
     * match per position. Возвращает substituted list + map результата на
     * source glyph'и (для ToUnicode CMap построения).
     *
     * @param  list<int>  $glyphs
     * @return array{glyphs: list<int>, sourceMap: array<int, list<int>>}
     *         glyphs — после substitution; sourceMap — ligatureGlyph →
     *         list of component glyph IDs (for ToUnicode multi-cp emission)
     */
    public function apply(array $glyphs): array
    {
        if ($this->ruleCount === 0) {
            return ['glyphs' => $glyphs, 'sourceMap' => []];
        }
        $result = [];
        $sourceMap = [];
        $n = count($glyphs);
        $i = 0;
        while ($i < $n) {
            $firstGid = $glyphs[$i];
            $matched = false;
            foreach ($this->byFirst[$firstGid] ?? [] as $rule) {
                $components = $rule['components'];
                $compCount = count($components);
                if ($i + $compCount >= $n) {
                    continue; // не хватает glyph'ов в input'е
                }
                $allMatch = true;
                for ($k = 0; $k < $compCount; $k++) {
                    if ($glyphs[$i + 1 + $k] !== $components[$k]) {
                        $allMatch = false;
                        break;
                    }
                }
                if (! $allMatch) {
                    continue;
                }
                // Match. Insert ligature glyph, advance $i.
                $result[] = $rule['result'];
                $sourceMap[$rule['result']] = $rule['sources'];
                $i += 1 + $compCount;
                $matched = true;
                break;
            }
            if (! $matched) {
                $result[] = $firstGid;
                $i++;
            }
        }

        return ['glyphs' => $result, 'sourceMap' => $sourceMap];
    }

    public function isEmpty(): bool
    {
        return $this->ruleCount === 0;
    }

    public function ruleCount(): int
    {
        return $this->ruleCount;
    }
}
