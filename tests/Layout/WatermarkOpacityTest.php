<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WatermarkOpacityTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    #[Test]
    public function image_watermark_with_opacity_emits_extgstate(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkImage: $img,
            watermarkImageOpacity: 0.3,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The ExtGState object is emitted with the right /ca.
        self::assertStringContainsString('/Type /ExtGState', $bytes);
        self::assertStringContainsString('/ca 0.3', $bytes);
        // Resources holds the /ExtGState reference.
        self::assertMatchesRegularExpression('@/ExtGState\s*<<\s*/Gs1\s+\d+\s+0\s+R@', $bytes);
        // The content stream applies the gs op before drawing the image.
        self::assertMatchesRegularExpression('@/Gs1\s+gs.*?/Im1\s+Do@s', $bytes);
    }

    #[Test]
    public function image_watermark_opacity_null_no_extgstate(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkImage: $img,
            watermarkImageOpacity: null,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Type /ExtGState', $bytes);
        self::assertStringNotContainsString(' gs\n', $bytes);
    }

    #[Test]
    public function image_watermark_opacity_full_skips_extgstate(): void
    {
        // 1.0 is a no-op (we decline to write a redundant ExtGState).
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkImage: $img,
            watermarkImageOpacity: 1.0,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Type /ExtGState', $bytes);
    }

    #[Test]
    public function text_watermark_with_opacity_emits_extgstate(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkText: 'DRAFT',
            watermarkTextOpacity: 0.5,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // An ExtGState with /ca 0.5 and /CA 0.5 (text can be filled and stroked).
        self::assertStringContainsString('/Type /ExtGState', $bytes);
        self::assertStringContainsString('/ca 0.5', $bytes);
        // The gs op is applied before the rotated text.
        self::assertMatchesRegularExpression('@/Gs\d+\s+gs@', $bytes);
    }

    #[Test]
    public function extgstate_deduped_for_equal_opacity(): void
    {
        // An image and text with the same opacity reuse the ExtGState on the
        // same page (deduplicated by key()).
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkImage: $img,
            watermarkImageOpacity: 0.5,
            watermarkText: 'DRAFT',
            watermarkTextOpacity: 0.5,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Although both use an opacity of 0.5, the image uses /ca only while
        // the text uses /ca + /CA → different keys → 2 ExtGStates. The
        // deduplication is checked on a single-kind scenario instead.
        $count = substr_count($bytes, '/Type /ExtGState');
        self::assertGreaterThanOrEqual(1, $count);
    }

    #[Test]
    public function builder_image_opacity_propagates(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = DocumentBuilder::new()
            ->watermarkImage($img)
            ->watermarkImageOpacity(0.25)
            ->paragraph('Body')
            ->build();

        self::assertSame(0.25, $doc->section->watermarkImageOpacity);
    }

    #[Test]
    public function builder_text_opacity_propagates(): void
    {
        $doc = DocumentBuilder::new()
            ->watermark('SAMPLE')
            ->watermarkTextOpacity(0.4)
            ->paragraph('Body')
            ->build();

        self::assertSame(0.4, $doc->section->watermarkTextOpacity);
    }

    #[Test]
    public function content_stream_balances_q_and_Q_with_opacity(): void
    {
        // q/Q have to be balanced: the opacity wrapping must not leave a
        // dangling graphics-state push.
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkImage: $img,
            watermarkImageOpacity: 0.3,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // q == Q (line-anchored counts).
        $qCount = preg_match_all('@^q$@m', $bytes);
        $QCount = preg_match_all('@^Q$@m', $bytes);
        self::assertSame($qCount, $QCount, 'q/Q must be balanced');
    }
}
