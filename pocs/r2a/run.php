<?php

declare(strict_types=1);

/**
 * POC-R2.a: Liberation Sans subset embedding (5 glyphs).
 *
 * Самый рисковый POC Phase R0 — TTF font embedding в PDF. Если работает —
 * можно гарантировать что Phase 2 реализуема.
 *
 * Что POC доказывает:
 *  - Парсинг TTF binary structure (header, table directory, cmap, hmtx, ...)
 *  - Построение Type0 composite font dict + CIDFontType2 descendant
 *  - Embedding TTF bytes как FontFile2 stream
 *  - ToUnicode CMap для copy-paste correctness
 *  - Identity-H encoding: 2-byte hex glyph-ID strings в content stream
 *  - Real-world рендеринг в PDF reader'е (визуальная проверка автором)
 *
 * Что НЕ покрыто (Phase 2):
 *  - Subset font'а (мы embed'им весь TTF — 411 KB на одну hello.pdf)
 *  - Kerning / ligatures
 *  - Multiple fonts в одном документе
 *  - Width array optimization
 *
 * Usage:
 *   php pocs/r2a/run.php > /tmp/hello-liberation.pdf
 *   open /tmp/hello-liberation.pdf
 *
 * Path TTF берётся из .cache/fonts/. Скачивается curl'ом из Liberation
 * 2.1.5 release tarball (см. project history).
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\ContentStream;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;

$ttfPath = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
if (! is_readable($ttfPath)) {
    fwrite(STDERR, "Liberation Sans not found at $ttfPath\n");
    fwrite(STDERR, "Download Liberation 2.1.5 first (see PLAN.md).\n");
    exit(1);
}

$ttf = TtfFile::fromFile($ttfPath);
$font = new PdfFont($ttf);

// Encode text — это accumulates usedGlyphs в font'е, которые потом
// embed'ятся в /W array + ToUnicode CMap.
$helloHex = $font->encodeText('Hello, world!');

// Content stream: текст с embedded font.
$cs = (new ContentStream)
    ->textHexString('F1', 24, 72, 750, $helloHex);
$contentBody = $cs->toString();

// Build PDF.
$writer = new Writer(version: '1.7');

$catalogId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$pageId = $writer->reserveObject();
$contentsId = $writer->reserveObject();

// Регистрируем font — это создаёт все объекты (FontFile2, FontDescriptor,
// CIDFontType2, ToUnicode CMap, Type0 dict). Возвращает top-level Type0 ID.
$fontId = $font->registerWith($writer);

$writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");

$writer->setObject($pagesId, "<< /Type /Pages /Kids [$pageId 0 R] /Count 1 >>");

$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] '
    .'/Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
    $pagesId,
    $contentsId,
    $fontId,
));

$writer->setObject($contentsId, sprintf(
    "<< /Length %d >>\nstream\n%sendstream",
    strlen($contentBody),
    $contentBody,
));

$writer->setRoot($catalogId);

echo $writer->toBytes();
