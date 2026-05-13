<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenActionTest extends TestCase
{
    #[Test]
    public function fit_page_open_action(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setOpenAction('fit-page');
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/OpenAction \[\d+\s+0\s+R\s+/Fit\]@', $bytes);
    }

    #[Test]
    public function fit_width_open_action(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setOpenAction('fit-width');
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/OpenAction \[\d+\s+0\s+R\s+/FitH null\]@', $bytes);
    }

    #[Test]
    public function xyz_with_zoom(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setOpenAction('xyz', x: 0, y: 720, zoom: 1.5);
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/OpenAction \[\d+\s+0\s+R\s+/XYZ 0 720 1\.5\]@', $bytes);
    }

    #[Test]
    public function open_to_specific_page(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->addPage();
        $pdf->addPage();
        // Open на page 2 (index 1).
        $pdf->setOpenAction('fit-page', pageIndex: 2);
        $bytes = $pdf->toBytes();
        // Должна reference object of 2nd page.
        self::assertStringContainsString('/OpenAction', $bytes);
    }

    #[Test]
    public function page_mode_outlines(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageMode('use-outlines');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/PageMode /UseOutlines', $bytes);
    }

    #[Test]
    public function page_mode_full_screen(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageMode('full-screen');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/PageMode /FullScreen', $bytes);
    }

    #[Test]
    public function page_layout_two_column(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageLayout('two-column-left');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/PageLayout /TwoColumnLeft', $bytes);
    }

    #[Test]
    public function defaults_no_entries(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/OpenAction', $bytes);
        self::assertStringNotContainsString('/PageMode', $bytes);
        self::assertStringNotContainsString('/PageLayout', $bytes);
    }
}
