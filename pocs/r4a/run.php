<?php

declare(strict_types=1);

/**
 * POC-R4.a: 3-column header table layout (polis-vzr-semya style).
 *
 * Самый сложный POC Phase R0 после fonts. Доказывает что мы можем
 * собрать table layout с:
 *  - Explicit column widths (фиксированная сетка через colgroup-style)
 *  - Cell padding + borders + background color
 *  - Text wrapping внутри cell (через LineBreaker)
 *  - Row height вычисляется как max of cell heights
 *  - Многострочный контент в ячейках
 *
 * Структура: полисный header с 3 column'ами:
 *   ┌────────────┬───────────────────────┬───────────────┐
 *   │ Acme       │ ПОЛИС СТРАХОВАНИЯ     │ № 12345       │
 *   │ Insurance  │ ВЗР × СЕМЬЯ           │ Дата: 13.05.26│
 *   └────────────┴───────────────────────┴───────────────┘
 *
 * Layout алгоритм для POC:
 *  1. Дано: column widths [w1, w2, w3], cell contents (UTF-8 strings)
 *  2. Для каждой cell:
 *      - LineBreak текст внутри (widthPt − 2*paddingPt)
 *      - Высота cell = numLines * lineHeight + 2*paddingPt
 *  3. Row height = max of cell heights
 *  4. Render:
 *      - Сначала fill rectangles backgrounds
 *      - Потом stroke rectangles borders
 *      - Потом text внутри каждой cell
 *
 * Usage:
 *   php pocs/r4a/run.php > /tmp/polis-header.pdf
 *   open /tmp/polis-header.pdf
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\LineBreaker;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

$ttf = TtfFile::fromFile(
    __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf',
);
$font = new PdfFont($ttf);

// Table definition.
$tableX = 72;        // pt от left
$tableY = 770;       // pt от bottom для top edge (Y growing up в PDF)
$columnWidths = [140, 280, 130];   // total: 550pt — fits A4 portrait minus margins
$paddingPt = 8;
$lineHeightMult = 1.4;
$fontSizePt = 11;
$titleSizePt = 13;   // для крупного среднего column'а

// Контент cell'ов.
$cells = [
    [
        'lines' => ['Acme Insurance', 'Group Ltd.'],
        'sizePt' => $fontSizePt,
    ],
    [
        'lines' => ['ПОЛИС СТРАХОВАНИЯ', 'ВЗР × СЕМЬЯ'],
        'sizePt' => $titleSizePt,
    ],
    [
        'lines' => ['№ 12345', 'Дата: 13.05.2026', 'Срок: 12 мес'],
        'sizePt' => $fontSizePt,
    ],
];

// 1. Wrap каждой cell + вычисляем height.
$cellRendered = [];
$maxCellHeight = 0;
foreach ($cells as $i => $cell) {
    $sizePt = $cell['sizePt'];
    $measurer = new TextMeasurer($font, $sizePt);
    $innerWidth = $columnWidths[$i] - 2 * $paddingPt;
    $breaker = new LineBreaker($measurer, $innerWidth);

    $allLines = [];
    foreach ($cell['lines'] as $sourceLine) {
        // LineBreaker.wrap handles paragraph-level break; иногда исходная
        // line сама по себе wrap'ится.
        $wrapped = $breaker->wrap($sourceLine);
        foreach ($wrapped as $w) {
            $allLines[] = $w;
        }
    }

    $lineHeight = $sizePt * $lineHeightMult;
    $cellHeight = count($allLines) * $lineHeight + 2 * $paddingPt;
    $maxCellHeight = max($maxCellHeight, $cellHeight);

    $cellRendered[] = [
        'lines' => $allLines,
        'sizePt' => $sizePt,
        'lineHeight' => $lineHeight,
    ];
}

$rowHeight = $maxCellHeight;

// 2. Render content stream.
$cs = new ContentStream;

// 2a. Backgrounds (заливка светло-серым).
$colX = $tableX;
foreach ($columnWidths as $i => $w) {
    $cs->fillRectangle(
        $colX, $tableY - $rowHeight, $w, $rowHeight,
        0.95, 0.95, 0.95,  // light gray
    );
    $colX += $w;
}

// 2b. Borders (0.5pt black по каждому периметру cell).
$colX = $tableX;
foreach ($columnWidths as $w) {
    $cs->strokeRectangle($colX, $tableY - $rowHeight, $w, $rowHeight, 0.5);
    $colX += $w;
}

// 2c. Text внутри cells.
$colX = $tableX;
foreach ($cellRendered as $i => $info) {
    $textX = $colX + $paddingPt;
    $textTopY = $tableY - $paddingPt - $info['sizePt']; // baseline первой line

    foreach ($info['lines'] as $j => $line) {
        $encoded = $font->encodeText($line);
        $y = $textTopY - $j * $info['lineHeight'];
        $cs->textHexString('F1', $info['sizePt'], $textX, $y, $encoded);
    }
    $colX += $columnWidths[$i];
}

// 3. Build PDF.
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

$body = $cs->toString();
$writer->setObject($contentsId, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($body), $body,
));

$writer->setRoot($catalogId);
echo $writer->toBytes();
