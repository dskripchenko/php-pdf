<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfFormXObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 107: Form XObject reusable content.
 */
final class FormXObjectTest extends TestCase
{
    private function newForm(string $content = "1 0 0 RG\n0 0 10 10 re\nS\n"): PdfFormXObject
    {
        return new PdfFormXObject($content, 0, 0, 10, 10);
    }

    #[Test]
    public function form_xobject_emits_correct_dictionary(): void
    {
        $form = $this->newForm();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 100, 200, 50, 50);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Type /XObject', $bytes);
        self::assertStringContainsString('/Subtype /Form', $bytes);
        self::assertStringContainsString('/BBox [0 0 10 10]', $bytes);
        self::assertStringContainsString('0 0 10 10 re', $bytes);
    }

    #[Test]
    public function form_xobject_referenced_via_do(): void
    {
        $form = $this->newForm();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 100, 200, 50, 50);
        $bytes = $pdf->toBytes();

        // Page content stream should contain `/Fm1 Do`.
        self::assertMatchesRegularExpression('@/Fm\d+ Do@', $bytes);
    }

    #[Test]
    public function form_xobject_appears_in_page_xobject_resources(): void
    {
        $form = $this->newForm();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 100, 200, 50, 50);
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/XObject <<[^>]*/Fm1 \d+ 0 R@', $bytes);
    }

    #[Test]
    public function same_form_xobject_deduplicated_across_pages(): void
    {
        $form = $this->newForm();
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->useFormXObject($form, 10, 10, 20, 20);
        $pdf->addPage()->useFormXObject($form, 30, 30, 40, 40);
        $bytes = $pdf->toBytes();

        // Only one /Subtype /Form occurrence — single shared XObject body.
        self::assertSame(1, substr_count($bytes, '/Subtype /Form'));
    }

    #[Test]
    public function ctm_scales_bbox_into_target_rect(): void
    {
        // BBox 0..10, drawn at (100, 200) with size 50×50 → sx=sy=5, tx=100, ty=200.
        $form = $this->newForm();
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 100, 200, 50, 50);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('5 0 0 5 100 200 cm', $bytes);
    }

    #[Test]
    public function non_origin_bbox_translates_correctly(): void
    {
        // BBox 10..30 (w=20, h=20), draw at (50, 60) с size 40×40 → sx=sy=2,
        //   tx = 50 - 10*2 = 30; ty = 60 - 10*2 = 40.
        $form = new PdfFormXObject("\n", 10, 10, 30, 30);
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 50, 60, 40, 40);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('2 0 0 2 30 40 cm', $bytes);
    }

    #[Test]
    public function explicit_matrix_emitted_in_dict(): void
    {
        $form = new PdfFormXObject("\n", 0, 0, 10, 10, matrix: [1, 0, 0, 1, 5, 5]);
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->useFormXObject($form, 0, 0, 10, 10);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Matrix [1 0 0 1 5 5]', $bytes);
    }

    #[Test]
    public function rejects_zero_or_inverted_bbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfFormXObject('', 10, 10, 5, 5);
    }

    #[Test]
    public function rejects_malformed_matrix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfFormXObject('', 0, 0, 10, 10, matrix: [1, 0, 0]);
    }

    #[Test]
    public function helpers_return_bbox_dimensions(): void
    {
        $form = new PdfFormXObject('', 10, 20, 50, 80);
        self::assertSame(40.0, $form->bboxWidth());
        self::assertSame(60.0, $form->bboxHeight());
    }
}
