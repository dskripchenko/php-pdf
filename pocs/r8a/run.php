<?php

declare(strict_types=1);

/**
 * POC-R8.a: hyperlinks (external URI + internal anchor).
 *
 * Эмитит 2-страничный PDF где на странице 1 есть:
 *  - External link "Visit example.com" → URI Action
 *  - Internal link "Jump to page 2" → Direct destination на page 2
 * А на странице 2:
 *  - Хайлайт-pointed-on-text «Hello from page 2»
 *  - Bookmark-target отображается через destination
 *
 * Annotation dict для links (ISO 32000-1 §12.5.4 для Link annotation):
 *   << /Type /Annot /Subtype /Link
 *      /Rect [llx lly urx ury]    ← clickable bounding box в pt
 *      /Border [0 0 0]            ← без visible underline
 *      /A << /Type /Action /S /URI /URI (http://...) >>     ← external
 *      /Dest [<page-ref> /XYZ <x> <y> <zoom>]               ← internal
 *   >>
 *
 * Page links активируются через `/Annots [<anno-ids>]` в Page dict.
 *
 * Usage:
 *   php pocs/r8a/run.php > /tmp/links.pdf
 *   open /tmp/links.pdf   # cmd+click чтобы тест linkability
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

$ttf = TtfFile::fromFile(
    __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf',
);
$font = new PdfFont($ttf);

// Encode 3 строки.
$externalLinkText = $font->encodeText('Visit example.com');
$internalLinkText = $font->encodeText('Jump to page 2');
$page2Text = $font->encodeText('Hello from page 2');

$writer = new Writer(version: '1.7');

$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$page1Id = $writer->reserveObject();
$page2Id = $writer->reserveObject();
$content1Id = $writer->reserveObject();
$content2Id = $writer->reserveObject();
// 2 annotations на page 1.
$externalLinkAnnoId = $writer->reserveObject();
$internalLinkAnnoId = $writer->reserveObject();

$fontId = $font->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");

$writer->setObject($pagesId, sprintf(
    '<< /Type /Pages /Kids [%d 0 R %d 0 R] /Count 2 >>',
    $page1Id, $page2Id,
));

// Page 1 с /Annots на 2 link'а.
$writer->setObject($page1Id, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R '
    .'/Resources << /Font << /F1 %d 0 R >> >> '
    .'/Annots [%d 0 R %d 0 R] >>',
    $pagesId, $content1Id, $fontId, $externalLinkAnnoId, $internalLinkAnnoId,
));

$writer->setObject($page2Id, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R '
    .'/Resources << /Font << /F1 %d 0 R >> >> >>',
    $pagesId, $content2Id, $fontId,
));

// External link annotation. Rect [llx lly urx ury] — clickable area.
// На странице 1 текст «Visit example.com» рендерим на Y=750. Высота
// текста ~24pt, ширина ~170pt. Rect от (72, 740) до (242, 770).
$writer->setObject($externalLinkAnnoId,
    '<< /Type /Annot /Subtype /Link '
    .'/Rect [72 740 242 770] /Border [0 0 0] '
    .'/A << /Type /Action /S /URI /URI (https://example.com) >> >>',
);

// Internal link → /Dest [page2 /XYZ 0 800 null]
//   /XYZ x y zoom — top-of-window position, null = retain current zoom.
$writer->setObject($internalLinkAnnoId, sprintf(
    '<< /Type /Annot /Subtype /Link '
    .'/Rect [72 700 222 730] /Border [0 0 0] '
    .'/Dest [%d 0 R /XYZ 0 800 null] >>',
    $page2Id,
));

// Content stream page 1.
$cs1 = (new ContentStream)
    ->textHexString('F1', 18, 72, 750, $externalLinkText)
    ->textHexString('F1', 18, 72, 710, $internalLinkText);
$body1 = $cs1->toString();
$writer->setObject($content1Id, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($body1), $body1,
));

// Content stream page 2.
$cs2 = (new ContentStream)
    ->textHexString('F1', 24, 72, 770, $page2Text);
$body2 = $cs2->toString();
$writer->setObject($content2Id, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($body2), $body2,
));

$writer->setRoot($catalogId);

echo $writer->toBytes();
