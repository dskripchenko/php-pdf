<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\RunStyleBuilder;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunStyleBuilderTest extends TestCase
{
    #[Test]
    public function empty_builder_yields_empty_style(): void
    {
        $style = (new RunStyleBuilder)->build();
        self::assertTrue($style->isEmpty());
    }

    #[Test]
    public function fluent_chain_sets_all_fields(): void
    {
        $style = (new RunStyleBuilder)
            ->size(13)
            ->color('#FF0000')
            ->background('00ff00')
            ->font('Helvetica')
            ->bold()
            ->italic()
            ->underline()
            ->strikethrough()
            ->highlight('yellow')
            ->build();

        self::assertSame(13.0, $style->sizePt);
        self::assertSame('ff0000', $style->color);
        self::assertSame('00ff00', $style->backgroundColor);
        self::assertSame('Helvetica', $style->fontFamily);
        self::assertTrue($style->bold);
        self::assertTrue($style->italic);
        self::assertTrue($style->underline);
        self::assertTrue($style->strikethrough);
        self::assertSame('yellow', $style->highlight);
    }

    #[Test]
    public function superscript_and_subscript_are_mutually_exclusive(): void
    {
        $sup = (new RunStyleBuilder)->superscript()->subscript()->build();
        self::assertFalse($sup->superscript);
        self::assertTrue($sup->subscript);

        $sub = (new RunStyleBuilder)->subscript()->superscript()->build();
        self::assertTrue($sub->superscript);
        self::assertFalse($sub->subscript);
    }

    #[Test]
    public function initial_style_seeds_the_builder(): void
    {
        $initial = (new RunStyle)->withBold()->withColor('123456');
        $style = (new RunStyleBuilder($initial))
            ->italic()
            ->build();

        self::assertTrue($style->bold);
        self::assertTrue($style->italic);
        self::assertSame('123456', $style->color);
    }

    #[Test]
    public function color_strips_hash_and_lowercases(): void
    {
        $style = (new RunStyleBuilder)->color('#ABCDEF')->build();
        self::assertSame('abcdef', $style->color);
    }
}
