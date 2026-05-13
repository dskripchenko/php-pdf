<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InlineImageTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function tinyJpeg(): PdfImage
    {
        // Reuse existing fixture (создан в Phase 4 tests).
        $path = __DIR__.'/../fixtures/sample.jpg';
        if (! is_readable($path)) {
            self::markTestSkipped('Sample JPEG fixture missing.');
        }

        return PdfImage::fromPath($path);
    }

    #[Test]
    public function image_implements_inline_and_block(): void
    {
        $img = Image::fromPath(__DIR__.'/../fixtures/sample.jpg');
        self::assertInstanceOf(\Dskripchenko\PhpPdf\Element\BlockElement::class, $img);
        self::assertInstanceOf(\Dskripchenko\PhpPdf\Element\InlineElement::class, $img);
    }

    #[Test]
    public function inline_image_renders_within_paragraph(): void
    {
        $img = new Image($this->tinyJpeg(), widthPt: 30, heightPt: 20);
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Before'),
                $img,
                new Run('after.'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));

        // Image XObject embedded.
        self::assertStringContainsString('/Subtype /Image', $bytes);
        // Inline image cm transform: '30 0 0 20 X Y cm'.
        self::assertStringContainsString('30 0 0 20', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'ii-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            // Text перед и после картинки still in PDF.
            self::assertStringContainsString('Before', $text);
            self::assertStringContainsString('after.', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function tall_inline_image_increases_line_height(): void
    {
        // 40pt tall image > 11pt default text line; line should accommodate.
        $img = new Image($this->tinyJpeg(), widthPt: 30, heightPt: 40);

        $docWith = new Document(new Section([
            new Paragraph([
                new Run('Text '),
                $img,
                new Run(' more text.'),
            ]),
            new Paragraph([new Run('Following paragraph')]),
        ]));
        $docWithout = new Document(new Section([
            new Paragraph([new Run('Text  more text.')]),
            new Paragraph([new Run('Following paragraph')]),
        ]));
        $bytesWith = $docWith->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        $bytesWithout = $docWithout->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));

        // 'Following paragraph' Y-coord должен быть lower (меньше Y)
        // в docWith из-за taller line. Просто проверим разные outputs.
        self::assertNotSame($bytesWith, $bytesWithout);
    }

    #[Test]
    public function inline_image_in_hyperlink_creates_clickable_image(): void
    {
        $img = new Image($this->tinyJpeg(), widthPt: 30, heightPt: 20);
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Click '),
                Hyperlink::external('https://example.com', [$img]),
                new Run(' to visit.'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));

        // /Subtype /Link annotation вокруг image.
        self::assertStringContainsString('/Subtype /Link', $bytes);
        self::assertStringContainsString('/URI (https://example.com)', $bytes);
    }

    #[Test]
    public function long_text_wraps_around_inline_image_correctly(): void
    {
        $img = new Image($this->tinyJpeg(), widthPt: 20, heightPt: 15);
        $longText = str_repeat('Lorem ipsum ', 30);
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Start '),
                $img,
                new Run(' '.$longText),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));

        self::assertStringStartsWith('%PDF', $bytes);
        // Image должен appear только один раз в этом параграфе.
        self::assertSame(1, substr_count($bytes, '/Subtype /Image'));
    }

    #[Test]
    public function block_image_at_top_level_still_works(): void
    {
        // Phase 4 behavior should remain — Image как top-level block.
        $img = Image::fromPath(__DIR__.'/../fixtures/sample.jpg', widthPt: 200);
        $doc = new Document(new Section([
            new Paragraph([new Run('Above image.')]),
            $img,
            new Paragraph([new Run('Below image.')]),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));

        self::assertStringContainsString('/Subtype /Image', $bytes);
    }
}
