<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 39: Multi-column layout block.
 *
 * Содержит body blocks которые рендерятся в N columns. Поток column-first:
 * content заполняет column 0 до bottom, переходит в column 1, ..., при
 * заполнении last column происходит page break и поток продолжается
 * с column 0 на новой page.
 *
 * Nesting: ColumnSet нельзя nest'ить — внутри columns должны быть
 * regular blocks (Paragraph, Table, List, ...).
 *
 * После ColumnSet cursor возвращается в single-column mode под лежащим
 * содержимым последней column (max(используемая y) по всем columns).
 */
final readonly class ColumnSet implements BlockElement
{
    /**
     * @param  list<BlockElement>  $body
     * @param  int  $columnCount  Number of columns (2..6 typical).
     * @param  float  $columnGapPt  Horizontal gap между columns.
     */
    public function __construct(
        public array $body,
        public int $columnCount = 2,
        public float $columnGapPt = 12.0,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 0,
    ) {
        if ($columnCount < 1) {
            throw new \InvalidArgumentException('ColumnSet columnCount must be >= 1');
        }
    }
}
