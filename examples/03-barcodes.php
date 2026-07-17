<?php

declare(strict_types=1);

// Barcodes: 12 linear + 4 2D symbologies. A few common ones here —
// see the torture set (examples/torture) for the full sweep.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Section;

$doc = new Document(new Section([
    new Heading(1, [new Run('Barcodes')]),
    new Paragraph([new Run('QR (URL):')]),
    new Barcode('https://github.com/dskripchenko/php-pdf', BarcodeFormat::Qr, heightPt: 90),
    new Paragraph([new Run('Code 128:')]),
    new Barcode('PHP-PDF-2026', BarcodeFormat::Code128),
    new Paragraph([new Run('EAN-13:')]),
    new Barcode('4006381333931', BarcodeFormat::Ean13),
    new Paragraph([new Run('DataMatrix (rectangular symbols supported):')]),
    new Barcode('php-pdf sample', BarcodeFormat::DataMatrix, heightPt: 60),
]));

save_sample('03-barcodes.pdf', $doc->toBytes());
