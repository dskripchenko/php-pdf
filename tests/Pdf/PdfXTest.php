<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfXConfig;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 225: PDF/X-3 / X-4 conformance tests.
 */
final class PdfXTest extends TestCase
{
    private string $iccPath = __DIR__.'/../fixtures/dummy.icc';

    protected function setUp(): void
    {
        if (! is_readable($this->iccPath)) {
            self::markTestSkipped('ICC profile fixture not available.');
        }
    }

    private function buildPdf(string $variant = PdfXConfig::VARIANT_X3): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('PDF/X test', 100, 700, StandardFont::Helvetica, 12);
        $pdf->enablePdfX(new PdfXConfig(
            iccProfilePath: $this->iccPath,
            variant: $variant,
        ));

        return $pdf->toBytes();
    }

    #[Test]
    public function emits_output_intent_with_gts_pdfx(): void
    {
        $bytes = $this->buildPdf();
        self::assertStringContainsString('/Type /OutputIntent', $bytes);
        self::assertStringContainsString('/S /GTS_PDFX', $bytes);
    }

    #[Test]
    public function emits_metadata_stream(): void
    {
        $bytes = $this->buildPdf();
        self::assertStringContainsString('/Type /Metadata', $bytes);
        self::assertStringContainsString('pdfx:GTS_PDFXVersion', $bytes);
    }

    #[Test]
    public function info_dict_contains_trapped(): void
    {
        $bytes = $this->buildPdf();
        // Default trapped = False.
        self::assertStringContainsString('/Trapped /False', $bytes);
    }

    #[Test]
    public function trapped_value_configurable(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfX(new PdfXConfig(
            iccProfilePath: $this->iccPath,
            trapped: PdfXConfig::TRAPPED_TRUE,
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Trapped /True', $bytes);
    }

    #[Test]
    public function variant_x4_bumps_pdf_version_to_1_6(): void
    {
        $bytes = $this->buildPdf(PdfXConfig::VARIANT_X4);
        self::assertMatchesRegularExpression('@^%PDF-1\.[67]@', $bytes);
        // XMP marker reflects variant.
        self::assertStringContainsString('PDF/X-4', $bytes);
    }

    #[Test]
    public function variant_x1a_bumps_to_1_4(): void
    {
        $bytes = $this->buildPdf(PdfXConfig::VARIANT_X1A);
        // PDF version should be 1.4+.
        self::assertMatchesRegularExpression('@^%PDF-1\.[4-9]@', $bytes);
        self::assertStringContainsString('PDF/X-1a:2003', $bytes);
    }

    #[Test]
    public function rejects_pdf_x_with_pdf_a(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new \Dskripchenko\PhpPdf\Pdf\PdfAConfig($this->iccPath));

        $this->expectException(\LogicException::class);
        $pdf->enablePdfX(new PdfXConfig(iccProfilePath: $this->iccPath));
    }

    #[Test]
    public function rejects_pdf_x_with_encryption(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('password');

        $this->expectException(\LogicException::class);
        $pdf->enablePdfX(new PdfXConfig(iccProfilePath: $this->iccPath));
    }

    #[Test]
    public function reject_unknown_variant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfXConfig(iccProfilePath: $this->iccPath, variant: 'PDF/X-99');
    }

    #[Test]
    public function reject_invalid_trapped_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfXConfig(iccProfilePath: $this->iccPath, trapped: 'maybe');
    }

    #[Test]
    public function output_condition_identifier_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfX(new PdfXConfig(
            iccProfilePath: $this->iccPath,
            outputConditionIdentifier: 'FOGRA39',
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/OutputConditionIdentifier (FOGRA39)', $bytes);
    }

    #[Test]
    public function pdf_structure_remains_valid(): void
    {
        $bytes = $this->buildPdf();
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString("%%EOF\n", $bytes);
    }
}
