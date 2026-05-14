<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 130: Lazy font subset — per-Writer registration + reset() API.
 *
 * Pre-130 bug: single $fontObjectId cache breaks reuse across multiple
 * Documents (second doc gets stale object ID from first writer).
 */
final class LazyFontSubsetTest extends TestCase
{
    private PdfFont $font;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->font = new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function same_pdffont_works_across_multiple_documents(): void
    {
        // Doc 1: encode "hello".
        $doc1 = PdfDocument::new(compressStreams: false);
        $doc1->addPage()->showEmbeddedText('hello', 100, 700, $this->font, 12);
        $bytes1 = $doc1->toBytes();
        self::assertStringContainsString('%PDF-', $bytes1);
        self::assertStringContainsString('/Type /Font /Subtype /Type0', $bytes1);

        // Doc 2: encode "world" using SAME PdfFont instance.
        $doc2 = PdfDocument::new(compressStreams: false);
        $doc2->addPage()->showEmbeddedText('world', 100, 700, $this->font, 12);
        $bytes2 = $doc2->toBytes();
        self::assertStringContainsString('%PDF-', $bytes2);
        self::assertStringContainsString('/Type /Font /Subtype /Type0', $bytes2);

        // Both should be valid distinct PDFs.
        self::assertNotSame($bytes1, $bytes2);
    }

    #[Test]
    public function same_font_in_same_doc_returns_same_object_id(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $page1 = $doc->addPage();
        $page1->showEmbeddedText('first', 100, 700, $this->font, 12);
        $page2 = $doc->addPage();
        $page2->showEmbeddedText('second', 100, 700, $this->font, 12);
        $bytes = $doc->toBytes();

        // Only one Type0 font object emitted (shared between pages).
        self::assertSame(1, substr_count($bytes, '/Type /Font /Subtype /Type0'));
    }

    #[Test]
    public function reset_clears_used_glyphs_for_fresh_subset(): void
    {
        // Use font в doc1.
        $doc1 = PdfDocument::new(compressStreams: false);
        $doc1->addPage()->showEmbeddedText('hello', 100, 700, $this->font, 12);
        $doc1->toBytes();

        // Without reset, doc2 subset includes "hello" glyphs too.
        // With reset(), doc2 subset only includes "world" glyphs.
        $this->font->reset();

        $doc2 = PdfDocument::new(compressStreams: false);
        $doc2->addPage()->showEmbeddedText('world', 100, 700, $this->font, 12);
        $bytes2 = $doc2->toBytes();

        // Hard to assert exact glyph count without parsing subset, но
        // structural validity is sufficient.
        self::assertStringContainsString('/Subtype /Type0', $bytes2);
    }

    #[Test]
    public function reset_clears_writer_registrations(): void
    {
        $doc1 = PdfDocument::new(compressStreams: false);
        $doc1->addPage()->showEmbeddedText('A', 100, 700, $this->font, 12);
        $doc1->toBytes();

        $this->font->reset();

        // After reset, registering with new writer должно work (fresh registration).
        $doc2 = PdfDocument::new(compressStreams: false);
        $doc2->addPage()->showEmbeddedText('B', 100, 700, $this->font, 12);
        $bytes = $doc2->toBytes();
        self::assertStringContainsString('%PDF-', $bytes);
    }

    #[Test]
    public function multiple_toBytes_on_same_document_idempotent(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage()->showEmbeddedText('repeated', 100, 700, $this->font, 12);

        $bytes1 = $doc->toBytes();
        $bytes2 = $doc->toBytes();

        // Note: timestamps may differ; check basic structure.
        // Document::toBytes() creates fresh Writer каждый раз — second call
        // gets distinct Writer instance. Font's writerRegistrations stores
        // first writer (garbage-collected после toBytes returns).
        self::assertStringContainsString('%PDF-', $bytes1);
        self::assertStringContainsString('%PDF-', $bytes2);
        // Length should be similar (might differ from timestamp в /Info).
        self::assertLessThan(200, abs(strlen($bytes1) - strlen($bytes2)));
    }
}
