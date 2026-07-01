<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfParseException;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P2: classic xref + trailer + lazy resolver, exercised against our own
 * (classic-xref) Writer output.
 */
final class ReaderDocumentTest extends TestCase
{
    private function makePdf(int $pageCount): string
    {
        $pdf = new PdfDocument();
        for ($i = 0; $i < $pageCount; $i++) {
            $pdf->addPage();
        }
        return $pdf->toBytes();
    }

    #[Test]
    public function reads_page_count_from_own_output(): void
    {
        foreach ([1, 2, 5] as $n) {
            $doc = ReaderDocument::fromBytes($this->makePdf($n));
            self::assertSame($n, $doc->pageCount(), "expected {$n} pages");
        }
    }

    #[Test]
    public function catalog_type_is_catalog(): void
    {
        $doc = ReaderDocument::fromBytes($this->makePdf(1));
        $catalog = $doc->catalog();
        self::assertInstanceOf(PdfDictionary::class, $catalog);
        $type = $doc->deref($catalog->get('Type'));
        self::assertInstanceOf(PdfName::class, $type);
        self::assertSame('Catalog', $type->value);
    }

    #[Test]
    public function trailer_exposes_root_and_size(): void
    {
        $doc = ReaderDocument::fromBytes($this->makePdf(3));
        $trailer = $doc->trailer();
        self::assertTrue($trailer->has('Root'));
        self::assertTrue($trailer->has('Size'));
        self::assertGreaterThan(0, $doc->deref($trailer->get('Size')));
    }

    #[Test]
    public function page_tree_root_type_is_pages(): void
    {
        $doc = ReaderDocument::fromBytes($this->makePdf(2));
        $pages = $doc->deref($doc->catalog()->get('Pages'));
        self::assertInstanceOf(PdfDictionary::class, $pages);
        $type = $doc->deref($pages->get('Type'));
        self::assertInstanceOf(PdfName::class, $type);
        self::assertSame('Pages', $type->value);
    }

    #[Test]
    public function missing_startxref_throws(): void
    {
        $this->expectException(PdfParseException::class);
        ReaderDocument::fromBytes('%PDF-1.7\nnot a real pdf\n');
    }
}
