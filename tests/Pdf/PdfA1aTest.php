<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 190: PDF/A-1a (accessible) compliance — auto-enables Tagged PDF.
 */
final class PdfA1aTest extends TestCase
{
    private string $iccPath = __DIR__.'/../fixtures/dummy.icc';

    #[Test]
    public function pdfa_1a_auto_enables_tagging(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_1,
            conformance: PdfAConfig::CONFORMANCE_A,
        ));
        self::assertTrue($pdf->isTagged(), 'PDF/A-1a должен автоматически включить Tagged PDF');
    }

    #[Test]
    public function pdfa_1b_does_not_enable_tagging(): void
    {
        // PDF/A-1b basic — tagging optional.
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_1,
            conformance: PdfAConfig::CONFORMANCE_B,
        ));
        self::assertFalse($pdf->isTagged(), 'PDF/A-1b не должен auto-enable tagging');
    }

    #[Test]
    public function pdfa_1a_metadata(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_1,
            conformance: PdfAConfig::CONFORMANCE_A,
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:conformance>A</pdfaid:conformance>', $bytes);
        // Tagged flag set; actual /MarkInfo emit зависит от content (нужен tagged blocks).
        self::assertTrue($pdf->isTagged());
    }

    #[Test]
    public function pdfa_1a_explicit_tagging_still_works(): void
    {
        // Caller may explicitly enableTagged() before/after enablePdfA — idempotent.
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enableTagged();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_1,
            conformance: PdfAConfig::CONFORMANCE_A,
        ));
        self::assertTrue($pdf->isTagged());
    }
}
