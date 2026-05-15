<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Page;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 226: Page /Tabs entry — form field tab navigation order.
 */
final class PageTabsTest extends TestCase
{
    #[Test]
    public function default_no_tabs_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        // No /Tabs entry by default.
        self::assertStringNotContainsString('/Tabs', $bytes);
    }

    #[Test]
    public function row_order_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTabOrder('R');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Tabs /R', $bytes);
    }

    #[Test]
    public function column_order_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTabOrder('C');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Tabs /C', $bytes);
    }

    #[Test]
    public function structure_order_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTabOrder('S');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Tabs /S', $bytes);
    }

    #[Test]
    public function rejects_invalid_tab_order(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();

        $this->expectException(\InvalidArgumentException::class);
        $page->setTabOrder('X');
    }

    #[Test]
    public function tab_order_getter(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        self::assertNull($page->tabOrder());

        $page->setTabOrder('S');
        self::assertSame('S', $page->tabOrder());
    }

    #[Test]
    public function chainable_setter(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $result = $page->setTabOrder('R');

        self::assertSame($page, $result, 'setTabOrder should return self');
    }

    #[Test]
    public function per_page_independent(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page1 = $pdf->addPage();
        $page1->setTabOrder('R');
        $page2 = $pdf->addPage();
        $page2->setTabOrder('S');
        $page3 = $pdf->addPage(); // no tab order
        $bytes = $pdf->toBytes();

        // Both /R и /S present.
        self::assertStringContainsString('/Tabs /R', $bytes);
        self::assertStringContainsString('/Tabs /S', $bytes);
    }
}
