<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageDedupTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    #[Test]
    public function same_pdfimage_instance_dedupes(): void
    {
        $img = PdfImage::fromPath($this->jpegPath);
        $doc = new AstDocument(new Section([
            new Image($img, widthPt: 50),
            new Image($img, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Single Image XObject (existing dedup by instance).
        self::assertSame(1, substr_count($bytes, '/Subtype /Image'));
    }

    #[Test]
    public function different_instances_same_content_dedup(): void
    {
        // Phase 29: load same file twice → 2 instances но 1 XObject.
        $img1 = PdfImage::fromPath($this->jpegPath);
        $img2 = PdfImage::fromPath($this->jpegPath);
        self::assertNotSame($img1, $img2);

        $doc = new AstDocument(new Section([
            new Image($img1, widthPt: 50),
            new Image($img2, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Content-based dedup → only 1 Image XObject.
        self::assertSame(1, substr_count($bytes, '/Subtype /Image'));
    }

    #[Test]
    public function different_images_not_deduped(): void
    {
        $img1 = PdfImage::fromPath(__DIR__.'/../fixtures/sample.jpg');
        $img2 = PdfImage::fromPath(__DIR__.'/../fixtures/sample.png');

        $doc = new AstDocument(new Section([
            new Image($img1, widthPt: 50),
            new Image($img2, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // 2 different images → 2 distinct XObjects.
        self::assertSame(2, substr_count($bytes, '/Subtype /Image'));
    }
}
