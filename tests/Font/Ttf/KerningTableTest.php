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

    /** Packs (secondGlyph, xAdvance) pairs as GPOS stores them. */
    private static function pairs(int ...$words): string
    {
        return pack('n*', ...array_map(static fn (int $w): int => $w & 0xFFFF, $words));
    }

    /** @param  array<int, int>  $byGid  glyph id → value */
    private static function perGlyph(array $byGid, string $format = 'n'): string
    {
        $out = array_fill(0, max(array_keys($byGid)) + 1, 0);
        foreach ($byGid as $gid => $value) {
            $out[$gid] = $value;
        }

        return $format === 'C' ? implode('', array_map('chr', $out)) : pack('n*', ...$out);
    }

    #[Test]
    public function packed_pairs_answer_like_added_ones(): void
    {
        $kt = new KerningTable;
        $kt->addPairs(36, self::pairs(57, -152, 60, 40, 61, 0));
        self::assertSame(-152, $kt->lookup(36, 57));
        self::assertSame(40, $kt->lookup(36, 60));
        self::assertSame(0, $kt->lookup(36, 61));
        self::assertSame(0, $kt->lookup(36, 99));
        self::assertSame(0, $kt->lookup(37, 57));
        self::assertSame(2, $kt->pairCount());
    }

    #[Test]
    public function a_later_pair_wins_but_a_later_zero_does_not_hide_an_earlier_value(): void
    {
        $kt = new KerningTable;
        $kt->addPairs(1, self::pairs(2, -100, 3, -30));
        $kt->addPairs(1, self::pairs(2, -200, 3, 0));
        self::assertSame(-200, $kt->lookup(1, 2));
        self::assertSame(-30, $kt->lookup(1, 3));
        self::assertSame(2, $kt->pairCount());
    }

    #[Test]
    public function a_glyph_id_matching_inside_a_value_is_not_a_pair(): void
    {
        $kt = new KerningTable;
        // the value of the pair (5, 7) is 7: looking up second glyph 7 must not read it as a glyph
        $kt->addPairs(1, self::pairs(5, 7, 9, -20));
        self::assertSame(0, $kt->lookup(1, 7));
        self::assertSame(7, $kt->lookup(1, 5));
    }

    #[Test]
    public function class_subtable_answers_without_expanding(): void
    {
        $kt = new KerningTable;
        // first glyphs 10 (class 1) and 11 (default class 0) covered; 12 not covered
        // second glyphs 20 and 21 in class 1, 22 in class 0; matrix 2 x 2
        $kt->addClasses(
            self::perGlyph([10 => 1, 11 => 1], 'C'),
            self::perGlyph([10 => 1]),
            self::perGlyph([20 => 1, 21 => 1]),
            2,
            pack('n*', 0, -30 & 0xFFFF, 0, -80 & 0xFFFF),
        );
        self::assertFalse($kt->isEmpty());
        self::assertSame(-80, $kt->lookup(10, 21));
        self::assertSame(-30, $kt->lookup(11, 20));
        self::assertSame(0, $kt->lookup(12, 20), 'not covered');
        self::assertSame(0, $kt->lookup(10, 22), 'second glyph in class 0');
        self::assertSame(0, $kt->lookup(999, 999), 'past the end of every string');
        self::assertSame(4, $kt->pairCount());
    }

    #[Test]
    public function a_class_the_matrix_has_no_column_for_kerns_nothing(): void
    {
        $kt = new KerningTable;
        // second glyph 20 is in class 2, but the matrix is 2 x 2 (classes 0 and 1)
        $kt->addClasses(self::perGlyph([10 => 1], 'C'), self::perGlyph([10 => 0]), self::perGlyph([20 => 2, 21 => 1]), 2, pack('n*', 0, 50, 0, 70));
        self::assertSame(0, $kt->lookup(10, 20));
        self::assertSame(50, $kt->lookup(10, 21));
    }

    #[Test]
    public function newer_layers_answer_first_as_with_add(): void
    {
        $kt = new KerningTable;
        $kt->add(10, 20, -5);
        $kt->addClasses(self::perGlyph([10 => 1], 'C'), self::perGlyph([10 => 0]), self::perGlyph([20 => 1, 21 => 1]), 2, pack('n*', 0, -50 & 0xFFFF));
        $kt->add(10, 21, -7);
        self::assertSame(-50, $kt->lookup(10, 20));
        self::assertSame(-7, $kt->lookup(10, 21));
        self::assertSame(2, $kt->pairCount());
    }

    #[Test]
    public function an_all_zero_class_subtable_is_not_kept(): void
    {
        $kt = new KerningTable;
        $kt->addClasses(self::perGlyph([10 => 1], 'C'), self::perGlyph([10 => 0]), self::perGlyph([20 => 1]), 2, pack('n*', 0, 0));
        self::assertTrue($kt->isEmpty());
    }

    #[Test]
    public function a_pair_set_of_zero_adjustments_is_not_kept(): void
    {
        $kt = new KerningTable;
        // glyph ids are non-zero, every value is zero: nothing to answer
        $kt->addPairs(1, self::pairs(2, 0, 0xFF00, 0, 3, 0));
        self::assertTrue($kt->isEmpty());
        self::assertSame(0, $kt->pairCount());

        $kt->addPairs(1, self::pairs(2, 0, 3, -1));
        self::assertFalse($kt->isEmpty());
        self::assertSame(-1, $kt->lookup(1, 3));
    }
}
