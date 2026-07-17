<?php

declare(strict_types=1);

// PDF/A-2u archival document — embedded fonts and an ICC output intent
// are mandatory, so this example needs the Liberation fonts:
//
//     bash scripts/fetch-fonts.sh && php examples/07-pdfa.php
//
// The output validates as compliant with veraPDF (see docs/en/CONFORMANCE.md).

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;

$fontDir = liberation_dir();
if ($fontDir === null) {
    echo "skipped: run scripts/fetch-fonts.sh first (PDF/A requires embedded fonts)\n";
    exit(0);
}

$doc = new Document(
    new Section([
        new Heading(1, [new Run('Archival document (PDF/A-2u)')]),
        new Paragraph([new Run('Embedded subset fonts, ICC output intent, XMP metadata. '
            .'Кириллица тоже работает.')]),
    ]),
    lang: 'en',
    pdfA: new PdfAConfig(
        dirname(__DIR__).'/resources/icc/sRGB2014.icc',
        iccProfileName: 'sRGB2014',
        title: 'php-pdf PDF/A example',
        author: 'php-pdf',
        part: PdfAConfig::PART_2,
        conformance: PdfAConfig::CONFORMANCE_U,
    ),
);

$engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile($fontDir.'/LiberationSans-Regular.ttf')));

save_sample('07-pdfa.pdf', $doc->toBytes($engine));
