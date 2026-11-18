<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfXConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * enablePdfUa() identifies the document as PDF/UA-1 (ISO 14289-1 §5, §7.1):
 * pdfuaid:part in the Catalog's XMP metadata, a dc:title, DisplayDocTitle.
 */
final class PdfUaTest extends TestCase
{
    #[Test]
    public function it_writes_xmp_with_the_pdfua_identification(): void
    {
        $bytes = self::document()->toBytes();

        self::assertMatchesRegularExpression('@/Type /Catalog [^\n]*/Metadata \d+ 0 R@', $bytes);
        self::assertStringContainsString('xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/"', $bytes);
        self::assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $bytes);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Annual report</rdf:li>', $bytes);
        self::assertStringNotContainsString('pdfaid:', $bytes);
    }

    #[Test]
    public function it_tags_the_document_and_shows_the_title(): void
    {
        $pdf = self::document();

        self::assertTrue($pdf->isTagged());
        self::assertTrue($pdf->isPdfUa());
        self::assertStringContainsString('/DisplayDocTitle true', $pdf->toBytes());
    }

    #[Test]
    public function display_doc_title_joins_other_viewer_preferences(): void
    {
        $pdf = self::document();
        $pdf->setViewerPreferences(['fitWindow' => true, 'displayDocTitle' => false]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/FitWindow true', $bytes);
        self::assertStringContainsString('/DisplayDocTitle true', $bytes);
        self::assertStringNotContainsString('/DisplayDocTitle false', $bytes);
    }

    #[Test]
    public function pages_with_annotations_use_structure_tab_order(): void
    {
        $pdf = self::document();
        $pdf->addPage()->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example');
        $bytes = $pdf->toBytes();

        // Only the page with the link; an explicit tab order would win.
        self::assertSame(1, substr_count($bytes, '/Tabs /S'));
    }

    #[Test]
    public function xmp_values_match_the_info_dictionary(): void
    {
        $pdf = self::document();
        $pdf->metadata(title: 'Annual report', author: 'Finance', creationDate: new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC')));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString("/CreationDate (D:20260304050607+00'00')", $bytes);
        self::assertStringContainsString('<xmp:CreateDate>2026-03-04T05:06:07+00:00</xmp:CreateDate>', $bytes);
        self::assertStringContainsString('<dc:creator><rdf:Seq><rdf:li>Finance</rdf:li></rdf:Seq></dc:creator>', $bytes);
    }

    #[Test]
    public function with_pdfa_both_identifications_share_one_packet(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enablePdfA(new PdfAConfig(self::icc(), part: PdfAConfig::PART_2, conformance: PdfAConfig::CONFORMANCE_U));
        $pdf->enablePdfUa();
        $pdf->metadata(title: 'Annual report');
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertSame(1, substr_count($bytes, '/Type /Metadata'));
        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $bytes);
        // PDF/A only accepts pdfuaid with an extension schema describing it.
        self::assertStringContainsString('<pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>', $bytes);
    }

    #[Test]
    public function pdfa_alone_has_no_extension_schema(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enablePdfA(new PdfAConfig(self::icc(), part: PdfAConfig::PART_2, conformance: PdfAConfig::CONFORMANCE_U));
        $pdf->addPage();

        self::assertStringNotContainsString('pdfaExtension', $pdf->toBytes());
    }

    #[Test]
    public function a_document_without_a_title_throws(): void
    {
        $pdf = PdfDocument::new();
        $pdf->enablePdfUa();
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('title');
        $pdf->toBytes();
    }

    #[Test]
    public function pdfua_and_pdfx_cannot_be_combined(): void
    {
        $pdf = PdfDocument::new();
        $pdf->enablePdfUa();

        $this->expectException(\LogicException::class);
        $pdf->enablePdfX(new PdfXConfig(self::icc()));
    }

    #[Test]
    public function an_untagged_document_has_no_metadata_stream(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->metadata(title: 'Plain');
        $pdf->addPage();

        self::assertStringNotContainsString('/Type /Metadata', $pdf->toBytes());
    }

    private static function document(): PdfDocument
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enablePdfUa();
        $pdf->metadata(title: 'Annual report');
        $pdf->setLang('en');
        $pdf->addPage();

        return $pdf;
    }

    private static function icc(): string
    {
        return dirname(__DIR__, 2).'/resources/icc/sRGB2014.icc';
    }
}
