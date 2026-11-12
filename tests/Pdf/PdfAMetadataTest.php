<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PDF/A (ISO 19005-1 §6.7.3, -2 §6.6.2.3) requires every /Info entry to match
 * its XMP property, so the XMP packet is built from the same values as /Info.
 */
final class PdfAMetadataTest extends TestCase
{
    private string $iccPath = __DIR__.'/../fixtures/dummy.icc';

    #[Test]
    public function xmp_carries_the_info_dictionary_values(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(
            title: 'Quarterly Report',
            author: 'Finance Team',
            subject: 'Results for Q3',
            keywords: 'finance, q3',
            creator: 'Report Builder',
            producer: 'Acme PDF',
            creationDate: new \DateTimeImmutable('2026-03-02 10:15:30', new \DateTimeZone('UTC')),
        );
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $bytes = $pdf->toBytes();
        $xmp = self::xmp($bytes);

        self::assertStringContainsString('/Producer (Acme PDF)', $bytes);
        self::assertStringContainsString('<pdf:Producer>Acme PDF</pdf:Producer>', $xmp);
        self::assertStringContainsString('<xmp:CreatorTool>Report Builder</xmp:CreatorTool>', $xmp);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Quarterly Report</rdf:li>', $xmp);
        self::assertStringContainsString('<rdf:li>Finance Team</rdf:li>', $xmp);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Results for Q3</rdf:li>', $xmp);
        self::assertStringContainsString('<pdf:Keywords>finance, q3</pdf:Keywords>', $xmp);
        self::assertStringContainsString('/CreationDate (D:20260302101530+00\'00\')', $bytes);
        self::assertStringContainsString('<xmp:CreateDate>2026-03-02T10:15:30+00:00</xmp:CreateDate>', $xmp);
    }

    #[Test]
    public function default_producer_and_date_match_between_info_and_xmp(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $bytes = $pdf->toBytes();
        $xmp = self::xmp($bytes);

        self::assertStringContainsString('/Producer (dskripchenko/php-pdf)', $bytes);
        self::assertStringContainsString('<pdf:Producer>dskripchenko/php-pdf</pdf:Producer>', $xmp);
        self::assertSame(1, preg_match("@/CreationDate \(D:(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)\+00'00'\)@", $bytes, $m));
        self::assertStringContainsString("<xmp:CreateDate>$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]+00:00</xmp:CreateDate>", $xmp);
    }

    #[Test]
    public function the_xmp_is_the_same_on_every_render_of_a_dated_document(): void
    {
        $render = function (): string {
            $pdf = PdfDocument::new(compressStreams: false);
            $pdf->addPage();
            $pdf->metadata(creationDate: new \DateTimeImmutable('2026-03-02 10:15:30', new \DateTimeZone('UTC')));
            $pdf->enablePdfA(new PdfAConfig($this->iccPath));

            return self::xmp($pdf->toBytes());
        };

        $first = $render();
        sleep(1);
        self::assertSame($first, $render());
    }

    #[Test]
    public function a_creation_date_outside_utc_is_written_as_the_same_instant(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(creationDate: new \DateTimeImmutable('2026-03-02 10:15:30', new \DateTimeZone('-07:00')));
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/CreationDate (D:20260302171530+00\'00\')', $bytes);
        self::assertStringContainsString('<xmp:CreateDate>2026-03-02T17:15:30+00:00</xmp:CreateDate>', self::xmp($bytes));
    }

    #[Test]
    public function the_config_title_and_author_fill_info_when_metadata_has_none(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, title: 'Archive Title', author: 'Archivist'));
        $bytes = $pdf->toBytes();
        $xmp = self::xmp($bytes);

        self::assertStringContainsString('/Title (Archive Title)', $bytes);
        self::assertStringContainsString('/Author (Archivist)', $bytes);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Archive Title</rdf:li>', $xmp);
        self::assertStringContainsString('<rdf:li>Archivist</rdf:li>', $xmp);
    }

    #[Test]
    public function metadata_title_wins_over_the_config_title_in_both_places(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(title: 'Document Title');
        $pdf->enablePdfA(new PdfAConfig($this->iccPath, title: 'Config Title'));
        $bytes = $pdf->toBytes();
        $xmp = self::xmp($bytes);

        self::assertStringContainsString('/Title (Document Title)', $bytes);
        self::assertStringNotContainsString('Config Title', $bytes);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Document Title</rdf:li>', $xmp);
    }

    #[Test]
    public function empty_entries_are_left_out_of_the_xmp(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));
        $xmp = self::xmp($pdf->toBytes());

        self::assertStringNotContainsString('<dc:title>', $xmp);
        self::assertStringNotContainsString('<dc:creator>', $xmp);
        self::assertStringNotContainsString('<xmp:CreatorTool>', $xmp);
    }

    #[Test]
    public function xml_special_characters_are_escaped(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(title: 'Smith & Sons <draft>');
        $pdf->enablePdfA(new PdfAConfig($this->iccPath));

        self::assertStringContainsString('Smith &amp; Sons &lt;draft&gt;', self::xmp($pdf->toBytes()));
    }

    private static function xmp(string $bytes): string
    {
        self::assertSame(1, preg_match('@<x:xmpmeta.*?</x:xmpmeta>@s', $bytes, $m), 'no XMP packet');

        return $m[0];
    }
}
