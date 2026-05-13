<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\KerningTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KerningTableTest extends TestCase
{
    #[Test]
    public function empty_when_no_pairs_added(): void
    {
        $kt = new KerningTable;
        self::assertTrue($kt->isEmpty());
        self::assertSame(0, $kt->pairCount());
    }

    #[Test]
    public function add_pair_increments_count(): void
    {
        $kt = new KerningTable;
        $kt->add(1, 2, -150);
        $kt->add(1, 3, -200);
        self::assertFalse($kt->isEmpty());
        self::assertSame(2, $kt->pairCount());
    }

    #[Test]
    public function lookup_returns_added_adjustment(): void
    {
        $kt = new KerningTable;
        $kt->add(36, 57, -152);
        self::assertSame(-152, $kt->lookup(36, 57));
    }

    #[Test]
    public function lookup_returns_zero_for_unknown_pair(): void
    {
        $kt = new KerningTable;
        $kt->add(36, 57, -152);
        self::assertSame(0, $kt->lookup(99, 100));
        self::assertSame(0, $kt->lookup(36, 100));
    }

    #[Test]
    public function zero_adjustment_is_not_stored(): void
    {
        $kt = new KerningTable;
        $kt->add(1, 2, 0);
        self::assertTrue($kt->isEmpty());
    }

    #[Test]
    public function adding_same_pair_overwrites(): void
    {
        $kt = new KerningTable;
        $kt->add(1, 2, -100);
        $kt->add(1, 2, -200); // re-add same pair с другим adj
        self::assertSame(-200, $kt->lookup(1, 2));
        self::assertSame(1, $kt->pairCount()); // не дублируется
    }
}
