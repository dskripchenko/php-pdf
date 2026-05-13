<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Row таблицы — упорядоченная series cell'ов.
 *
 * $isHeader = true означает что row повторяется в начале каждой
 * следующей страницы при page-overflow (как `<thead>`).
 *
 * $heightPt — explicit row height. Если null, layout определяет
 * по высоте content'а самого высокого cell'а.
 */
final readonly class Row
{
    /**
     * @param  list<Cell>  $cells
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?float $heightPt = null,
    ) {}
}
