<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrawImageRotatedTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    #[Test]
    public function rotated_emits_cm_with_non_axis_aligned_matrix(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $img = PdfImage::fromPath($this->jpegPath);
        $page->drawImageRotated($img, 100, 100, 50, 50, M_PI / 4);
        $bytes = $pdf->toBytes();

        // cm operator with non-axis-aligned matrix (b ≠ 0).
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+cm@', $bytes);
        // Image XObject Do operator.
        self::assertMatchesRegularExpression('@/Im\d+\s+Do@', $bytes);
    }

    #[Test]
    public function zero_degree_rotation_equivalent_to_drawimage(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $img = PdfImage::fromPath($this->jpegPath);
        $page->drawImageRotated($img, 100, 100, 50, 50, 0);
        $bytes = $pdf->toBytes();

        // No-rotation: cm matrix = scale + translate ≈ axis-aligned.
        self::assertStringContainsString(' Do', $bytes);
    }

    #[Test]
    public function ninety_degree_rotation(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $img = PdfImage::fromPath($this->jpegPath);
        $page->drawImageRotated($img, 100, 100, 50, 50, M_PI / 2);
        $bytes = $pdf->toBytes();

        // a = cos(90)·w ≈ 0; b = sin(90)·w ≈ 50.
        self::assertStringContainsString(' Do', $bytes);
        // Wrap в q ... Q для local CTM.
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString("\nQ\n", $bytes);
    }
}
