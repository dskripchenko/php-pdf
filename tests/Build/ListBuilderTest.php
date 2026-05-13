<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ListBuilder;
use Dskripchenko\PhpPdf\Build\ListItemBuilder;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Style\ListFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListBuilderTest extends TestCase
{
    #[Test]
    public function bullet_factory_yields_bullet_list(): void
    {
        $l = ListBuilder::bullet()
            ->item('A')
            ->item('B')
            ->build();
        self::assertSame(ListFormat::Bullet, $l->effectiveFormat());
        self::assertCount(2, $l->items);
    }

    #[Test]
    public function ordered_factory_default_decimal(): void
    {
        $l = ListBuilder::ordered()->item('X')->build();
        self::assertSame(ListFormat::Decimal, $l->effectiveFormat());
    }

    #[Test]
    public function ordered_factory_with_roman_format(): void
    {
        $l = ListBuilder::ordered(ListFormat::UpperRoman)->item('Y')->build();
        self::assertSame(ListFormat::UpperRoman, $l->effectiveFormat());
    }

    #[Test]
    public function items_array_helper(): void
    {
        $l = ListBuilder::bullet()->items(['a', 'b', 'c'])->build();
        self::assertCount(3, $l->items);
    }

    #[Test]
    public function item_with_nested_list_via_callback(): void
    {
        $l = ListBuilder::bullet()
            ->item('Top', fn(ListBuilder $b) => $b->item('Sub 1')->item('Sub 2'))
            ->item('Second')
            ->build();

        self::assertNotNull($l->items[0]->nestedList);
        self::assertCount(2, $l->items[0]->nestedList->items);
        self::assertNull($l->items[1]->nestedList);
    }

    #[Test]
    public function item_builder_nest_with_explicit_format(): void
    {
        $l = ListBuilder::bullet()
            ->item(fn(ListItemBuilder $i) => $i
                ->text('Parent')
                ->nest(ListFormat::Decimal, fn(ListBuilder $sub) => $sub
                    ->item('1.1')
                    ->item('1.2')
                )
            )
            ->build();

        $nested = $l->items[0]->nestedList;
        self::assertNotNull($nested);
        self::assertSame(ListFormat::Decimal, $nested->effectiveFormat());
        self::assertCount(2, $nested->items);
    }

    #[Test]
    public function start_at_propagates(): void
    {
        $l = ListBuilder::ordered()->startAt(7)->item('a')->build();
        self::assertSame(7, $l->startAt);
    }

    #[Test]
    public function spacing_props_propagate(): void
    {
        $l = ListBuilder::bullet()
            ->spaceBefore(10)
            ->spaceAfter(12)
            ->item('A')
            ->build();
        self::assertSame(10.0, $l->spaceBeforePt);
        self::assertSame(12.0, $l->spaceAfterPt);
    }

    #[Test]
    public function document_builder_bullet_list(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn(ListBuilder $b) => $b->items(['One', 'Two']))
            ->build();
        $list = $doc->section->body[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(ListFormat::Bullet, $list->effectiveFormat());
    }

    #[Test]
    public function document_builder_ordered_list(): void
    {
        $doc = DocumentBuilder::new()
            ->orderedList(fn(ListBuilder $b) => $b->items(['I', 'II']), ListFormat::UpperRoman)
            ->build();
        $list = $doc->section->body[0];
        self::assertSame(ListFormat::UpperRoman, $list->effectiveFormat());
    }

    #[Test]
    public function full_smoke_renders_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Lists demo')
            ->bulletList(fn(ListBuilder $b) => $b
                ->item('First')
                ->item('Second with nested', fn($sub) => $sub
                    ->item('Nested A')
                    ->item('Nested B')
                )
                ->item('Third')
            )
            ->orderedList(fn(ListBuilder $b) => $b
                ->items(['Step one', 'Step two', 'Step three'])
            )
            ->toBytes();

        self::assertStringStartsWith('%PDF', $bytes);
    }
}
