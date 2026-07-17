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

/**
 * Every stream's declared /Length must count exactly the stream data:
 * the bytes after the EOL following `stream`, excluding the EOL delimiter
 * before `endstream` (ISO 32000 §7.3.8, PDF/A clause 6.1.7). veraPDF
 * flags off-by-one lengths, e.g. a ToUnicode CMap whose own trailing
 * newline was absorbed into /Length.
 */
final class StreamLengthTest extends TestCase
{
    private function assertStreamLengthsExact(string $bytes): void
    {
        $checked = 0;
        $offset = 0;
        while (preg_match(
            '/<<(?<dict>[^>]*?)\/Length (?<len>\d+)(?<rest>[^>]*?)>>\s*stream\r?\n/s',
            $bytes,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset,
        ) === 1) {
            $dataStart = $m[0][1] + strlen($m[0][0]);
            $offset = $dataStart;
            $declared = (int) $m['len'][0];

            $after = substr($bytes, $dataStart + $declared, 11);
            self::assertMatchesRegularExpression(
                '/^(\r\n|\r|\n)endstream/',
                $after,
                sprintf(
                    'Stream at byte %d: /Length %d must be followed by EOL + endstream, got %s',
                    $dataStart,
                    $declared,
                    var_export($after, true),
                ),
            );
            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'No streams found in output');
    }

    private function document(): AstDocument
    {
        return new AstDocument(new Section([
            new Paragraph([new Run('Stream length check — офис, floor, difficult.')]),
        ]));
    }

    private function engine(bool $compress): Engine
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new Engine(
            defaultFont: new PdfFont(TtfFile::fromFile($path)),
            compressStreams: $compress,
        );
    }

    #[Test]
    public function compressed_output_stream_lengths_are_exact(): void
    {
        $this->assertStreamLengthsExact($this->document()->toBytes($this->engine(true)));
    }

    #[Test]
    public function uncompressed_output_stream_lengths_are_exact(): void
    {
        $this->assertStreamLengthsExact($this->document()->toBytes($this->engine(false)));
    }
}
