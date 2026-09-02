<?php

declare(strict_types=1);

/**
 * Hand-builds a minimal AcroForm PDF with a text field reachable only
 * through /Parent-chain name inheritance ("employer.name") — a shape the
 * library's own FormField authoring API cannot produce (it only emits flat
 * fields, plus its own auto-generated radio-group parent/kids). Used as a
 * static fixture by tests/Pdf/Forms/ExistingFormFillerTest.php.
 *
 * Run: php scripts/fixtures/generate-parent-inherited-form.php
 */

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Pdf\Writer;

$writer = new Writer();

$pageId = $writer->reserveObject();
$pagesId = $writer->reserveObject();
$catalogId = $writer->reserveObject();
$acroFormId = $writer->reserveObject();
$parentId = $writer->reserveObject();
$childId = $writer->reserveObject();

$contentsId = $writer->addObject("<< /Length 0 >>\nstream\n\nendstream");

// Non-terminal field node: only /T + /Kids, no /FT/value of its own.
$writer->setObject($parentId, "<< /FT /Tx /T (employer) /Kids [{$childId} 0 R] >>");

// Terminal field, itself the sole widget annotation: partial /T "name" +
// /Parent -> fully-qualified name resolves to "employer.name".
$writer->setObject($childId, sprintf(
    '<< /Type /Annot /Subtype /Widget /FT /Tx /Rect [100 700 300 720] '
    .'/T (name) /Parent %d 0 R /P %d 0 R /F 4 /V () /DA (/Helv 10 Tf 0 g) >>',
    $parentId,
    $pageId,
));

$writer->setObject($acroFormId, sprintf(
    '<< /Fields [%d 0 R] /DA (/Helv 10 Tf 0 g) /DR << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>',
    $parentId,
));

$writer->setObject($pageId, sprintf(
    '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /Contents %d 0 R '
    .'/Resources << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> '
    .'/Annots [%d 0 R] >>',
    $pagesId,
    $contentsId,
    $childId,
));

$writer->setObject($pagesId, sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 >>', $pageId));
$writer->setObject($catalogId, sprintf('<< /Type /Catalog /Pages %d 0 R /AcroForm %d 0 R >>', $pagesId, $acroFormId));
$writer->setRoot($catalogId);

$outPath = __DIR__.'/../../tests/fixtures/forms/parent-inherited.pdf';
file_put_contents($outPath, $writer->toBytes());

echo "Wrote {$outPath}\n";
