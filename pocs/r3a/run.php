<?php

declare(strict_types=1);

/**
 * POC-R3.a: text wrapping (500 chars, 2 fonts × 2 sizes).
 *
 * Доказывает что Layout\TextMeasurer + LineBreaker работают:
 *  - Точно измеряем ширину строк через PdfFont->widthOfCharPdfUnits()
 *  - Greedy line breaking даёт visually adequate wrapping
 *  - 4 варианта (Sans 10pt, Sans 14pt, Serif 10pt, Serif 14pt) рендерятся
 *    бок-о-бок для сравнения качества
 *
 * Качество vs mpdf: ожидаем 90%+ overlap. Без kerning/ligatures в v0.1
 * текст может быть на 1-3% шире (PdfFont измеряет advance widths без
 * kerning corrections).
 *
 * Usage:
 *   php pocs/r3a/run.php > /tmp/text-wrap.pdf
 *   open /tmp/text-wrap.pdf
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\LineBreaker;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

// 500+ символов lorem-ish текст. Включает Cyrillic для test обоих шрифтов.
$text = 'The quick brown fox jumps over the lazy dog. '
    .'Pack my box with five dozen liquor jugs. '
    .'How vexingly quick daft zebras jump! '
    .'Съешь же ещё этих мягких французских булок, да выпей чаю. '
    .'В чащах юга жил бы цитрус? Да, но фальшивый экземпляр! '
    .'Lorem ipsum dolor sit amet, consectetur adipiscing elit. '
    .'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. '
    .'Ut enim ad minim veniam, quis nostrud exercitation ullamco.';

$ttfDir = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5';
$sansTtf = TtfFile::fromFile($ttfDir.'/LiberationSans-Regular.ttf');
$serifTtf = TtfFile::fromFile($ttfDir.'/LiberationSerif-Regular.ttf');
$sansFont = new PdfFont($sansTtf);
$serifFont = new PdfFont($serifTtf);

// 4 column layout — A4 landscape для удобства. Каждая column = ~120pt.
// MediaBox для landscape A4: 842 × 595.
$columnWidth = 180;  // pt
$lineHeight = 1.4;   // multiplier of fontSize

$columns = [
    ['font' => $sansFont, 'name' => 'F1', 'sizePt' => 10, 'label' => 'Liberation Sans 10pt'],
    ['font' => $sansFont, 'name' => 'F1', 'sizePt' => 14, 'label' => 'Liberation Sans 14pt'],
    ['font' => $serifFont, 'name' => 'F2', 'sizePt' => 10, 'label' => 'Liberation Serif 10pt'],
    ['font' => $serifFont, 'name' => 'F2', 'sizePt' => 14, 'label' => 'Liberation Serif 14pt'],
];

// Pre-encode для каждой column'ы (wrap + encode).
$rendered = [];
foreach ($columns as $col) {
    $measurer = new TextMeasurer($col['font'], $col['sizePt']);
    $breaker = new LineBreaker($measurer, $columnWidth);
    $lines = $breaker->wrap($text);

    $encoded = [];
    foreach ($lines as $line) {
        $encoded[] = $col['font']->encodeText($line);
    }
    $rendered[] = [
        'col' => $col,
        'lines' => $encoded,
        'rawLines' => $lines,
    ];
}

$writer = new Writer(version: '1.7');
$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageId = $writer->reserveObject();
$contentsId = $writer->reserveObject();

$sansFontId = $sansFont->registerWith($writer);
$serifFontId = $serifFont->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");
$writer->setObject($pagesId, "<< /Type /Pages /Kids [$pageId 0 R] /Count 1 >>");
$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 842 595] '
    .'/Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
    $pagesId, $contentsId, $sansFontId, $serifFontId,
));

// Render content.
$cs = new ContentStream;
$startY = 540;        // top of page
$xLeft = 20;
$colGap = 25;

foreach ($rendered as $i => $info) {
    $colX = $xLeft + $i * ($columnWidth + $colGap);
    $col = $info['col'];

    // Label (bold-like — для POC просто меньший размер тех же шрифтов).
    $labelEncoded = $col['font']->encodeText($col['label']);
    $cs->textHexString($col['name'], 9, $colX, $startY, $labelEncoded);

    $y = $startY - 20;
    foreach ($info['lines'] as $j => $hexLine) {
        $cs->textHexString($col['name'], $col['sizePt'], $colX, $y, $hexLine);
        $y -= $col['sizePt'] * $lineHeight;
        if ($y < 30) {
            break;
        }
    }
}

$body = $cs->toString();
$writer->setObject($contentsId, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($body), $body,
));

$writer->setRoot($catalogId);
echo $writer->toBytes();
