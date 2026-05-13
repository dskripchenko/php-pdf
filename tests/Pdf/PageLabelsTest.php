<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageLabelsTest extends TestCase
{
    #[Test]
    public function decimal_labels(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageLabels([['startPage' => 0, 'style' => 'decimal']]);
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/PageLabels << /Nums \[0 << /S /D >>\] >>@', $bytes);
    }

    #[Test]
    public function roman_then_decimal(): void
    {
        // Front matter (i, ii, iii) then body (1, 2, 3).
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->addPage();
        $pdf->addPage();
        $pdf->setPageLabels([
            ['startPage' => 0, 'style' => 'lower-roman'],
            ['startPage' => 2, 'style' => 'decimal'],
        ]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/S /r', $bytes);
        self::assertStringContainsString('/S /D', $bytes);
    }

    #[Test]
    public function prefix_applied(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageLabels([['startPage' => 0, 'style' => 'decimal', 'prefix' => 'A-']]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/P (A-)', $bytes);
    }

    #[Test]
    public function first_number_offset(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageLabels([['startPage' => 0, 'style' => 'decimal', 'firstNumber' => 100]]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/St 100', $bytes);
    }

    #[Test]
    public function upper_alpha_style(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setPageLabels([['startPage' => 0, 'style' => 'upper-alpha']]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/S /A', $bytes);
    }

    #[Test]
    public function no_labels_no_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/PageLabels', $bytes);
    }
}
