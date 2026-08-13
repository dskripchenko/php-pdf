<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Image;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The transparency of an image makes it into the PDF.
 *
 * PDF cannot carry transparency inside an image: it is given by a separate mask
 * object through `/SMask`. The alpha channel used to be thrown away, and
 * transparent pixels printed in whatever colour lay beneath them — a signature
 * and a stamp from an imported document came out on a black rectangle.
 */
final class AlphaMaskTest extends TestCase
{
    /** A 2×2 PNG: two opaque pixels and two fully transparent ones. */
    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 0));
        imagesetpixel($image, 1, 1, imagecolorallocatealpha($image, 0, 0, 255, 0));

        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    #[Test]
    public function alpha_channel_becomes_a_soft_mask(): void
    {
        $png = PdfImage::fromBytes($this->transparentPng());

        self::assertNotNull($png->alphaData, 'альфа-канал потерян при разборе PNG');
    }

    #[Test]
    public function the_document_carries_the_mask_object(): void
    {
        $document = new Document(new Section([
            new Image(PdfImage::fromBytes($this->transparentPng()), widthPt: 20, heightPt: 20),
        ]));

        $bytes = $document->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/SMask', $bytes);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
    }

    #[Test]
    public function opaque_image_gets_no_mask(): void
    {
        // An extra object in the file buys nothing: an opaque image needs no
        // mask.
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagepng($image);
        $opaque = (string) ob_get_clean();

        self::assertNull(PdfImage::fromBytes($opaque)->alphaData);
    }
}
