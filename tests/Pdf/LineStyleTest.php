<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 114: line dash pattern, line cap, line join, miter limit.
 */
final class LineStyleTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);
        $page->strokeLine(50, 50, 200, 50);

        return $pdf->toBytes();
    }

    #[Test]
    public function dash_pattern_emits_d_operator(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setLineDashPattern([5, 3]));

        self::assertStringContainsString('[5 3] 0 d', $bytes);
    }

    #[Test]
    public function dash_phase_emitted(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setLineDashPattern([10, 5, 2, 5], 2.5));

        self::assertStringContainsString('[10 5 2 5] 2.5 d', $bytes);
    }

    #[Test]
    public function reset_dash_emits_empty(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setLineDashPattern([3, 3])->resetLineDashPattern());

        self::assertStringContainsString('[] 0 d', $bytes);
    }

    #[Test]
    public function line_cap_emits_J(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setLineCap(1));
        self::assertStringContainsString("1 J", $bytes);
    }

    #[Test]
    public function invalid_line_cap_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setLineCap(5);
    }

    #[Test]
    public function line_join_emits_j(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setLineJoin(2));
        self::assertStringContainsString("2 j", $bytes);
    }

    #[Test]
    public function invalid_line_join_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setLineJoin(-1);
    }

    #[Test]
    public function miter_limit_emits_M(): void
    {
        $bytes = $this->emit(fn ($p) => $p->setMiterLimit(4.5));
        self::assertStringContainsString('4.5 M', $bytes);
    }
}
