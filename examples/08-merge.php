<?php

declare(strict_types=1);

// Read + merge: append pages from multiple PDFs (any producer — classic
// xref, xref streams, object streams, even encrypted sources).
// Annotations and outlines are carried over by default.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;

// Two source documents (stand-ins for files from anywhere).
$cover = DocumentBuilder::new()->heading(1, 'Combined report')->paragraph('Cover page.')->toBytes();
$body = DocumentBuilder::new()
    ->heading(2, 'Chapter 1')->paragraph('First chapter body.')
    ->pageBreak()
    ->heading(2, 'Chapter 2')->paragraph('Second chapter body.')
    ->toBytes();

$merged = PdfMerger::create()
    ->append(PdfSource::fromBytes($cover))
    ->append(PdfSource::fromBytes($body), pages: [2, 1]) // reorder on the fly
    ->toBytes();

save_sample('08-merge.pdf', $merged);
