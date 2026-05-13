<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfATest extends TestCase
{
    private string $iccPath = __DIR__.'/../fixtures/dummy.icc';

    #[Test]
    public function enabling_pdfa_downgrades_pdf_version_to_1_4(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $bytes = $pdf->toBytes();

        self::assertStringStartsWith('%PDF-1.4', $bytes);
    }

    #[Test]
    public function pdfa_emits_metadata_outputintent_lang_in_catalog(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, lang: 'ru'));
        $bytes = $pdf->toBytes();

        // Catalog должен содержать /Metadata + /OutputIntents + /Lang.
        self::assertMatchesRegularExpression('@/Metadata\s+\d+\s+0\s+R@', $bytes);
        self::assertMatchesRegularExpression('@/OutputIntents \[\d+\s+0\s+R\]@', $bytes);
        self::assertStringContainsString('/Lang (ru)', $bytes);
    }

    #[Test]
    public function pdfa_metadata_stream_contains_pdfaid_markers(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, title: 'Archive Title'));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $bytes);
        self::assertStringContainsString('Archive Title', $bytes);
    }

    #[Test]
    public function pdfa_output_intent_references_icc_profile(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, iccProfileName: 'sRGB Test'));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Type /OutputIntent', $bytes);
        self::assertStringContainsString('/S /GTS_PDFA1', $bytes);
        self::assertStringContainsString('(sRGB Test)', $bytes);
        // /N 3 indicates RGB ICC profile.
        self::assertStringContainsString('/N 3', $bytes);
    }

    #[Test]
    public function pdfa_encryption_combination_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $this->expectException(\LogicException::class);
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_128);
    }

    #[Test]
    public function encrypt_then_pdfa_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw');
        $this->expectException(\LogicException::class);
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
    }

    #[Test]
    public function pdfa_config_requires_readable_icc_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfAConfig('/nonexistent/path.icc');
    }

    #[Test]
    public function default_lang_is_en(): void
    {
        $config = new PdfAConfig($this->iccPath);
        self::assertSame('en', $config->lang);
    }

    #[Test]
    public function pdfa_smoke_with_text_content(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Archive content', 72, 720, StandardFont::Helvetica, 12);
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, title: 'My Doc', author: 'Author Name'));
        $bytes = $pdf->toBytes();

        // PDF compiles successfully + содержит markers + content.
        self::assertStringContainsString('Archive content', $bytes);
        self::assertStringContainsString('GTS_PDFA1', $bytes);
        self::assertStringContainsString('Author Name', $bytes);
    }
}
