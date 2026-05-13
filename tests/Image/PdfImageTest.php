<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Image;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfImageTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available.');
        }
    }

    #[Test]
    public function detects_jpeg_from_bytes(): void
    {
        $gd = imagecreatetruecolor(10, 5);
        ob_start();
        imagejpeg($gd);
        $jpg = ob_get_clean();
        imagedestroy($gd);

        $img = PdfImage::fromBytes($jpg);
        self::assertSame(10, $img->widthPx);
        self::assertSame(5, $img->heightPx);
        self::assertSame('/DCTDecode', $img->filter);
        self::assertSame('/DeviceRGB', $img->colorSpace);
        self::assertSame(8, $img->bitsPerComponent);
        // Для JPEG — pass-through, imageData = whole JPEG bytes.
        self::assertSame($jpg, $img->imageData);
    }

    #[Test]
    public function detects_png_rgb_from_bytes(): void
    {
        $gd = imagecreatetruecolor(8, 4);
        $color = imagecolorallocate($gd, 255, 0, 0);
        imagefilledrectangle($gd, 0, 0, 7, 3, $color);
        ob_start();
        imagepng($gd);
        $png = ob_get_clean();
        imagedestroy($gd);

        $img = PdfImage::fromBytes($png);
        self::assertSame(8, $img->widthPx);
        self::assertSame(4, $img->heightPx);
        self::assertSame('/FlateDecode', $img->filter);
        self::assertSame('/DeviceRGB', $img->colorSpace);
        // PNG re-encoded через Flate, размер ≠ original.
        self::assertNotSame($png, $img->imageData);
    }

    #[Test]
    public function rejects_non_image_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PdfImage::fromBytes('not an image at all');
    }

    #[Test]
    public function register_creates_image_xobject(): void
    {
        $gd = imagecreatetruecolor(20, 10);
        ob_start();
        imagejpeg($gd);
        $jpg = ob_get_clean();
        imagedestroy($gd);

        $writer = new Writer;
        $img = PdfImage::fromBytes($jpg);
        $id = $img->registerWith($writer);
        $writer->setRoot($writer->addObject('<< /Type /Catalog /Pages 1 0 R >>'));
        $pdf = $writer->toBytes();

        self::assertStringContainsString('/Type /XObject', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('/Width 20', $pdf);
        self::assertStringContainsString('/Height 10', $pdf);
        self::assertStringContainsString('/Filter /DCTDecode', $pdf);
    }

    #[Test]
    public function double_register_returns_same_id(): void
    {
        $gd = imagecreatetruecolor(5, 5);
        ob_start();
        imagejpeg($gd);
        $jpg = ob_get_clean();
        imagedestroy($gd);

        $writer = new Writer;
        $img = PdfImage::fromBytes($jpg);
        $id1 = $img->registerWith($writer);
        $id2 = $img->registerWith($writer);
        self::assertSame($id1, $id2);
    }
}
