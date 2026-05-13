<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ViewerPreferencesTest extends TestCase
{
    #[Test]
    public function boolean_preferences(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setViewerPreferences([
            'hideToolbar' => true,
            'hideMenubar' => true,
            'fitWindow' => true,
            'centerWindow' => false,
        ]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/HideToolbar true', $bytes);
        self::assertStringContainsString('/HideMenubar true', $bytes);
        self::assertStringContainsString('/FitWindow true', $bytes);
        self::assertStringContainsString('/CenterWindow false', $bytes);
    }

    #[Test]
    public function direction_r2l(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setViewerPreferences(['direction' => 'R2L']);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Direction /R2L', $bytes);
    }

    #[Test]
    public function print_scaling_none(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setViewerPreferences(['printScaling' => 'None']);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/PrintScaling /None', $bytes);
    }

    #[Test]
    public function duplex_long_edge(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setViewerPreferences(['duplex' => 'DuplexFlipLongEdge']);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Duplex /DuplexFlipLongEdge', $bytes);
    }

    #[Test]
    public function display_doc_title(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setViewerPreferences(['displayDocTitle' => true]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/DisplayDocTitle true', $bytes);
    }

    #[Test]
    public function empty_preferences_no_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/ViewerPreferences', $bytes);
    }
}
