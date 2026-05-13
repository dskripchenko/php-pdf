<?php

declare(strict_types=1);

/**
 * POC-R2.b: Cyrillic subset через embedded Liberation Sans.
 *
 * Делает то же что R2.a, но рендерит русский текст. Подтверждает:
 *  - cmap mapping для Cyrillic Unicode (U+0410..U+044F) корректен
 *  - ToUnicode CMap делает копи-пейст правильным (а не glyph-ID hex)
 *  - PDF reader корректно рендерит non-Latin charset с Identity-H
 *
 * Usage:
 *   php pocs/r2b/run.php > /tmp/hello-cyrillic.pdf
 *   open /tmp/hello-cyrillic.pdf
 *   # Скопировать текст из reader'а — должно быть "Привет, мир!"
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

$ttfPath = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
$ttf = TtfFile::fromFile($ttfPath);
$font = new PdfFont($ttf);

$cyrillic = $font->encodeText('Привет, мир!');
$latin = $font->encodeText('Hello, world!');
$mixed = $font->encodeText('Mixed: Привет / Hello — 1 + 1 = 2.');

$cs = (new ContentStream)
    ->textHexString('F1', 24, 72, 760, $cyrillic)
    ->textHexString('F1', 24, 72, 720, $latin)
    ->textHexString('F1', 16, 72, 680, $mixed);
$contentBody = $cs->toString();

$writer = new Writer(version: '1.7');
$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageId = $writer->reserveObject();
$contentsId = $writer->reserveObject();
$fontId = $font->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");
$writer->setObject($pagesId, "<< /Type /Pages /Kids [$pageId 0 R] /Count 1 >>");
$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
    $pagesId, $contentsId, $fontId,
));
$writer->setObject($contentsId, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($contentBody), $contentBody,
));
$writer->setRoot($catalogId);

echo $writer->toBytes();
