<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\TableStyle;

/**
 * Table — block-level элемент с rows of cells.
 *
 * $columnWidthsPt — explicit list of column widths в pt. Если null,
 * layout-engine распределяет ширину равномерно между столбцами.
 *
 * Number of columns = max gridSpan-сумма по rows; layout engine
 * валидирует consistency.
 */
final readonly class Table implements BlockElement
{
    /**
     * @param  list<Row>  $rows
     * @param  list<float>|null  $columnWidthsPt
     */
    public function __construct(
        public array $rows = [],
        public TableStyle $style = new TableStyle,
        public ?array $columnWidthsPt = null,
        public ?string $caption = null,
    ) {}

    /**
     * Число столбцов = max(sum gridSpan по row).
     */
    public function columnCount(): int
    {
        $max = 0;
        foreach ($this->rows as $row) {
            $sum = 0;
            foreach ($row->cells as $cell) {
                $sum += $cell->columnSpan;
            }
            if ($sum > $max) {
                $max = $sum;
            }
        }

        return $max;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
