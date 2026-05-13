<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Layout;

/**
 * Greedy line-breaking algorithm.
 *
 * Алгоритм:
 *   1. Разбиваем text на «слова» (по whitespace, плюс preserve any
 *      explicit `\n` как hard break).
 *   2. Idем word-by-word. Если current_line + " " + word проходит в
 *      maxWidthPt — append. Иначе — break, новая line с word.
 *   3. Слово шире чем maxWidthPt — character-wise break (URLs, длинные
 *      идентификаторы).
 *
 * Возвращает list<string> — каждая запись = одна строка для рендера.
 *
 * Не реализовано в v0.1:
 *  - Knuth-Plass optimal (требует backtracking + boxes-glues-penalties)
 *  - Hyphenation
 *  - Soft-hyphen (U+00AD) handling
 *  - Justification (это уже layout-stage, не breaking)
 *  - Hanging punctuation
 *  - Tab-stops
 *
 * Соответствует ADR-выбору «typography v0.1: kerning + basic ligatures
 * только; line breaking — greedy».
 */
final class LineBreaker
{
    public function __construct(
        private readonly TextMeasurer $measurer,
        private readonly float $maxWidthPt,
    ) {}

    /**
     * @return list<string>
     */
    public function wrap(string $text): array
    {
        $lines = [];
        // Honor explicit newlines — каждый "\n" = hard break.
        foreach (explode("\n", $text) as $paragraph) {
            $wrapped = $this->wrapParagraph($paragraph);
            foreach ($wrapped as $line) {
                $lines[] = $line;
            }
            // Каждый paragraph закачивается line — even если последняя
            // line непустая. Empty paragraph эмитит одну empty line
            // (= blank line в output).
            if ($wrapped === []) {
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wrapParagraph(string $paragraph): array
    {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            return [''];
        }

        $words = preg_split('/\s+/u', $paragraph) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->measurer->widthPt($candidate) <= $this->maxWidthPt) {
                $current = $candidate;

                continue;
            }
            // Слово не помещается с предыдущим content'ом.
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            // Если само слово шире maxWidth — character-wise break.
            if ($this->measurer->widthPt($word) > $this->maxWidthPt) {
                $broken = $this->breakLongWord($word);
                // Last fragment остаётся в current; остальные сразу
                // эмитим как finished lines.
                $last = array_pop($broken);
                foreach ($broken as $piece) {
                    $lines[] = $piece;
                }
                $current = $last ?? '';
            } else {
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Разбивает слово, которое шире maxWidthPt, на куски character-by-
     * character. Используется для URL'ов / long identifiers.
     *
     * @return list<string>
     */
    private function breakLongWord(string $word): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pieces = [];
        $current = '';
        foreach ($chars as $ch) {
            $candidate = $current.$ch;
            if ($this->measurer->widthPt($candidate) <= $this->maxWidthPt) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $pieces[] = $current;
            }
            $current = $ch;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
