<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Style;

use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunStyleTest extends TestCase
{
    #[Test]
    public function default_style_is_empty(): void
    {
        $s = new RunStyle;
        self::assertTrue($s->isEmpty());
        self::assertNull($s->sizePt);
        self::assertNull($s->fontFamily);
        self::assertFalse($s->bold);
    }

    #[Test]
    public function with_bold_returns_modified_copy(): void
    {
        $a = new RunStyle;
        $b = $a->withBold();
        self::assertFalse($a->bold);
        self::assertTrue($b->bold);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function with_color_strips_hash_and_lowercases(): void
    {
        $a = (new RunStyle)->withColor('#FF0000');
        self::assertSame('ff0000', $a->color);

        $b = (new RunStyle)->withColor('14B8A6');
        self::assertSame('14b8a6', $b->color);
    }

    #[Test]
    public function superscript_clears_subscript_and_vice_versa(): void
    {
        $sup = (new RunStyle)->withSubscript()->withSuperscript();
        self::assertTrue($sup->superscript);
        self::assertFalse($sup->subscript);

        $sub = (new RunStyle)->withSuperscript()->withSubscript();
        self::assertTrue($sub->subscript);
        self::assertFalse($sub->superscript);
    }

    #[Test]
    public function inheritFrom_fills_null_fields(): void
    {
        $parent = new RunStyle(sizePt: 12, color: '333333', fontFamily: 'Arial');
        $child = (new RunStyle)->withBold();
        $effective = $child->inheritFrom($parent);

        self::assertSame(12.0, $effective->sizePt);
        self::assertSame('333333', $effective->color);
        self::assertSame('Arial', $effective->fontFamily);
        self::assertTrue($effective->bold);
    }

    #[Test]
    public function inheritFrom_preserves_explicit_child_values(): void
    {
        $parent = new RunStyle(sizePt: 12, color: '333333');
        $child = new RunStyle(sizePt: 16);
        $effective = $child->inheritFrom($parent);

        self::assertSame(16.0, $effective->sizePt);  // child wins
        self::assertSame('333333', $effective->color); // parent inherited
    }

    #[Test]
    public function size_pt_via_with(): void
    {
        $s = (new RunStyle)->withSizePt(14.5);
        self::assertSame(14.5, $s->sizePt);
    }
}
