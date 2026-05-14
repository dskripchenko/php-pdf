<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 110: /CropBox, /BleedBox, /TrimBox, /ArtBox (print production).
 */
final class PageBoxesTest extends TestCase
{
    #[Test]
    public function default_omits_optional_boxes(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/MediaBox', $bytes);
        self::assertStringNotContainsString('/CropBox', $bytes);
        self::assertStringNotContainsString('/BleedBox', $bytes);
        self::assertStringNotContainsString('/TrimBox', $bytes);
        self::assertStringNotContainsString('/ArtBox', $bytes);
    }

    #[Test]
    public function setCropBox_emits_cropbox_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCropBox(10, 10, 585, 832);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/CropBox [10 10 585 832]', $bytes);
    }

    #[Test]
    public function all_four_boxes_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCropBox(0, 0, 600, 800);
        $page->setBleedBox(5, 5, 595, 795);
        $page->setTrimBox(10, 10, 590, 790);
        $page->setArtBox(20, 20, 580, 780);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/CropBox [0 0 600 800]', $bytes);
        self::assertStringContainsString('/BleedBox [5 5 595 795]', $bytes);
        self::assertStringContainsString('/TrimBox [10 10 590 790]', $bytes);
        self::assertStringContainsString('/ArtBox [20 20 580 780]', $bytes);
    }

    #[Test]
    public function fractional_coordinates_formatted_without_locale(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setBleedBox(0.5, 0.5, 595.276, 841.89);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/BleedBox [0.5 0.5 595.276 841.89]', $bytes);
    }

    #[Test]
    public function accessors_return_stored_values(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTrimBox(1, 2, 3, 4);

        self::assertSame([1.0, 2.0, 3.0, 4.0], $page->trimBox());
        self::assertNull($page->cropBox());
    }
}
