<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 121: Stamp + Polygon + PolyLine annotations.
 */
final class StampPolygonAnnotationTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function stamp_emits_subtype_and_name(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addStampAnnotation(100, 200, 200, 100, 'Approved', 'Reviewed'));

        self::assertStringContainsString('/Subtype /Stamp', $bytes);
        self::assertStringContainsString('/Name /Approved', $bytes);
        self::assertStringContainsString('/Contents (Reviewed)', $bytes);
    }

    #[Test]
    public function stamp_default_is_draft(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addStampAnnotation(0, 0, 50, 50));
        self::assertStringContainsString('/Name /Draft', $bytes);
    }

    #[Test]
    public function invalid_stamp_name_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addStampAnnotation(0, 0, 10, 10, 'BogusStamp');
    }

    #[Test]
    public function polygon_emits_vertices_and_bbox(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addPolygonAnnotation(
            [[10, 10], [100, 10], [50, 100]],
            strokeColor: [1, 0, 0],
            fillColor: [1, 1, 0.5],
        ));

        self::assertStringContainsString('/Subtype /Polygon', $bytes);
        self::assertStringContainsString('/Vertices [10 10 100 10 50 100]', $bytes);
        self::assertStringContainsString('/Rect [10 10 100 100]', $bytes);
        self::assertStringContainsString('/IC [1 1 0.5]', $bytes);
    }

    #[Test]
    public function polyline_emits_subtype_polyline(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addPolyLineAnnotation([[0, 0], [10, 50], [20, 0]]));

        self::assertStringContainsString('/Subtype /PolyLine', $bytes);
        self::assertStringContainsString('/Vertices [0 0 10 50 20 0]', $bytes);
    }

    #[Test]
    public function polygon_too_few_vertices_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addPolygonAnnotation([[0, 0], [10, 10]]);
    }

    #[Test]
    public function polyline_too_few_vertices_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addPolyLineAnnotation([[0, 0]]);
    }
}
