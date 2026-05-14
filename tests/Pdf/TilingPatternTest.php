<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfTilingPattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 111: Tiling Pattern (Type 1).
 */
final class TilingPatternTest extends TestCase
{
    private function diagonalStripeTile(): PdfTilingPattern
    {
        // 10×10 cell, single diagonal stroke.
        return new PdfTilingPattern(
            contentStream: "0 0 m\n10 10 l\n0.5 w\nS\n",
            bboxLlx: 0, bboxLly: 0, bboxUrx: 10, bboxUry: 10,
            xStep: 10, yStep: 10,
        );
    }

    #[Test]
    public function tiling_pattern_emits_correct_dictionary(): void
    {
        $pattern = $this->diagonalStripeTile();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $name = $page->registerTilingPattern($pattern);
        $page->fillRectWithTilingPattern(50, 50, 200, 100, $name);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Type /Pattern', $bytes);
        self::assertStringContainsString('/PatternType 1', $bytes);
        self::assertStringContainsString('/PaintType 1', $bytes);
        self::assertStringContainsString('/TilingType 1', $bytes);
        self::assertStringContainsString('/BBox [0 0 10 10]', $bytes);
        self::assertStringContainsString('/XStep 10', $bytes);
        self::assertStringContainsString('/YStep 10', $bytes);
    }

    #[Test]
    public function pattern_content_stream_in_output(): void
    {
        $pattern = $this->diagonalStripeTile();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->registerTilingPattern($pattern);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('10 10 l', $bytes);
    }

    #[Test]
    public function fill_rect_uses_pattern_color_space(): void
    {
        $pattern = $this->diagonalStripeTile();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $name = $page->registerTilingPattern($pattern);
        $page->fillRectWithTilingPattern(10, 20, 100, 50, $name);
        $bytes = $pdf->toBytes();

        // Page content emits `q /Pattern cs /TP1 scn 10 20 100 50 re f Q`.
        self::assertStringContainsString('/Pattern cs', $bytes);
        self::assertStringContainsString("/$name scn", $bytes);
        self::assertStringContainsString('10 20 100 50 re', $bytes);
    }

    #[Test]
    public function pattern_appears_in_page_pattern_resources(): void
    {
        $pattern = $this->diagonalStripeTile();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->registerTilingPattern($pattern);
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/Pattern <<[^>]*/TP1 \d+ 0 R@', $bytes);
    }

    #[Test]
    public function explicit_matrix_emitted(): void
    {
        $pattern = new PdfTilingPattern("\n", 0, 0, 5, 5, 5, 5, matrix: [1, 0, 0, 1, 2, 3]);
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->registerTilingPattern($pattern);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Matrix [1 0 0 1 2 3]', $bytes);
    }

    #[Test]
    public function rejects_zero_or_inverted_bbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfTilingPattern('', 10, 10, 5, 5, 10, 10);
    }

    #[Test]
    public function rejects_zero_step(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfTilingPattern('', 0, 0, 10, 10, 0, 10);
    }

    #[Test]
    public function rejects_malformed_matrix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfTilingPattern('', 0, 0, 10, 10, 10, 10, matrix: [1, 2]);
    }
}
