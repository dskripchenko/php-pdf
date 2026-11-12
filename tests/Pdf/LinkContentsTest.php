<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A link's description (/Contents) is what assistive technology reads for
 * it. PDF/UA requires one on every link annotation (ISO 14289-1 §7.18.5).
 */
final class LinkContentsTest extends TestCase
{
    #[Test]
    public function every_kind_of_link_takes_a_description(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example website');
        $page->addInternalLink(10, 30, 50, 10, 'target', 'Go to the summary');
        $page->addNamedActionLink(10, 50, 50, 10, 'NextPage', 'Next page');
        $page->addJavaScriptLink(10, 70, 50, 10, 'app.alert(1)', 'Show a message');
        $page->addLaunchLink(10, 90, 50, 10, 'other.pdf', 'Open the appendix');
        $pdf->registerDestination('target', $page, 0, 0);
        $bytes = $pdf->toBytes();

        foreach (['Example website', 'Go to the summary', 'Next page', 'Show a message', 'Open the appendix'] as $text) {
            self::assertStringContainsString('/Contents ('.$text.')', $bytes);
        }
    }

    #[Test]
    public function a_link_without_a_description_has_no_contents_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addExternalLink(10, 10, 50, 10, 'https://example.com');

        preg_match('@<< /Type /Annot /Subtype /Link\b.*?>>\s*endobj@s', $pdf->toBytes(), $m);
        self::assertNotEmpty($m);
        self::assertStringNotContainsString('/Contents', $m[0]);
    }

    #[Test]
    public function a_tagged_link_keeps_its_struct_parent_beside_the_description(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $pdf->addPage()->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example');

        self::assertMatchesRegularExpression('@/Contents \(Example\) /StructParent \d+@', $pdf->toBytes());
    }

    #[Test]
    public function a_non_ascii_description_is_written_as_a_text_string(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Café menu');

        // UTF-16BE with BOM, as every PDF text string outside PDFDocEncoding.
        self::assertStringContainsString('/Contents <FEFF', $pdf->toBytes());
    }
}
