<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\LigatureSubstitutions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LigatureSubstitutionsTest extends TestCase
{
    #[Test]
    public function empty_passes_glyphs_through(): void
    {
        $sub = new LigatureSubstitutions;
        $result = $sub->apply([1, 2, 3]);
        self::assertSame([1, 2, 3], $result['glyphs']);
        self::assertSame([], $result['sourceMap']);
    }

    #[Test]
    public function single_pair_ligature_substitutes(): void
    {
        $sub = new LigatureSubstitutions;
        // f=10, i=11 → fi=100 (synthetic)
        $sub->add(10, [11], 100);

        $result = $sub->apply([10, 11]);
        self::assertSame([100], $result['glyphs']);
        self::assertArrayHasKey(100, $result['sourceMap']);
        self::assertSame([10, 11], $result['sourceMap'][100]);
    }

    #[Test]
    public function longer_match_wins_over_shorter(): void
    {
        $sub = new LigatureSubstitutions;
        // fi → 100; ffi → 200
        $sub->add(10, [11], 100);
        $sub->add(10, [10, 11], 200);

        // Input: f, f, i → должно match'нуть ffi первым
        $result = $sub->apply([10, 10, 11]);
        self::assertSame([200], $result['glyphs']);
        self::assertSame([10, 10, 11], $result['sourceMap'][200]);
    }

    #[Test]
    public function nonmatch_keeps_glyphs(): void
    {
        $sub = new LigatureSubstitutions;
        $sub->add(10, [11], 100);

        $result = $sub->apply([10, 12]);  // f, x — not match
        self::assertSame([10, 12], $result['glyphs']);
        self::assertSame([], $result['sourceMap']);
    }

    #[Test]
    public function partial_match_at_end_does_not_substitute(): void
    {
        $sub = new LigatureSubstitutions;
        $sub->add(10, [11, 12], 100); // need [11, 12] after 10

        // Last position — нет места для 2 components
        $result = $sub->apply([10, 11]);
        self::assertSame([10, 11], $result['glyphs']);
    }

    #[Test]
    public function multiple_ligatures_in_one_pass(): void
    {
        $sub = new LigatureSubstitutions;
        $sub->add(10, [11], 100); // fi → 100
        $sub->add(20, [21], 200); // fl → 200 (synthetic separate first)

        $result = $sub->apply([10, 11, 30, 20, 21]); // fi x fl
        self::assertSame([100, 30, 200], $result['glyphs']);
    }

    #[Test]
    public function continues_after_match(): void
    {
        $sub = new LigatureSubstitutions;
        $sub->add(10, [11], 100);

        // fi fi
        $result = $sub->apply([10, 11, 10, 11]);
        self::assertSame([100, 100], $result['glyphs']);
    }

    #[Test]
    public function rule_count(): void
    {
        $sub = new LigatureSubstitutions;
        self::assertSame(0, $sub->ruleCount());
        self::assertTrue($sub->isEmpty());

        $sub->add(10, [11], 100);
        $sub->add(10, [11, 12], 200);
        $sub->add(20, [21], 300);

        self::assertSame(3, $sub->ruleCount());
        self::assertFalse($sub->isEmpty());
    }
}
