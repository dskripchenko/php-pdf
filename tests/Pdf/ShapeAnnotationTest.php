<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 120: Square/Circle/Line annotation shapes.
 */
final class ShapeAnnotationTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function square_emits_subtype_and_border(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addSquareAnnotation(
            10, 20, 100, 50,
            strokeColor: [1.0, 0.0, 0.0],
            borderWidth: 2.0,
        ));

        self::assertStringContainsString('/Subtype /Square', $bytes);
        self::assertStringContainsString('/Rect [10 20 110 70]', $bytes);
        self::assertStringContainsString('/C [1 0 0]', $bytes);
        self::assertStringContainsString('/BS << /Type /Border /W 2 /S /S >>', $bytes);
    }

    #[Test]
    public function square_with_fill_emits_ic(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addSquareAnnotation(
            0, 0, 50, 50,
            fillColor: [0.5, 0.5, 0.5],
        ));

        self::assertStringContainsString('/IC [0.5 0.5 0.5]', $bytes);
    }

    #[Test]
    public function circle_emits_subtype_circle(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addCircleAnnotation(0, 0, 100, 100));
        self::assertStringContainsString('/Subtype /Circle', $bytes);
    }

    #[Test]
    public function line_emits_subtype_and_endpoints(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addLineAnnotation(10, 20, 100, 80, [0, 0, 1]));

        self::assertStringContainsString('/Subtype /Line', $bytes);
        self::assertStringContainsString('/L [10 20 100 80]', $bytes);
        self::assertStringContainsString('/C [0 0 1]', $bytes);
    }

    #[Test]
    public function line_rect_uses_normalized_corners(): void
    {
        // Endpoints reversed — rect should still bound the line correctly.
        $bytes = $this->emit(fn ($p) => $p->addLineAnnotation(100, 80, 10, 20));
        self::assertStringContainsString('/Rect [10 20 100 80]', $bytes);
        self::assertStringContainsString('/L [100 80 10 20]', $bytes);
    }
}
