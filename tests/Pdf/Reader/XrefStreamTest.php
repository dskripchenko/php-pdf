<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P4: cross-reference streams (§7.5.8) and object streams (§7.5.7),
 * exercised against php-pdf output that uses them.
 */
final class XrefStreamTest extends TestCase
{
    private function makePdf(int $pageCount, bool $objStm): string
    {
        $pdf = new PdfDocument();
        if ($objStm) {
            $pdf->useObjectStreams(true); // auto-enables xref streams
        } else {
            $pdf->useXrefStream(true);
        }
        for ($i = 0; $i < $pageCount; $i++) {
            $pdf->addPage();
        }
        return $pdf->toBytes();
    }

    #[Test]
    public function reads_page_count_from_xref_stream_output(): void
    {
        foreach ([1, 3, 6] as $n) {
            $doc = ReaderDocument::fromBytes($this->makePdf($n, objStm: false));
            self::assertSame($n, $doc->pageCount(), "xref-stream, {$n} pages");
        }
    }

    #[Test]
    public function reads_page_count_from_object_stream_output(): void
    {
        foreach ([1, 3, 6] as $n) {
            $doc = ReaderDocument::fromBytes($this->makePdf($n, objStm: true));
            self::assertSame($n, $doc->pageCount(), "object-stream, {$n} pages");
        }
    }

    #[Test]
    public function catalog_resolves_through_object_stream(): void
    {
        // With object streams the catalog itself is typically compressed.
        $doc = ReaderDocument::fromBytes($this->makePdf(2, objStm: true));
        $catalog = $doc->catalog();
        self::assertInstanceOf(PdfDictionary::class, $catalog);
        $type = $doc->deref($catalog->get('Type'));
        self::assertInstanceOf(PdfName::class, $type);
        self::assertSame('Catalog', $type->value);
    }

    #[Test]
    public function page_tree_type_via_xref_stream(): void
    {
        $doc = ReaderDocument::fromBytes($this->makePdf(4, objStm: false));
        $pages = $doc->deref($doc->catalog()->get('Pages'));
        self::assertInstanceOf(PdfDictionary::class, $pages);
        self::assertSame('Pages', $doc->deref($pages->get('Type'))->value);
        self::assertSame(4, $doc->deref($pages->get('Count')));
    }
}
