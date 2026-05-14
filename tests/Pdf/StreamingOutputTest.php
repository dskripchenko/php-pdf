<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 129: Streaming PDF output (Writer::toStream / Document::toStream).
 */
final class StreamingOutputTest extends TestCase
{
    private function sampleDoc(): PdfDocument
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Streaming test', 100, 700, StandardFont::Helvetica, 12);

        return $pdf;
    }

    #[Test]
    public function toStream_writes_same_bytes_as_toBytes(): void
    {
        $pdf = $this->sampleDoc();
        $expected = $pdf->toBytes();

        $mem = fopen('php://memory', 'r+b');
        $written = $pdf->toStream($mem);
        rewind($mem);
        $actual = stream_get_contents($mem);
        fclose($mem);

        self::assertSame(strlen($expected), $written);
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function toStream_emits_valid_pdf_header(): void
    {
        $mem = fopen('php://memory', 'r+b');
        $this->sampleDoc()->toStream($mem);
        rewind($mem);
        $head = fread($mem, 8);
        fclose($mem);

        self::assertStringStartsWith('%PDF-', $head);
    }

    #[Test]
    public function toFile_writes_via_streaming(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-stream-');
        try {
            $bytesWritten = $this->sampleDoc()->toFile($tmp);
            self::assertSame(filesize($tmp), $bytesWritten);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function non_resource_arg_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sampleDoc()->toStream('not a resource');
    }

    #[Test]
    public function streaming_with_encrypted_pdf(): void
    {
        $pdf = $this->sampleDoc();
        $pdf->encrypt('password');

        $mem = fopen('php://memory', 'r+b');
        $pdf->toStream($mem);
        rewind($mem);
        $bytes = stream_get_contents($mem);
        fclose($mem);

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('/Encrypt', $bytes);
        self::assertStringNotContainsString('Streaming test', $bytes);
    }

    #[Test]
    public function toFile_returns_byte_count_matching_filesize(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        // Multi-page document для non-trivial size.
        for ($i = 0; $i < 5; $i++) {
            $page = $pdf->addPage();
            $page->showText("Page $i", 100, 700, StandardFont::Helvetica, 12);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-multi-');
        try {
            $written = $pdf->toFile($tmp);
            self::assertSame(filesize($tmp), $written);
            self::assertGreaterThan(0, $written);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function memory_stream_can_accumulate_then_be_read(): void
    {
        $mem = fopen('php://temp', 'r+b');
        $bytesWritten = $this->sampleDoc()->toStream($mem);
        fseek($mem, 0, SEEK_END);
        self::assertSame($bytesWritten, ftell($mem));
        rewind($mem);
        $first = fread($mem, 5);
        fclose($mem);
        self::assertSame('%PDF-', $first);
    }
}
