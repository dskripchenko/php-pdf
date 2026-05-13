<?php

declare(strict_types=1);

/**
 * POC-R6.a: PNG + JPEG image embedding.
 *
 * Доказывает что мы можем embed'ить картинки в PDF:
 *  - PNG: parse IHDR + IDAT, unfilter scanlines, re-encode через Flate
 *  - JPEG: pass-through DCT-encoded bytes (Native PDF support)
 *  - Image XObject + Do operator для render с translation/scale
 *
 * Создаёт тестовые PNG и JPEG через GD, embed'ит обе в одну PDF страницу
 * рядом.
 *
 * Usage:
 *   php pocs/r6a/run.php > /tmp/images.pdf
 *   open /tmp/images.pdf
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

// 1. Создаём test PNG и JPEG в памяти.
if (! function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "PHP GD extension required for POC-R6.a\n");
    exit(1);
}

// PNG 100×50 — синий gradient.
$gd = imagecreatetruecolor(100, 50);
for ($x = 0; $x < 100; $x++) {
    $color = imagecolorallocate($gd, 0, 0, (int) (50 + $x * 2));
    imageline($gd, $x, 0, $x, 49, $color);
}
ob_start();
imagepng($gd);
$pngBytes = ob_get_clean();
imagedestroy($gd);

// JPEG 80×60 — красный квадрат с белым центром.
$gd = imagecreatetruecolor(80, 60);
$red = imagecolorallocate($gd, 200, 30, 30);
$white = imagecolorallocate($gd, 255, 255, 255);
imagefilledrectangle($gd, 0, 0, 79, 59, $red);
imagefilledrectangle($gd, 20, 15, 59, 44, $white);
ob_start();
imagejpeg($gd, null, 85);
$jpgBytes = ob_get_clean();
imagedestroy($gd);

// 2. Parse через PdfImage.
$pngImage = PdfImage::fromBytes($pngBytes);
$jpgImage = PdfImage::fromBytes($jpgBytes);

// 3. Build PDF.
$ttf = TtfFile::fromFile(
    __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf',
);
$font = new PdfFont($ttf);

// Подписи под картинками.
$pngLabel = $font->encodeText('PNG 100x50 gradient');
$jpgLabel = $font->encodeText('JPEG 80x60 red+white');

$writer = new Writer(version: '1.7');

$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageId = $writer->reserveObject();
$contentsId = $writer->reserveObject();

$fontId = $font->registerWith($writer);
$pngId = $pngImage->registerWith($writer);
$jpgId = $jpgImage->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");
$writer->setObject($pagesId, "<< /Type /Pages /Kids [$pageId 0 R] /Count 1 >>");

$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R '
    .'/Resources << /Font << /F1 %d 0 R >> /XObject << /Im1 %d 0 R /Im2 %d 0 R >> >> >>',
    $pagesId, $contentsId, $fontId, $pngId, $jpgId,
));

// Content stream: 2 картинки + 2 подписи.
// PNG в (72, 700), 200×100 pt. Текст ниже на (72, 680).
// JPEG в (310, 700), 160×120 pt. Текст ниже на (310, 680).
$cs = (new ContentStream)
    ->drawImage('Im1', 72, 700, 200, 100)
    ->textHexString('F1', 12, 72, 685, $pngLabel)
    ->drawImage('Im2', 310, 700, 160, 120)
    ->textHexString('F1', 12, 310, 685, $jpgLabel);
$body = $cs->toString();
$writer->setObject($contentsId, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($body), $body,
));

$writer->setRoot($catalogId);

echo $writer->toBytes();
