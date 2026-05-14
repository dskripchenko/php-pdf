<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 116: rectangular + polygon clipping paths.
 */
final class ClippingTest extends TestCase
{
    #[Test]
    public function withClipRect_wraps_drawing_in_q_W_n_Q(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->withClipRect(50, 50, 100, 100, function ($p) {
            $p->fillRect(0, 0, 1000, 1000, 1, 0, 0);
        });
        $bytes = $pdf->toBytes();

        self::assertStringContainsString("q\n50 50 100 100 re\nW n", $bytes);
        // Inner fill emitted, then matching Q.
        self::assertStringContainsString('0 0 1000 1000 re', $bytes);
    }

    #[Test]
    public function clip_polygon_emits_path_and_W_n(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->withClipPolygon([[0, 0], [100, 0], [50, 100]], function ($p) {
            $p->fillRect(0, 0, 200, 200, 0, 1, 0);
        });
        $bytes = $pdf->toBytes();

        self::assertStringContainsString("0 0 m", $bytes);
        self::assertStringContainsString("100 0 l", $bytes);
        self::assertStringContainsString("50 100 l", $bytes);
        self::assertStringContainsString("h W n", $bytes);
    }

    #[Test]
    public function clip_polygon_rejects_under_three_points(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->withClipPolygon([[0, 0], [10, 0]], fn () => null);
    }

    #[Test]
    public function clip_restored_after_callback(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->withClipRect(0, 0, 50, 50, function ($p) {
            $p->fillRect(5, 5, 10, 10, 1, 1, 1);
        });
        // After block, the next op should NOT be clipped — verified by
        // exact stream sequence containing 'Q' between the inner fill
        // and any subsequent op.
        $page->fillRect(60, 60, 5, 5, 0, 0, 0);
        $bytes = $pdf->toBytes();

        // 'Q\n60 60 5 5 re' appears in order.
        self::assertMatchesRegularExpression('@Q\n.*?60 60 5 5 re@s', $bytes);
    }
}
