<?php

declare(strict_types=1);

/**
 * POC-R5.a: forced + soft page breaks.
 *
 * Эмитит 3-страничный PDF чтобы доказать что multi-page mechanics
 * работают:
 *  - Pages tree с /Kids array из 3 элементов + /Count 3
 *  - Все 3 pages ссылаются на тот же resource font dict (test shared
 *    resources)
 *  - Каждая page имеет уникальный content stream
 *
 * Forced vs soft page break — на уровне PDF format это identical:
 * новая Page object с своим content stream. Различие — в Layout Engine
 * (Phase 3), который решает когда вставлять break:
 *   - forced: явный `<page-break/>` или `<hr class="page-break">`
 *   - soft: content overflows page MediaBox → auto-break
 *
 * Здесь POC доказывает что format-level multi-page работает.
 *
 * Usage:
 *   php pocs/r5a/run.php > /tmp/multipage.pdf
 *   pdftotext /tmp/multipage.pdf -
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

// Готовим encoded strings для 3 страниц.
$pageTexts = [
    'Page 1 of 3 — forced break next',
    'Page 2 of 3 — soft (overflow) break next',
    'Page 3 of 3 — last page',
];
$encoded = [];
foreach ($pageTexts as $t) {
    $encoded[] = $font->encodeText($t);
}

$writer = new Writer(version: '1.7');

// Резервируем верхушку дерева + 3 страницы + 3 content stream'а.
$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageIds = [$writer->reserveObject(), $writer->reserveObject(), $writer->reserveObject()];
$contentIds = [$writer->reserveObject(), $writer->reserveObject(), $writer->reserveObject()];

$fontId = $font->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");

// Pages tree с 3 kids.
$kidsRefs = implode(' ', array_map(fn ($id) => "$id 0 R", $pageIds));
$writer->setObject($pagesId, sprintf(
    '<< /Type /Pages /Kids [%s] /Count %d >>',
    $kidsRefs, count($pageIds),
));

// 3 Page objects + content streams.
foreach ($pageIds as $i => $pageId) {
    $writer->setObject($pageId, sprintf(
        '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
        .'/Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
        $pagesId, $contentIds[$i], $fontId,
    ));

    $cs = (new ContentStream)
        ->textHexString('F1', 18, 72, 750, $encoded[$i]);
    $body = $cs->toString();
    $writer->setObject($contentIds[$i], sprintf(
        "<< /Length %d >>\nstream\n%sendstream",
        strlen($body), $body,
    ));
}

$writer->setRoot($catalogId);

echo $writer->toBytes();
