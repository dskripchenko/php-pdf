<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\ListFormat;

/**
 * ListNode — block-level список (bullet или ordered).
 *
 * $items: list<ListItem>
 * $format: тип маркеров (Bullet/Decimal/LowerLetter/...). Если null —
 *   defaults на Bullet (для convenience).
 * $startAt: с какого числа начинается нумерация (default 1).
 *
 * Nested списки реализуются через ListItem.nestedList — render engine
 * рекурсивно отрисовывает с увеличенным indent'ом.
 */
final readonly class ListNode implements BlockElement
{
    /**
     * @param  list<ListItem>  $items
     */
    public function __construct(
        public array $items = [],
        public ?ListFormat $format = null,
        public int $startAt = 1,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 6,
    ) {}

    public function effectiveFormat(): ListFormat
    {
        return $this->format ?? ListFormat::Bullet;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
