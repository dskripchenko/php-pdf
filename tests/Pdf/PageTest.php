<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Page;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    #[Test]
    public function portrait_dimensions(): void
    {
        $p = new Page(PaperSize::A4);
        self::assertSame(595.28, $p->widthPt());
        self::assertSame(841.89, $p->heightPt());
    }

    #[Test]
    public function landscape_swaps_dimensions(): void
    {
        $p = new Page(PaperSize::A4, Orientation::Landscape);
        self::assertSame(841.89, $p->widthPt());
        self::assertSame(595.28, $p->heightPt());
    }

    #[Test]
    public function show_text_registers_font_with_resource_name(): void
    {
        $p = new Page(PaperSize::A4);
        $p->showText('Hi', 72, 720, StandardFont::TimesRoman, 12);

        $fonts = $p->standardFonts();
        self::assertCount(1, $fonts);
        self::assertContainsOnlyInstancesOf(StandardFont::class, $fonts);
        // Имя ресурса начинается с F.
        $name = array_key_first($fonts);
        self::assertMatchesRegularExpression('/^F\d+$/', $name);
    }

    #[Test]
    public function repeated_show_text_with_same_font_does_not_duplicate_registration(): void
    {
        $p = new Page(PaperSize::A4);
        $p->showText('A', 72, 720, StandardFont::Helvetica, 12);
        $p->showText('B', 72, 700, StandardFont::Helvetica, 14);
        $p->showText('C', 72, 680, StandardFont::Helvetica, 11);

        self::assertCount(1, $p->standardFonts());
    }

    #[Test]
    public function different_fonts_yield_different_resource_names(): void
    {
        $p = new Page(PaperSize::A4);
        $p->showText('A', 72, 720, StandardFont::TimesRoman, 12);
        $p->showText('B', 72, 700, StandardFont::Helvetica, 12);
        $p->showText('C', 72, 680, StandardFont::Courier, 12);

        $fonts = $p->standardFonts();
        self::assertCount(3, $fonts);
        self::assertSame(
            [StandardFont::TimesRoman, StandardFont::Helvetica, StandardFont::Courier],
            array_values($fonts),
        );
    }

    #[Test]
    public function content_stream_contains_text_operators(): void
    {
        $p = new Page(PaperSize::A4);
        $p->showText('Hello', 72, 720, StandardFont::TimesRoman, 12);

        $stream = $p->buildContentStream();
        self::assertStringContainsString('BT', $stream);
        self::assertStringContainsString('Tf', $stream);
        self::assertStringContainsString('Td', $stream);
        self::assertStringContainsString('(Hello) Tj', $stream);
        self::assertStringContainsString('ET', $stream);
    }

    #[Test]
    public function fill_rect_appears_in_content_stream(): void
    {
        $p = new Page(PaperSize::A4);
        $p->fillRect(10, 20, 100, 50, 1, 0, 0);

        $stream = $p->buildContentStream();
        self::assertStringContainsString('1 0 0 rg', $stream);
        self::assertStringContainsString('10 20 100 50 re', $stream);
        self::assertStringContainsString("f\n", $stream);
    }

    #[Test]
    public function stroke_rect_appears_in_content_stream(): void
    {
        $p = new Page(PaperSize::A4);
        $p->strokeRect(10, 20, 100, 50, 1, 0, 1, 0);

        $stream = $p->buildContentStream();
        self::assertStringContainsString('0 1 0 RG', $stream);
        self::assertStringContainsString("S\n", $stream);
    }

    #[Test]
    public function fluent_api_returns_self(): void
    {
        $p = new Page(PaperSize::A4);
        $result = $p
            ->showText('Hi', 72, 720, StandardFont::TimesRoman, 12)
            ->fillRect(0, 0, 10, 10)
            ->strokeRect(0, 0, 10, 10);

        self::assertSame($p, $result);
    }
}
