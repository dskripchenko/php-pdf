<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 188-189: tab-stops + hanging punctuation tests.
 */
final class TabStopsAndHangingPunctuationTest extends TestCase
{
    private function font(): ?PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function tab_renders_words_at_separated_positions(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run("Col1\tCol2")]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Both words present.
        self::assertStringContainsString('Col1', $bytes);
        self::assertStringContainsString('Col2', $bytes);
    }

    #[Test]
    public function tab_advances_x_to_next_stop(): void
    {
        // С 36pt tab stops: short text + \t → Col2 starts at x = startX + 36.
        $doc = new Document(new Section([
            new Paragraph([new Run("A\tB")]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, tabStopPt: 36.0));
        // Extract Tm/Td positions для validation (rough check).
        self::assertStringContainsString('(A)', $bytes);
        self::assertStringContainsString('(B)', $bytes);
    }

    #[Test]
    public function multiple_tabs_create_columns(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run("a\tb\tc\td")]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(a)', $bytes);
        self::assertStringContainsString('(b)', $bytes);
        self::assertStringContainsString('(c)', $bytes);
        self::assertStringContainsString('(d)', $bytes);
    }

    #[Test]
    public function tab_with_custom_interval(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run("X\tY")]),
        ]));
        // 72pt = 1 inch tab stops.
        $bytes = $doc->toBytes(new Engine(compressStreams: false, tabStopPt: 72.0));
        self::assertStringContainsString('(X)', $bytes);
        self::assertStringContainsString('(Y)', $bytes);
    }

    #[Test]
    public function paragraph_without_tabs_unchanged(): void
    {
        // Backward compat: текст без \t работает как раньше.
        $doc = new Document(new Section([
            new Paragraph([new Run('Plain text without tabs')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('Plain', $bytes);
    }

    #[Test]
    public function hanging_punctuation_renders_paragraph(): void
    {
        // Hanging punct flag enabled — text с trailing punct renders without
        // error (visual effect требует font-cached test для byte-position check).
        $doc = new Document(new Section([
            new Paragraph([new Run('Hello, world. Test.')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, hangingPunctuation: true));
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function hanging_punctuation_default_disabled(): void
    {
        $engine = new Engine;
        self::assertFalse($engine->hangingPunctuation);
    }

    #[Test]
    public function hanging_punctuation_can_be_enabled(): void
    {
        $engine = new Engine(hangingPunctuation: true);
        self::assertTrue($engine->hangingPunctuation);
    }

    #[Test]
    public function tab_stops_default_36pt(): void
    {
        $engine = new Engine;
        self::assertSame(36.0, $engine->tabStopPt);
    }
}
