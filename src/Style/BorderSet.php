<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Borders для всех сторон paragraph'а / cell'а.
 *
 * Каждая сторона опциональна — null означает «нет border'а». Все четыре
 * стороны могут быть разные.
 */
final readonly class BorderSet
{
    public function __construct(
        public ?Border $top = null,
        public ?Border $left = null,
        public ?Border $bottom = null,
        public ?Border $right = null,
    ) {}

    /**
     * Все 4 стороны одинаковые — convenience constructor.
     */
    public static function all(Border $border): self
    {
        return new self($border, $border, $border, $border);
    }

    public function hasAnyBorder(): bool
    {
        return $this->top !== null
            || $this->left !== null
            || $this->bottom !== null
            || $this->right !== null;
    }
}
