<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 122: Ink annotation (freehand drawing).
 */
final class InkAnnotationTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function single_stroke_emits_ink_list(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addInkAnnotation([
            [[10, 20], [15, 25], [20, 30]],
        ]));

        self::assertStringContainsString('/Subtype /Ink', $bytes);
        self::assertStringContainsString('/InkList [[10 20 15 25 20 30]]', $bytes);
    }

    #[Test]
    public function multiple_strokes_each_become_inner_array(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addInkAnnotation([
            [[0, 0], [10, 10]],
            [[20, 20], [30, 30]],
        ]));

        self::assertStringContainsString('/InkList [[0 0 10 10] [20 20 30 30]]', $bytes);
    }

    #[Test]
    public function rect_is_bbox_over_all_strokes(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addInkAnnotation([
            [[100, 200]],
            [[5, 6], [50, 60]],
        ]));

        self::assertStringContainsString('/Rect [5 6 100 200]', $bytes);
    }

    #[Test]
    public function color_and_border_width_emitted(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addInkAnnotation(
            [[[0, 0], [10, 10]]],
            strokeColor: [0.2, 0.4, 0.6],
            borderWidth: 1.5,
        ));

        self::assertStringContainsString('/C [0.2 0.4 0.6]', $bytes);
        self::assertStringContainsString('/BS << /Type /Border /W 1.5 /S /S >>', $bytes);
    }

    #[Test]
    public function empty_strokes_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addInkAnnotation([]);
    }

    #[Test]
    public function empty_stroke_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addInkAnnotation([[]]);
    }
}
