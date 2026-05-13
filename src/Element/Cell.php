<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\CellStyle;

/**
 * Cell таблицы — содержит произвольные BlockElement'ы (Paragraph,
 * Image, даже nested Table'ы).
 *
 * columnSpan/rowSpan ≥ 1 (default 1). Span'ы > 1 означают cell
 * растягивается на N столбцов / M строк. Layout-engine обрабатывает.
 *
 * Cell сам не реализует BlockElement — он живёт только внутри Row.
 */
final readonly class Cell
{
    /**
     * @param  list<BlockElement>  $children
     */
    public function __construct(
        public array $children = [],
        public CellStyle $style = new CellStyle,
        public int $columnSpan = 1,
        public int $rowSpan = 1,
    ) {
        if ($columnSpan < 1) {
            throw new \InvalidArgumentException("columnSpan must be ≥ 1, got $columnSpan");
        }
        if ($rowSpan < 1) {
            throw new \InvalidArgumentException("rowSpan must be ≥ 1, got $rowSpan");
        }
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
