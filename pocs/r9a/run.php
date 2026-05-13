<?php

declare(strict_types=1);

/**
 * POC-R9.a: emit minimal Hello World PDF.
 *
 *  - A4 portrait (595.28 × 841.89 pt)
 *  - Single page
 *  - Text "Hello, world!" в Times-Roman 24pt
 *  - PDF base-14 font reference (без embedding)
 *
 * Usage:
 *   php pocs/r9a/run.php > /tmp/hello.pdf
 *   open /tmp/hello.pdf
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\Writer;

$writer = new Writer(version: '1.7');

// 1. Сначала резервируем ID — родительские объекты ссылаются на дочерние.
$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageId = $writer->reserveObject();
$contentsId = $writer->reserveObject();
$fontId = $writer->reserveObject();

// 2. Content stream: BT/Tf/Td/Tj/ET.
//    A4 = 595.28 × 841.89 pt. Y=750 — около верха страницы.
$cs = (new ContentStream)
    ->text('F1', 24, 72, 750, 'Hello, world!');
$contentStreamBody = $cs->toString();

// 3. Заполняем объекты в правильном порядке (любой порядок OK; xref всё
//    равно сортирует по ID).
$writer->setObject($catalogId, sprintf(
    '<< /Type /Catalog /Pages %d 0 R >>',
    $pagesId,
));

$writer->setObject($pagesId, sprintf(
    '<< /Type /Pages /Kids [%d 0 R] /Count 1 >>',
    $pageId,
));

// A4 MediaBox: 0 0 595.28 841.89 (rounded to integer pt).
$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
    $pagesId,
    $contentsId,
    $fontId,
));

// Content stream object — body inside `stream ... endstream`.
$contentStreamObj = sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($contentStreamBody),
    $contentStreamBody,
);
$writer->setObject($contentsId, $contentStreamObj);

// Base-14 Times-Roman — без embedding. Acrobat/etc используют installed
// metrics-only resource.
$writer->setObject($fontId,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
);

$writer->setRoot($catalogId);

echo $writer->toBytes();
