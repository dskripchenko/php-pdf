<?php

declare(strict_types=1);

// Stamping: overlay a page (watermark, letterhead) onto every page of an
// existing document — the FPDI use case, MIT-licensed.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Merge\Placement;

$contract = DocumentBuilder::new()
    ->heading(1, 'Service contract')
    ->paragraph('Section 1. The parties agree on the scope of work.')
    ->pageBreak()
    ->heading(2, 'Appendix A')
    ->paragraph('Detailed schedule of deliverables.')
    ->toBytes();

$watermark = DocumentBuilder::new()->heading(1, 'COPY — NOT AN ORIGINAL')->toBytes();

$stamped = PdfMerger::create()
    ->append(PdfSource::fromBytes($contract))
    ->stamp(PdfSource::fromBytes($watermark), placement: Placement::fit())
    ->toBytes();

save_sample('09-stamp.pdf', $stamped);
