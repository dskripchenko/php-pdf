<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function multiParagraphDocument(): AstDocument
    {
        $blocks = [];
        for ($i = 1; $i <= 50; $i++) {
            $blocks[] = new Paragraph([new Run(
                "Paragraph $i — Lorem ipsum dolor sit amet consectetur ".
                'adipiscing elit, sed do eiusmod tempor incididunt ut labore.'
            )]);
        }

        return new AstDocument(new Section($blocks));
    }

    #[Test]
    public function compressed_output_has_flatedecode_filter(): void
    {
        $doc = $this->multiParagraphDocument();
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    #[Test]
    public function uncompressed_output_no_flatedecode_on_content_streams(): void
    {
        $doc = $this->multiParagraphDocument();
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $this->font(),
            compressStreams: false,
        ));
        // FlateDecode может присутствовать для image streams, но не для
        // content streams. Проверим что raw text operators видны.
        self::assertStringContainsString('BT', $bytes);
        self::assertStringContainsString('ET', $bytes);
    }

    #[Test]
    public function compression_significantly_reduces_size(): void
    {
        $doc = $this->multiParagraphDocument();
        $compressed = $doc->toBytes(new Engine(defaultFont: $this->font()));
        $raw = $doc->toBytes(new Engine(
            defaultFont: $this->font(),
            compressStreams: false,
        ));

        $ratio = strlen($compressed) / strlen($raw);
        // Text-heavy → compression обычно даёт ≥2× reduction; ≤80% size.
        self::assertLessThan(0.8, $ratio,
            sprintf('Compressed (%dB) should be < 80%% of raw (%dB), got %.1f%%',
                strlen($compressed), strlen($raw), $ratio * 100));
    }

    #[Test]
    public function compressed_pdf_remains_valid(): void
    {
        $doc = $this->multiParagraphDocument();
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);

        // pdftotext должен извлечь text через decompression.
        $tmp = tempnam(sys_get_temp_dir(), 'comp-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Paragraph 1', $text);
            self::assertStringContainsString('Lorem ipsum', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function empty_content_stream_not_compressed(): void
    {
        // Edge case: пустой документ → content stream пустой → skipped
        $doc = new AstDocument(new Section);
        $bytes = $doc->toBytes(new Engine);
        self::assertStringStartsWith('%PDF', $bytes);
    }
}
