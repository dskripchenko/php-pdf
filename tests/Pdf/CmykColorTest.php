<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 117: DeviceCMYK color operators (k / K).
 */
final class CmykColorTest extends TestCase
{
    #[Test]
    public function cmyk_fill_emits_k_operator(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCmykFillColor(0.2, 0.4, 0.6, 0.1);
        $page->fillRect(10, 10, 50, 50, 0, 0, 0);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('0.2 0.4 0.6 0.1 k', $bytes);
    }

    #[Test]
    public function cmyk_stroke_emits_K_operator(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCmykStrokeColor(1.0, 0.0, 0.0, 0.0);
        $page->strokeLine(10, 10, 100, 10);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('1 0 0 0 K', $bytes);
    }

    #[Test]
    public function rich_black_emitted_correctly(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCmykFillColor(0.6, 0.4, 0.4, 1.0); // rich black
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('0.6 0.4 0.4 1 k', $bytes);
    }

    #[Test]
    public function out_of_range_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setCmykFillColor(1.5, 0, 0, 0);
    }

    #[Test]
    public function negative_value_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setCmykStrokeColor(0, -0.1, 0, 0);
    }
}
