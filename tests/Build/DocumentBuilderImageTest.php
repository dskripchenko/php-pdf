<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderImageTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    private string $pngPath = __DIR__.'/../fixtures/sample.png';

    #[Test]
    public function image_from_path_added_as_block(): void
    {
        $doc = DocumentBuilder::new()
            ->image($this->jpegPath)
            ->build();

        self::assertCount(1, $doc->section->body);
        $img = $doc->section->body[0];
        self::assertInstanceOf(Image::class, $img);
        self::assertSame(40, $img->source->widthPx);
    }

    #[Test]
    public function image_with_width_and_alignment(): void
    {
        $doc = DocumentBuilder::new()
            ->image($this->pngPath, widthPt: 120, alignment: Alignment::Center)
            ->build();

        $img = $doc->section->body[0];
        self::assertSame(120.0, $img->widthPt);
        self::assertSame(Alignment::Center, $img->alignment);
    }

    #[Test]
    public function image_accepts_pdf_image_instance(): void
    {
        $pdfImg = PdfImage::fromPath($this->jpegPath);
        $doc = DocumentBuilder::new()
            ->image($pdfImg, widthPt: 80)
            ->build();

        $img = $doc->section->body[0];
        self::assertSame($pdfImg, $img->source);
    }

    #[Test]
    public function image_accepts_existing_ast_node(): void
    {
        $existing = Image::fromPath($this->jpegPath, widthPt: 50);
        $doc = DocumentBuilder::new()->image($existing)->build();
        self::assertSame($existing, $doc->section->body[0]);
    }

    #[Test]
    public function full_image_smoke_renders_valid_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Image Demo')
            ->image($this->jpegPath, widthPt: 200, alignment: Alignment::Center)
            ->paragraph('Caption под картинкой.')
            ->image($this->pngPath, widthPt: 80, spaceBeforePt: 12)
            ->toBytes();

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('/Filter /DCTDecode', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }
}
