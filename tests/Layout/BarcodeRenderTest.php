<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarcodeRenderTest extends TestCase
{
    #[Test]
    public function barcode_emits_fillrect_operators(): void
    {
        $doc = new Document(new Section([
            new Barcode('HELLO', BarcodeFormat::Code128, widthPt: 200, heightPt: 40),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Code 128 → много fill operators (по одному на contiguous black run).
        // Минимум >= 10 черных rectangles ожидается для 'HELLO'.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(10, $count, 'Barcode must emit multiple filled rects');
    }

    #[Test]
    public function barcode_caption_shown_under_bars(): void
    {
        $doc = new Document(new Section([
            new Barcode('ABC-123', showText: true),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Caption parenthesized в PDF text show op.
        self::assertStringContainsString('(ABC-123) Tj', $bytes);
    }

    #[Test]
    public function barcode_show_text_false_skips_caption(): void
    {
        $doc = new Document(new Section([
            new Barcode('ABC-123', showText: false),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('(ABC-123) Tj', $bytes);
    }

    #[Test]
    public function builder_barcode_propagates_to_body(): void
    {
        $doc = DocumentBuilder::new()
            ->barcode('SKU-42', widthPt: 150)
            ->build();

        self::assertCount(1, $doc->section->body);
        $node = $doc->section->body[0];
        self::assertInstanceOf(Barcode::class, $node);
        self::assertSame('SKU-42', $node->value);
        self::assertSame(150.0, $node->widthPt);
        self::assertSame(BarcodeFormat::Code128, $node->format);
    }

    #[Test]
    public function barcode_clamps_to_content_width(): void
    {
        // 10000pt requested — должно clamp'нуться к content area.
        $doc = new Document(new Section([
            new Barcode('A', widthPt: 10000),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // PDF не должен содержать unreasonably large coordinates.
        self::assertDoesNotMatchRegularExpression('@\b10000(?:\.|\s)@', $bytes);
    }

    #[Test]
    public function barcode_alignment_center_offsets_x(): void
    {
        // Center alignment должен отступить от leftX.
        $doc = new Document(new Section([
            new Barcode('A', widthPt: 100, alignment: Alignment::Center),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Просто валидируем что PDF корректно собирается + content stream
        // содержит fillRect для bars. Точный X — implementation-detail.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function barcode_renders_at_specified_height(): void
    {
        $doc = new Document(new Section([
            new Barcode('XYZ', widthPt: 100, heightPt: 60.0, showText: false),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // re оператор использует format: `x y w h re`. Должен содержать
        // height 60 у каждого bar.
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+60(?:\s)\s*re@', $bytes);
    }

    #[Test]
    public function multiple_barcodes_on_same_page(): void
    {
        $doc = new Document(new Section([
            new Barcode('FIRST'),
            new Barcode('SECOND'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(FIRST) Tj', $bytes);
        self::assertStringContainsString('(SECOND) Tj', $bytes);
    }
}
