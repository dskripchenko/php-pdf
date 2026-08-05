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
 * Прозрачность картинки доезжает до PDF.
 *
 * PDF не умеет прозрачность внутри изображения: она задаётся отдельным
 * объектом-маской через `/SMask`. Раньше альфа-канал выбрасывался, и
 * прозрачные точки печатались тем цветом, что лежал под ними — подпись и
 * печать из импортированного документа выходили на чёрном прямоугольнике.
 */
final class AlphaMaskTest extends TestCase
{
    /** PNG 2×2: два пикселя непрозрачных, два полностью прозрачных. */
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
        // Лишний объект в файле ничего не даёт: непрозрачной картинке маска
        // не нужна.
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagepng($image);
        $opaque = (string) ob_get_clean();

        self::assertNull(PdfImage::fromBytes($opaque)->alphaData);
    }
}
