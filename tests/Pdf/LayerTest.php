<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfLayer;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 112: Optional Content Groups (layers).
 */
final class LayerTest extends TestCase
{
    #[Test]
    public function layer_emits_ocg_object(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addLayer('Watermark');
        $page = $pdf->addPage();
        $page->showText('x', 100, 100, StandardFont::Helvetica, 12);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Type /OCG', $bytes);
        self::assertStringContainsString('/Name (Watermark)', $bytes);
    }

    #[Test]
    public function catalog_emits_ocproperties_with_ocgs_array(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addLayer('L1');
        $pdf->addLayer('L2');
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/OCProperties <<[^>]*/OCGs \[\d+ 0 R \d+ 0 R\]@', $bytes);
    }

    #[Test]
    public function default_visibility_controls_on_off_arrays(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addLayer('Visible', defaultVisible: true);
        $pdf->addLayer('Hidden', defaultVisible: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/ON \[\d+ 0 R\]@', $bytes);
        self::assertMatchesRegularExpression('@/OFF \[\d+ 0 R\]@', $bytes);
    }

    #[Test]
    public function begin_end_layer_emits_marked_content_in_stream(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $layer = $pdf->addLayer('Annotations');
        $page = $pdf->addPage();
        $page->beginLayer($layer);
        $page->showText('inside', 100, 100, StandardFont::Helvetica, 12);
        $page->endLayer();
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/OC /OC1 BDC', $bytes);
        self::assertStringContainsString('EMC', $bytes);
    }

    #[Test]
    public function layer_appears_in_page_properties_resources(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $layer = $pdf->addLayer('Layer1');
        $page = $pdf->addPage();
        $page->beginLayer($layer);
        $page->endLayer();
        $bytes = $pdf->toBytes();

        self::assertMatchesRegularExpression('@/Properties <<[^>]*/OC1 \d+ 0 R@', $bytes);
    }

    #[Test]
    public function design_intent_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addLayer('Engineering Marks', intent: 'Design');
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Intent /Design', $bytes);
    }

    #[Test]
    public function invalid_intent_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfLayer('x', intent: 'Bogus');
    }

    #[Test]
    public function name_with_parens_escaped_in_dict(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addLayer('Layer (v2)');
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Name (Layer \\(v2\\))', $bytes);
    }

    #[Test]
    public function same_layer_used_twice_dedupes_property_name(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $layer = $pdf->addLayer('L');
        $page = $pdf->addPage();
        $page->beginLayer($layer);
        $page->endLayer();
        $page->beginLayer($layer);
        $page->endLayer();
        $bytes = $pdf->toBytes();

        // Both wrapper pairs share the same /OC1 resource name.
        self::assertSame(2, substr_count($bytes, '/OC /OC1 BDC'));
    }
}
