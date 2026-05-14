<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 118: text rendering modes (Tr 0..7).
 */
final class TextRenderingModeTest extends TestCase
{
    private function emit(int $mode): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTextRenderingMode($mode);
        $page->showText('test', 100, 100, StandardFont::Helvetica, 12);

        return $pdf->toBytes();
    }

    #[Test]
    public function fill_mode_default(): void
    {
        self::assertStringContainsString("0 Tr", $this->emit(0));
    }

    #[Test]
    public function stroke_only_mode(): void
    {
        self::assertStringContainsString("1 Tr", $this->emit(1));
    }

    #[Test]
    public function invisible_mode_useful_for_ocr_layer(): void
    {
        self::assertStringContainsString("3 Tr", $this->emit(3));
    }

    #[Test]
    public function clip_only_mode(): void
    {
        self::assertStringContainsString("7 Tr", $this->emit(7));
    }

    #[Test]
    public function out_of_range_negative_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setTextRenderingMode(-1);
    }

    #[Test]
    public function out_of_range_high_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setTextRenderingMode(8);
    }
}
