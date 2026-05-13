<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Build;

use Closure;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Row;

/**
 * Fluent builder для Row таблицы.
 *
 * `header()` помечает row как `<thead>`-like — Engine повторит её на
 * top'е каждой next page при page-overflow.
 */
final class RowBuilder
{
    /** @var list<Cell> */
    private array $cells = [];

    private bool $isHeader = false;

    private ?float $heightPt = null;

    public static function new(): self
    {
        return new self;
    }

    public function header(bool $value = true): self
    {
        $this->isHeader = $value;

        return $this;
    }

    public function height(float $pt): self
    {
        $this->heightPt = $pt;

        return $this;
    }

    /**
     * Adds cell. $content:
     *  - string         — single-paragraph cell с этим text'ом
     *  - Closure        — CellBuilder для full configuration
     *  - Cell           — готовый AST-node
     */
    public function cell(string|Closure|Cell $content): self
    {
        if ($content instanceof Cell) {
            $this->cells[] = $content;

            return $this;
        }
        $b = new CellBuilder;
        if (is_string($content)) {
            $b->text($content);
        } else {
            $content($b);
        }
        $this->cells[] = $b->build();

        return $this;
    }

    /**
     * Convenience: list<string> — n simple cell'ов с этими text'ами.
     *
     * @param  list<string>  $texts
     */
    public function cells(array $texts): self
    {
        foreach ($texts as $text) {
            $this->cell($text);
        }

        return $this;
    }

    public function build(): Row
    {
        return new Row(
            cells: $this->cells,
            isHeader: $this->isHeader,
            heightPt: $this->heightPt,
        );
    }
}
