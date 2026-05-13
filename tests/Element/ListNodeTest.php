<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Element;

use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Style\ListFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListNodeTest extends TestCase
{
    private function item(string $text): ListItem
    {
        return new ListItem([new Paragraph([new Run($text)])]);
    }

    #[Test]
    public function empty_list_is_empty(): void
    {
        self::assertTrue((new ListNode)->isEmpty());
    }

    #[Test]
    public function default_format_is_bullet(): void
    {
        $l = new ListNode([$this->item('A')]);
        self::assertSame(ListFormat::Bullet, $l->effectiveFormat());
    }

    #[Test]
    public function explicit_format_used(): void
    {
        $l = new ListNode([$this->item('A')], format: ListFormat::LowerRoman);
        self::assertSame(ListFormat::LowerRoman, $l->effectiveFormat());
    }

    #[Test]
    public function format_ordered_predicate(): void
    {
        self::assertFalse(ListFormat::Bullet->isOrdered());
        self::assertTrue(ListFormat::Decimal->isOrdered());
        self::assertTrue(ListFormat::LowerLetter->isOrdered());
        self::assertTrue(ListFormat::UpperRoman->isOrdered());
    }

    #[Test]
    public function nested_list_stored_on_item(): void
    {
        $nested = new ListNode([$this->item('child')]);
        $item = new ListItem(children: [new Paragraph([new Run('parent')])], nestedList: $nested);
        self::assertSame($nested, $item->nestedList);
    }

    #[Test]
    public function start_at_can_offset_numbering(): void
    {
        $l = new ListNode([$this->item('A')], format: ListFormat::Decimal, startAt: 5);
        self::assertSame(5, $l->startAt);
    }
}
