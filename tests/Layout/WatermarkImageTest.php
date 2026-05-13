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

final class WatermarkImageTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    private string $pngPath = __DIR__.'/../fixtures/sample.png';

    #[Test]
    public function image_watermark_renders_xobject_on_page(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body content')])],
            watermarkImage: $img,
            watermarkImageWidthPt: 200,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Image XObject должен быть зарегистрирован.
        self::assertStringContainsString('/Subtype /Image', $bytes);
        // Do operator вызывается → image отрисован.
        self::assertMatchesRegularExpression('@/Im\d+\s+Do@', $bytes);
    }

    #[Test]
    public function image_watermark_repeats_on_every_page(): void
    {
        $img = PdfImage::fromPath($this->pngPath);
        $body = [];
        // 3 page breaks → 4 страницы.
        for ($i = 0; $i < 4; $i++) {
            $body[] = new Paragraph([new Run("Page $i")]);
            if ($i < 3) {
                $body[] = new \Dskripchenko\PhpPdf\Element\PageBreak;
            }
        }
        $doc = new Document(new Section(
            body: $body,
            watermarkImage: $img,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Один Image XObject (dedup); используется на каждой странице.
        self::assertSame(1, substr_count($bytes, '/Subtype /Image'));
        // Do operators ≥ 4 (по одному на каждую page).
        $doCount = preg_match_all('@/Im\d+\s+Do@', $bytes);
        self::assertGreaterThanOrEqual(4, $doCount);
    }

    #[Test]
    public function no_image_watermark_no_xobject(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body content')])],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Subtype /Image', $bytes);
    }

    #[Test]
    public function text_and_image_watermark_coexist(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body content')])],
            watermarkText: 'DRAFT',
            watermarkImage: $img,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Image XObject + rotated text matrix (text watermark) — оба
        // отрисованы на странице.
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('(DRAFT)', $bytes);
    }

    #[Test]
    public function builder_watermark_image_propagates_to_section(): void
    {
        $img = PdfImage::fromPath($this->pngPath);
        $doc = DocumentBuilder::new()
            ->watermarkImage($img, 300)
            ->paragraph('Body')
            ->build();

        self::assertSame($img, $doc->section->watermarkImage);
        self::assertSame(300.0, $doc->section->watermarkImageWidthPt);
        self::assertTrue($doc->section->hasImageWatermark());
        self::assertTrue($doc->section->hasWatermark());
    }

    #[Test]
    public function builder_watermark_image_null_disables(): void
    {
        $doc = DocumentBuilder::new()
            ->watermarkImage(null)
            ->paragraph('Body')
            ->build();

        self::assertNull($doc->section->watermarkImage);
        self::assertFalse($doc->section->hasImageWatermark());
        self::assertFalse($doc->section->hasWatermark());
    }

    #[Test]
    public function image_centered_using_aspect_ratio(): void
    {
        // Synthetic image 200x100 (2:1 aspect). watermarkImageWidthPt=200
        // → height should be 100.
        $img = PdfImage::fromPath($this->jpegPath);
        $aspect = $img->widthPx / $img->heightPx;
        $w = 200.0;
        $h = $w / $aspect;

        $doc = new Document(new Section(
            body: [new Paragraph([new Run('x')])],
            watermarkImage: $img,
            watermarkImageWidthPt: $w,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // PDF image draw operator: `<w> 0 0 <h> <x> <y> cm`. ContentStream
        // удаляет trailing .0 для integers → используем integer-aware regex.
        // h=150 для 40x30 image при w=200.
        self::assertEqualsWithDelta(150.0, $h, 0.01);
        self::assertMatchesRegularExpression(
            '@\b200\s+0\s+0\s+150(?:\.|\s)@',
            $bytes,
            'Image scale matrix must preserve aspect ratio (200 0 0 150)',
        );
    }
}
