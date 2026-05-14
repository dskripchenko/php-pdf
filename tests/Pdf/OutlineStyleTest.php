<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutlineStyleTest extends TestCase
{
    #[Test]
    public function outline_with_color(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'Red Bookmark', $page, 0, 700, color: '#ff0000');
        $bytes = $pdf->toBytes();

        // /C [1 0 0] — red.
        self::assertMatchesRegularExpression('@/C \[1\s+0\s+0\]@', $bytes);
    }

    #[Test]
    public function outline_with_bold(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'Bold', $page, 0, 700, bold: true);
        $bytes = $pdf->toBytes();

        // /F 2 (bold flag).
        self::assertStringContainsString('/F 2', $bytes);
    }

    #[Test]
    public function outline_with_italic(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'Italic', $page, 0, 700, italic: true);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/F 1', $bytes);
    }

    #[Test]
    public function outline_bold_italic_combined(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'BI', $page, 0, 700, bold: true, italic: true);
        $bytes = $pdf->toBytes();

        // /F 3 = bold (2) + italic (1).
        self::assertStringContainsString('/F 3', $bytes);
    }

    #[Test]
    public function outline_default_no_color_no_flags(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'Plain', $page, 0, 700);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/C [', $bytes);
        // /F не emitted (default 0).
        self::assertDoesNotMatchRegularExpression('@/F\s+\d+@', $bytes);
    }

    #[Test]
    public function color_short_hex_supported(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerOutlineEntry(1, 'Green', $page, 0, 700, color: '#0f0');
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/C \[0\s+1\s+0\]@', $bytes);
    }
}
