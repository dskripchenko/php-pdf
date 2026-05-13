<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageRotationTest extends TestCase
{
    #[Test]
    public function rotation_90_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setRotation(90);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Rotate 90', $bytes);
    }

    #[Test]
    public function rotation_180(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setRotation(180);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Rotate 180', $bytes);
    }

    #[Test]
    public function rotation_normalization(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setRotation(450); // 450 mod 360 = 90.
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Rotate 90', $bytes);
    }

    #[Test]
    public function negative_rotation_normalized(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setRotation(-90); // → 270.
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Rotate 270', $bytes);
    }

    #[Test]
    public function default_rotation_no_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/Rotate', $bytes);
    }

    #[Test]
    public function non_multiple_of_90_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->setRotation(45);
    }
}
