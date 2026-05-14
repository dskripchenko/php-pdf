<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 216: Document::toStream() и toFile() convenience methods.
 */
final class DocumentStreamTest extends TestCase
{
    private function buildDoc(): Document
    {
        return new Document(new Section([
            new Paragraph([new Run('Stream test content.')]),
        ]));
    }

    #[Test]
    public function to_stream_writes_to_resource(): void
    {
        $doc = $this->buildDoc();
        $mem = fopen('php://memory', 'r+b');
        $written = $doc->toStream($mem, new Engine(compressStreams: false));

        rewind($mem);
        $bytes = stream_get_contents($mem);
        fclose($mem);

        self::assertSame(strlen($bytes), $written);
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    #[Test]
    public function to_stream_output_matches_to_bytes(): void
    {
        $doc = $this->buildDoc();
        $engine = new Engine(compressStreams: false);

        $bytesDirect = $doc->toBytes($engine);

        $mem = fopen('php://memory', 'r+b');
        $doc->toStream($mem, $engine);
        rewind($mem);
        $bytesStream = stream_get_contents($mem);
        fclose($mem);

        self::assertSame($bytesDirect, $bytesStream);
    }

    #[Test]
    public function to_file_writes_pdf(): void
    {
        $doc = $this->buildDoc();
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-stream-');
        try {
            $written = $doc->toFile($tmp, new Engine(compressStreams: false));
            self::assertGreaterThan(0, $written);
            self::assertFileExists($tmp);

            $bytes = file_get_contents($tmp);
            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertStringEndsWith("%%EOF\n", $bytes);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function to_file_output_matches_to_bytes(): void
    {
        $doc = $this->buildDoc();
        $engine = new Engine(compressStreams: false);
        $expected = $doc->toBytes($engine);

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-file-');
        try {
            $doc->toFile($tmp, $engine);
            $actual = file_get_contents($tmp);
            self::assertSame($expected, $actual);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function to_file_invalid_path_throws(): void
    {
        $doc = $this->buildDoc();
        $this->expectException(\RuntimeException::class);
        $doc->toFile('/nonexistent/dir/output.pdf');
    }

    #[Test]
    public function to_stream_respects_xref_and_objstm_flags(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('test')])]),
            useXrefStream: true,
            useObjectStreams: true,
        );

        $mem = fopen('php://memory', 'r+b');
        $doc->toStream($mem, new Engine(compressStreams: false));
        rewind($mem);
        $bytes = stream_get_contents($mem);
        fclose($mem);

        self::assertStringContainsString('/Type /XRef', $bytes);
        self::assertStringContainsString('/Type /ObjStm', $bytes);
    }

    #[Test]
    public function to_stream_propagates_metadata(): void
    {
        // Без Object Streams metadata visible в raw bytes.
        $doc = new Document(
            new Section([new Paragraph([new Run('test')])]),
            metadata: ['Title' => 'Stream Test', 'Author' => 'Tester'],
        );

        $mem = fopen('php://memory', 'r+b');
        $doc->toStream($mem, new Engine(compressStreams: false));
        rewind($mem);
        $bytes = stream_get_contents($mem);
        fclose($mem);

        self::assertStringContainsString('/Title (Stream Test)', $bytes);
        self::assertStringContainsString('/Author (Tester)', $bytes);
    }
}
