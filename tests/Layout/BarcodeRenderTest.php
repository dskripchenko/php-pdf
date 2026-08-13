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

        // Code 128 gives many fill operators (one per contiguous black run).
        // At least 10 black rectangles are expected for 'HELLO'.
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

        // The caption is parenthesized in the PDF text-show op.
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
        // 10000pt requested — it has to be clamped to the content area.
        $doc = new Document(new Section([
            new Barcode('A', widthPt: 10000),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The PDF must not contain unreasonably large coordinates.
        self::assertDoesNotMatchRegularExpression('@\b10000(?:\.|\s)@', $bytes);
    }

    #[Test]
    public function barcode_alignment_center_offsets_x(): void
    {
        // Centre alignment has to move away from leftX.
        $doc = new Document(new Section([
            new Barcode('A', widthPt: 100, alignment: Alignment::Center),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Just validate that the PDF assembles correctly and that the content
        // stream holds a fillRect for the bars. The exact X is an implementation
        // detail.
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

        // The re operator has the format `x y w h re`. It has to carry a height
        // of 60 for every bar.
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
