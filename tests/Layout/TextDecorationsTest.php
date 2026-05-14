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
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextDecorationsTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function underline_emits_stroke_below_baseline(): void
    {
        // Baseline для default 11pt text starts около y = topY - 11×0.8 ≈ topY - 8.8.
        // Underline = baseline - 11×0.12 ≈ baseline - 1.32.
        $doc = new Document(new Section([
            new Paragraph([new Run('underline', (new RunStyle)->withUnderline())]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Stroke operator 'S' должен присутствовать (для underline line).
        self::assertStringContainsString("S\n", $bytes);
        // С regular text (no underline) — stroke count = 0.
        $docRegular = new Document(new Section([
            new Paragraph([new Run('plain')]),
        ]));
        $bytesRegular = $docRegular->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertGreaterThan(
            substr_count($bytesRegular, "S\n"),
            substr_count($bytes, "S\n"),
            'Underline should add stroke operators',
        );
    }

    #[Test]
    public function strikethrough_emits_stroke(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('strike', (new RunStyle)->withStrikethrough())]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertStringContainsString("S\n", $bytes);
    }

    #[Test]
    public function both_underline_and_strike_produce_two_strokes(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('both',
                (new RunStyle)->withUnderline()->withStrikethrough()
            )]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // Two stroke ops per word — underline + strike.
        self::assertGreaterThanOrEqual(2, substr_count($bytes, "S\n"));
    }

    #[Test]
    public function decoration_uses_run_color_when_set(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('red underline',
                (new RunStyle)->withUnderline()->withColor('cc0000')
            )]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 0xcc/255 ≈ 0.8 in RG (stroke color).
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+RG@', $bytes);
    }

    #[Test]
    public function no_decoration_no_extra_strokes(): void
    {
        $docDecorated = new Document(new Section([
            new Paragraph([new Run('U', (new RunStyle)->withUnderline())]),
        ]));
        $docPlain = new Document(new Section([
            new Paragraph([new Run('P')]),
        ]));
        $bytesDec = $docDecorated->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $bytesPlain = $docPlain->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertGreaterThan(
            substr_count($bytesPlain, "S\n"),
            substr_count($bytesDec, "S\n"),
        );
    }

    #[Test]
    public function underline_drawn_under_text_when_underlined(): void
    {
        // Phase 158: multi-word underlined run теперь batched в single
        // showText + single continuous underline stroke под всем batch.
        // Раньше: 4 words = 4 strokes (per-word). Теперь: 4 words = 1 stroke
        // (per-batch). Тестируем что underline присутствует (≥1 stroke).
        $doc = new Document(new Section([
            new Paragraph([new Run('one two three four',
                (new RunStyle)->withUnderline()
            )]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $sCount = substr_count($bytes, "S\n");
        self::assertGreaterThanOrEqual(1, $sCount, 'underline должен быть нарисован');

        // Compare с baseline: без underline — strokes count меньше.
        $docPlain = new Document(new Section([
            new Paragraph([new Run('one two three four')]),
        ]));
        $bytesPlain = $docPlain->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertGreaterThan(substr_count($bytesPlain, "S\n"), $sCount,
            'underlined paragraph должен иметь больше strokes чем plain');
    }
}
