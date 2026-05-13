<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * ListItem — один элемент списка.
 *
 * $children: list<BlockElement> — content (обычно Paragraph'ы, могут
 * быть Image/нестед Table).
 *
 * $nestedList: опциональный sub-ListNode для вложенных списков.
 * Renders сразу после children с увеличенным indent'ом.
 *
 * ListItem сам не BlockElement — живёт только внутри ListNode.
 */
final readonly class ListItem
{
    /**
     * @param  list<BlockElement>  $children
     */
    public function __construct(
        public array $children,
        public ?ListNode $nestedList = null,
    ) {}
}
