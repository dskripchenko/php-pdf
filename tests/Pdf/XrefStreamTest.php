<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDoc;
use Dskripchenko\PhpPdf\Pdf\Writer;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 208: XRef stream cross-reference table tests.
 */
final class XrefStreamTest extends TestCase
{
    private function buildDoc(bool $useXref = false): Document
    {
        return new Document(
            new Section([
                new Paragraph([new Run('XRef stream test content.')]),
            ]),
            useXrefStream: $useXref,
        );
    }

    #[Test]
    public function classic_xref_default(): void
    {
        $doc = $this->buildDoc();
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Classic xref keyword present.
        self::assertStringContainsString("\nxref\n", $bytes);
        self::assertStringContainsString("\ntrailer\n", $bytes);
        // No XRef stream object.
        self::assertStringNotContainsString('/Type /XRef', $bytes);
    }

    #[Test]
    public function xref_stream_replaces_classic_when_enabled(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // XRef stream object present.
        self::assertStringContainsString('/Type /XRef', $bytes);
        // Classic xref/trailer NOT present (XRef stream replaces them).
        self::assertStringNotContainsString("\nxref\n", $bytes);
        self::assertStringNotContainsString("\ntrailer\n", $bytes);
    }

    #[Test]
    public function xref_stream_contains_w_array(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /W [1 4 2] field widths.
        self::assertStringContainsString('/W [1 4 2]', $bytes);
    }

    #[Test]
    public function xref_stream_contains_flate_filter(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // FlateDecode applied к stream content.
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    #[Test]
    public function xref_stream_contains_size_and_root(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /Size and /Root references.
        self::assertMatchesRegularExpression('@/Size \d+@', $bytes);
        self::assertMatchesRegularExpression('@/Root \d+ 0 R@', $bytes);
    }

    #[Test]
    public function xref_stream_has_startxref_pointer(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // startxref keyword + numeric offset present.
        self::assertMatchesRegularExpression('@\nstartxref\n\d+\n%%EOF\n@', $bytes);
    }

    #[Test]
    public function xref_stream_output_smaller_than_classic_for_large_doc(): void
    {
        // Many objects → xref stream advantage grows (10 bytes per entry в
        // classic vs ~3-4 bytes compressed в stream).
        $blocks = [];
        for ($i = 0; $i < 100; $i++) {
            $blocks[] = new Paragraph([new Run("Paragraph $i with some content к pad the object count.")]);
        }
        $doc1 = new Document(new Section($blocks));
        $doc1Bytes = $doc1->toBytes(new Engine(compressStreams: false));

        $doc2 = new Document(new Section($blocks), useXrefStream: true);
        $doc2Bytes = $doc2->toBytes(new Engine(compressStreams: false));

        // XRef stream version should be smaller (or at least not larger).
        self::assertLessThan(strlen($doc1Bytes), strlen($doc2Bytes));
    }

    #[Test]
    public function xref_stream_first_entry_encodes_free_record(): void
    {
        // Entry 0 = type(0) + offset(0) + gen(65535).
        // After FlateDecode decompress, first 7 bytes should be:
        //   0x00 0x00 0x00 0x00 0x00 0xFF 0xFF
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Find LAST stream (XRef stream comes after font streams etc).
        preg_match_all('@stream\n(.*?)\nendstream@s', $bytes, $matches);
        self::assertNotEmpty($matches[1], 'No streams found');
        $xrefRawStream = end($matches[1]);

        $decompressed = @gzuncompress($xrefRawStream);
        self::assertIsString($decompressed, 'XRef stream должен FlateDecode-decompress');
        self::assertGreaterThanOrEqual(7, strlen($decompressed));
        // First entry: 0x00 + offset 0 (4 bytes) + gen 0xFFFF (2 bytes).
        self::assertSame(0, ord($decompressed[0]));
        self::assertSame(0, ord($decompressed[1]));
        self::assertSame(0, ord($decompressed[2]));
        self::assertSame(0, ord($decompressed[3]));
        self::assertSame(0, ord($decompressed[4]));
        self::assertSame(0xFF, ord($decompressed[5]));
        self::assertSame(0xFF, ord($decompressed[6]));
    }

    #[Test]
    public function xref_stream_with_compressed_content_streams(): void
    {
        // Combine с FlateDecode content streams — exercise both filter paths.
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: true));

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
        self::assertStringContainsString('/Type /XRef', $bytes);
    }

    #[Test]
    public function xref_stream_with_metadata(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('content')])]),
            metadata: ['Title' => 'XRef Test', 'Author' => 'Test Author'],
            useXrefStream: true,
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /Info reference в XRef dictionary.
        self::assertMatchesRegularExpression('@/Type /XRef.*?/Info \d+ 0 R@s', $bytes);
    }

    #[Test]
    public function pdf_structure_valid_with_xref_stream(): void
    {
        $doc = $this->buildDoc(useXref: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Header + binary marker.
        self::assertStringStartsWith('%PDF-', $bytes);
        // EOF marker.
        self::assertStringContainsString("%%EOF\n", $bytes);
        // XRef object id + offset coherent.
        self::assertMatchesRegularExpression('@startxref\n(\d+)\n@', $bytes);
        preg_match('@startxref\n(\d+)\n@', $bytes, $m);
        $offset = (int) $m[1];
        // Offset должен point внутри document.
        self::assertLessThan(strlen($bytes), $offset);
        self::assertGreaterThan(0, $offset);
        // At offset должен start "N 0 obj" where N = XRef stream id.
        self::assertMatchesRegularExpression(
            '@^\d+ 0 obj@',
            substr($bytes, $offset, 30),
        );
    }

    // ---- low-level Writer tests ----

    #[Test]
    public function writer_pdf_version_bumped_к_1_5_when_xref_stream_enabled(): void
    {
        $pdf = new PdfDoc;
        $pdf->pdfVersion('1.4');
        $pdf->useXrefStream();
        $bytes = $pdf->toBytes();

        // Header bumped from 1.4 к 1.5.
        self::assertStringStartsWith("%PDF-1.5\n", $bytes);
    }

    #[Test]
    public function writer_pdf_version_preserved_when_higher(): void
    {
        $pdf = new PdfDoc;
        $pdf->pdfVersion('1.7');
        $pdf->useXrefStream();
        $bytes = $pdf->toBytes();

        self::assertStringStartsWith("%PDF-1.7\n", $bytes);
    }

    #[Test]
    public function writer_direct_xref_stream_compact_dict(): void
    {
        // Direct test of Writer using XRef stream — minimal case.
        $writer = new Writer('1.5', useXrefStream: true);
        $catalogId = $writer->reserveObject();
        $pagesId = $writer->reserveObject();
        $writer->setObject($catalogId, '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>');
        $writer->setObject($pagesId, '<< /Type /Pages /Kids [] /Count 0 >>');
        $writer->setRoot($catalogId);
        $bytes = $writer->toBytes();

        self::assertStringStartsWith("%PDF-1.5\n", $bytes);
        self::assertStringContainsString('/Type /XRef', $bytes);
        self::assertStringContainsString('/W [1 4 2]', $bytes);
        // No classic xref keyword.
        self::assertStringNotContainsString("\nxref\n", $bytes);
    }
}
