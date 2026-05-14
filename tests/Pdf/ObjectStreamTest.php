<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Writer;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 214: Object Streams (PDF 1.5+) tests.
 */
final class ObjectStreamTest extends TestCase
{
    private function buildDoc(bool $useObjStm = false): Document
    {
        return new Document(
            new Section([
                new Paragraph([new Run('Object stream test content.')]),
                new Paragraph([new Run('Second paragraph.')]),
            ]),
            useObjectStreams: $useObjStm,
        );
    }

    #[Test]
    public function classic_default_no_obj_stm(): void
    {
        $doc = $this->buildDoc(useObjStm: false);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // No /Type /ObjStm object.
        self::assertStringNotContainsString('/Type /ObjStm', $bytes);
    }

    #[Test]
    public function object_stream_emitted_when_enabled(): void
    {
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /Type /ObjStm object present.
        self::assertStringContainsString('/Type /ObjStm', $bytes);
        // /N (count) + /First (offset) entries.
        self::assertMatchesRegularExpression('@/Type /ObjStm /N \d+ /First \d+@', $bytes);
    }

    #[Test]
    public function object_stream_auto_enables_xref_stream(): void
    {
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // XRef stream должен быть active (Object Streams requires it).
        self::assertStringContainsString('/Type /XRef', $bytes);
        // Classic xref ABSENT.
        self::assertStringNotContainsString("\nxref\n", $bytes);
    }

    #[Test]
    public function object_stream_bumps_pdf_version_to_1_5(): void
    {
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@^%PDF-1\.[567]\n@', $bytes);
    }

    #[Test]
    public function object_stream_smaller_output_for_large_doc(): void
    {
        // Generate many paragraphs → many small objects (pages, resources).
        $blocks = [];
        for ($i = 0; $i < 50; $i++) {
            $blocks[] = new Paragraph([new Run("Paragraph $i")]);
        }

        $docPlain = new Document(new Section($blocks));
        $docOptimized = new Document(new Section($blocks), useObjectStreams: true);

        $plainBytes = $docPlain->toBytes(new Engine(compressStreams: false));
        $optBytes = $docOptimized->toBytes(new Engine(compressStreams: false));

        // Object streams должен дать smaller output.
        self::assertLessThan(strlen($plainBytes), strlen($optBytes));
    }

    #[Test]
    public function object_stream_with_compressed_content_streams(): void
    {
        // Combine с FlateDecode на content streams — both optimizations active.
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: true));

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
        self::assertStringContainsString('/Type /ObjStm', $bytes);
    }

    #[Test]
    public function object_stream_xref_has_type_2_entries(): void
    {
        // Decode XRef stream и проверить что content contains type-2 entries
        // (first byte = 0x02 для compressed objects).
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Extract all FlateDecode streams; the last one должен быть XRef stream.
        preg_match_all('@stream\n(.*?)\nendstream@s', $bytes, $matches);
        self::assertNotEmpty($matches[1]);

        // Find XRef stream — должен быть после /Type /XRef dict.
        $xrefDictPos = strpos($bytes, '/Type /XRef');
        self::assertNotFalse($xrefDictPos);
        $afterDict = substr($bytes, $xrefDictPos);
        preg_match('@stream\n(.*?)\nendstream@s', $afterDict, $m);
        self::assertNotEmpty($m[1]);

        $decompressed = @gzuncompress($m[1]);
        self::assertIsString($decompressed);

        // Entries are 7 bytes each (W=[1 4 2]). Check каждого entry type byte.
        $hasType2 = false;
        for ($i = 0; $i + 7 <= strlen($decompressed); $i += 7) {
            if (ord($decompressed[$i]) === 2) {
                $hasType2 = true;
                break;
            }
        }
        self::assertTrue($hasType2, 'XRef stream должен содержать type-2 entries для packed objects');
    }

    #[Test]
    public function object_stream_disabled_when_encryption(): void
    {
        // Encryption uses low-level API; need to manually exercise that path.
        // Build doc → render → encrypt — Object Streams путь должен быть skipped.
        // Just verify через direct flag combination not engaging ObjStm output.
        $engine = new Engine(compressStreams: false);
        $pdf = $engine->render($this->buildDoc(useObjStm: true));
        $pdf->encrypt('test-password');
        $bytes = $pdf->toBytes();

        // Encryption disables Object Streams.
        self::assertStringNotContainsString('/Type /ObjStm', $bytes);
    }

    #[Test]
    public function pdf_structure_valid_with_object_streams(): void
    {
        $doc = $this->buildDoc(useObjStm: true);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString("%%EOF\n", $bytes);
        // startxref должен point внутри document.
        preg_match('@startxref\n(\d+)\n@', $bytes, $m);
        $offset = (int) $m[1];
        self::assertLessThan(strlen($bytes), $offset);
        self::assertGreaterThan(0, $offset);
    }

    #[Test]
    public function low_level_writer_direct_object_stream(): void
    {
        $writer = new Writer('1.5', useXrefStream: true, useObjectStreams: true);
        $catalogId = $writer->reserveObject();
        $pagesId = $writer->reserveObject();
        $infoId = $writer->reserveObject();
        $writer->setObject($catalogId, '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>');
        $writer->setObject($pagesId, '<< /Type /Pages /Kids [] /Count 0 >>');
        $writer->setObject($infoId, '<< /Producer (test) /CreationDate (D:20260514120000Z) >>');
        $writer->setRoot($catalogId);
        $writer->setInfo($infoId);
        $bytes = $writer->toBytes();

        self::assertStringStartsWith("%PDF-1.5\n", $bytes);
        self::assertStringContainsString('/Type /ObjStm', $bytes);
        self::assertStringContainsString('/N 3', $bytes); // 3 packed objects
    }

    #[Test]
    public function object_stream_skipped_when_too_few_objects(): void
    {
        // Writer с very few objects — packing not worth it, fall back to direct.
        $writer = new Writer('1.5', useXrefStream: true, useObjectStreams: true);
        $catalogId = $writer->reserveObject();
        $pagesId = $writer->reserveObject();
        $writer->setObject($catalogId, '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>');
        $writer->setObject($pagesId, '<< /Type /Pages /Kids [] /Count 0 >>');
        $writer->setRoot($catalogId);
        $bytes = $writer->toBytes();

        // Only 2 packable < threshold 3 → no Object Stream.
        self::assertStringNotContainsString('/Type /ObjStm', $bytes);
    }
}
