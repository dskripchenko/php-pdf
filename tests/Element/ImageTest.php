<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Element;

use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    private string $pngPath = __DIR__.'/../fixtures/sample.png';

    #[Test]
    public function from_path_parses_jpeg(): void
    {
        $img = Image::fromPath($this->jpegPath);
        self::assertSame(40, $img->source->widthPx);
        self::assertSame(30, $img->source->heightPx);
        self::assertSame('/DCTDecode', $img->source->filter);
    }

    #[Test]
    public function from_path_parses_png(): void
    {
        $img = Image::fromPath($this->pngPath);
        self::assertSame(40, $img->source->widthPx);
        self::assertSame(30, $img->source->heightPx);
        self::assertSame('/FlateDecode', $img->source->filter);
    }

    #[Test]
    public function from_bytes_parses_image(): void
    {
        $bytes = (string) file_get_contents($this->jpegPath);
        $img = Image::fromBytes($bytes);
        self::assertSame(40, $img->source->widthPx);
        self::assertSame(30, $img->source->heightPx);
    }

    #[Test]
    public function default_size_uses_native_pixels_as_points(): void
    {
        $img = Image::fromPath($this->jpegPath);
        [$w, $h] = $img->effectiveSizePt();
        self::assertSame(40.0, $w);
        self::assertSame(30.0, $h);
    }

    #[Test]
    public function explicit_width_preserves_aspect_ratio(): void
    {
        $img = Image::fromPath($this->jpegPath, widthPt: 120);
        [$w, $h] = $img->effectiveSizePt();
        self::assertSame(120.0, $w);
        // 30/40 × 120 = 90.
        self::assertEqualsWithDelta(90.0, $h, 0.0001);
    }

    #[Test]
    public function explicit_height_preserves_aspect_ratio(): void
    {
        $img = Image::fromPath($this->jpegPath, heightPt: 60);
        [$w, $h] = $img->effectiveSizePt();
        self::assertEqualsWithDelta(80.0, $w, 0.0001);
        self::assertSame(60.0, $h);
    }

    #[Test]
    public function explicit_both_dimensions_overrides_aspect_ratio(): void
    {
        $img = Image::fromPath($this->jpegPath, widthPt: 100, heightPt: 200);
        [$w, $h] = $img->effectiveSizePt();
        self::assertSame(100.0, $w);
        self::assertSame(200.0, $h);
    }

    #[Test]
    public function alignment_stored_on_element(): void
    {
        $img = Image::fromPath($this->jpegPath, alignment: Alignment::Center);
        self::assertSame(Alignment::Center, $img->alignment);
    }

    #[Test]
    public function spacing_fields_default_to_zero(): void
    {
        $img = Image::fromPath($this->jpegPath);
        self::assertSame(0.0, $img->spaceBeforePt);
        self::assertSame(0.0, $img->spaceAfterPt);
    }

    #[Test]
    public function spacing_fields_propagate(): void
    {
        $img = Image::fromPath($this->jpegPath, spaceBeforePt: 12, spaceAfterPt: 6);
        self::assertSame(12.0, $img->spaceBeforePt);
        self::assertSame(6.0, $img->spaceAfterPt);
    }

    #[Test]
    public function constructor_with_pdf_image_directly(): void
    {
        $pdfImg = PdfImage::fromPath($this->pngPath);
        $img = new Image($pdfImg, widthPt: 50);
        self::assertSame($pdfImg, $img->source);
        self::assertSame(50.0, $img->widthPt);
    }
}
