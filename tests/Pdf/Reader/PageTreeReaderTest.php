<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P6: page-tree flattening with inheritable-attribute resolution.
 */
final class PageTreeReaderTest extends TestCase
{
    #[Test]
    public function lists_all_leaf_pages_in_order(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage(customDimensionsPt: [200.0, 300.0]);
        $pdf->addPage(customDimensionsPt: [400.0, 500.0]);
        $doc = ReaderDocument::fromBytes($pdf->toBytes());

        $pages = $doc->pages();
        self::assertCount(2, $pages);
        self::assertContainsOnlyInstancesOf(ReaderPage::class, $pages);
    }

    #[Test]
    public function resolves_media_box_geometry(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage(customDimensionsPt: [200.0, 300.0]);
        $doc = ReaderDocument::fromBytes($pdf->toBytes());

        $page = $doc->pages()[0];
        self::assertSame([0.0, 0.0, 200.0, 300.0], $page->mediaBox);
        self::assertSame(200.0, $page->width());
        self::assertSame(300.0, $page->height());
        // CropBox defaults to MediaBox.
        self::assertSame($page->mediaBox, $page->cropBox);
    }

    #[Test]
    public function default_rotate_is_zero(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage();
        $doc = ReaderDocument::fromBytes($pdf->toBytes());
        self::assertSame(0, $doc->pages()[0]->rotate);
    }

    #[Test]
    public function each_page_exposes_resources(): void
    {
        $pdf = new PdfDocument();
        $page = $pdf->addPage();
        $doc = ReaderDocument::fromBytes($pdf->toBytes());
        // A page carries a /Resources dictionary (possibly inherited).
        self::assertNotNull($doc->pages()[0]->resources);
    }

    #[Test]
    public function page_object_numbers_are_recorded(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage();
        $pdf->addPage();
        $doc = ReaderDocument::fromBytes($pdf->toBytes());
        foreach ($doc->pages() as $page) {
            self::assertGreaterThan(0, $page->objectNumber);
        }
    }

    #[Test]
    public function works_through_object_streams(): void
    {
        $pdf = new PdfDocument();
        $pdf->useObjectStreams(true);
        $pdf->addPage(customDimensionsPt: [123.0, 456.0]);
        $doc = ReaderDocument::fromBytes($pdf->toBytes());

        $pages = $doc->pages();
        self::assertCount(1, $pages);
        self::assertSame(123.0, $pages[0]->width());
        self::assertSame(456.0, $pages[0]->height());
    }
}
