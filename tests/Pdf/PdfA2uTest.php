<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfA2uTest extends TestCase
{
    private string $iccPath = __DIR__.'/../fixtures/dummy.icc';

    #[Test]
    public function pdfa_2u_metadata(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_2,
            conformance: PdfAConfig::CONFORMANCE_U,
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:conformance>U</pdfaid:conformance>', $bytes);
    }

    #[Test]
    public function pdfa_2a_accessible(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_2,
            conformance: PdfAConfig::CONFORMANCE_A,
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:conformance>A</pdfaid:conformance>', $bytes);
    }

    #[Test]
    public function pdfa_3b_supported(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_3,
            conformance: PdfAConfig::CONFORMANCE_B,
        ));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
    }

    #[Test]
    public function pdfa_1u_not_allowed(): void
    {
        // PDF/A-1 supports только A или B, не U.
        $this->expectException(\InvalidArgumentException::class);
        new PdfAConfig(
            $this->iccPath,
            part: PdfAConfig::PART_1,
            conformance: PdfAConfig::CONFORMANCE_U,
        );
    }

    #[Test]
    public function default_remains_pdfa_1b(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $bytes);
    }

    #[Test]
    public function invalid_part_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfAConfig($this->iccPath, part: 5);
    }
}
