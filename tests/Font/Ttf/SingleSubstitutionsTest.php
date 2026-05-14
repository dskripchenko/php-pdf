<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\SingleSubstitutions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 143: GSUB Type 1 Single Substitution data class.
 */
final class SingleSubstitutionsTest extends TestCase
{
    #[Test]
    public function empty_state(): void
    {
        $sub = new SingleSubstitutions;
        self::assertTrue($sub->isEmpty());
        self::assertSame(0, $sub->ruleCount());
        self::assertFalse($sub->has(100));
        self::assertSame(100, $sub->substitute(100));
    }

    #[Test]
    public function add_and_substitute(): void
    {
        $sub = new SingleSubstitutions;
        $sub->add(100, 200);
        self::assertFalse($sub->isEmpty());
        self::assertSame(1, $sub->ruleCount());
        self::assertTrue($sub->has(100));
        self::assertSame(200, $sub->substitute(100));
        self::assertSame(101, $sub->substitute(101));  // unchanged
    }

    #[Test]
    public function apply_to_glyph_list(): void
    {
        $sub = new SingleSubstitutions;
        $sub->add(10, 100);
        $sub->add(20, 200);
        $result = $sub->apply([5, 10, 15, 20, 25]);
        self::assertSame([5, 100, 15, 200, 25], $result);
    }

    #[Test]
    public function apply_empty_returns_input(): void
    {
        $sub = new SingleSubstitutions;
        $result = $sub->apply([1, 2, 3]);
        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function multiple_rules(): void
    {
        $sub = new SingleSubstitutions;
        $sub->add(1, 11);
        $sub->add(2, 12);
        $sub->add(3, 13);
        self::assertSame(3, $sub->ruleCount());
        self::assertSame(['1' => 11, '2' => 12, '3' => 13], array_combine(
            array_map('strval', array_keys($sub->asArray())),
            $sub->asArray(),
        ));
    }
}
