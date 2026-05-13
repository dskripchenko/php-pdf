<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkRenderTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function external_link_emits_uri_action(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Visit '),
                Hyperlink::external('https://example.com', [new Run('our website')]),
                new Run(' for details.'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('/Subtype /Link', $bytes);
        self::assertStringContainsString('/URI (https://example.com)', $bytes);
    }

    #[Test]
    public function internal_link_emits_dest_reference(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Bookmark('chapter1', [new Run('Chapter 1')]),
            ]),
            new Paragraph([
                Hyperlink::internal('chapter1', [new Run('See chapter 1')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('/Subtype /Link', $bytes);
        self::assertStringContainsString('/Dest (chapter1)', $bytes);
        // Named-dests tree должен быть emit'ed в Catalog.
        self::assertStringContainsString('/Names', $bytes);
        self::assertStringContainsString('/Dests', $bytes);
        self::assertStringContainsString('(chapter1)', $bytes);
    }

    #[Test]
    public function bookmark_registers_destination_at_paragraph_y(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Bookmark('top', [new Run('Top')])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // /XYZ <x> <y> 0 — destination format.
        self::assertMatchesRegularExpression('@\[\d+ 0 R /XYZ [\d.]+ [\d.]+ 0\]@', $bytes);
    }

    #[Test]
    public function multiple_hyperlinks_produce_multiple_annotations(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                Hyperlink::external('https://a.test', [new Run('A')]),
                new Run(' '),
                Hyperlink::external('https://b.test', [new Run('B')]),
                new Run(' '),
                Hyperlink::external('https://c.test', [new Run('C')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertSame(3, substr_count($bytes, '/URI ('));
        self::assertStringContainsString('https://a.test', $bytes);
        self::assertStringContainsString('https://b.test', $bytes);
        self::assertStringContainsString('https://c.test', $bytes);
    }

    #[Test]
    public function annotation_has_link_subtype_and_rect(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                Hyperlink::external('https://x.test', [new Run('click here')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('/Annots [', $bytes);
        self::assertStringContainsString('/Subtype /Link', $bytes);
        self::assertStringContainsString('/Rect [', $bytes);
    }

    #[Test]
    public function bookmark_only_no_visible_content(): void
    {
        // Bookmark без children — просто marker в потоке.
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Before '),
                new Bookmark('marker1'),
                new Run('after.'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'bm-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Before', $text);
            self::assertStringContainsString('after.', $text);
            // Bookmark name НЕ должен быть visible.
            self::assertStringNotContainsString('marker1', $text);
        } finally {
            @unlink($tmp);
        }
        self::assertStringContainsString('(marker1)', $bytes);
    }

    #[Test]
    public function destinations_alphabetically_sorted(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Bookmark('zeta')]),
            new Paragraph([new Bookmark('alpha')]),
            new Paragraph([new Bookmark('mu')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $alphaPos = strpos($bytes, '(alpha)');
        $muPos = strpos($bytes, '(mu)');
        $zetaPos = strpos($bytes, '(zeta)');

        self::assertNotFalse($alphaPos);
        self::assertNotFalse($muPos);
        self::assertNotFalse($zetaPos);
        self::assertLessThan($muPos, $alphaPos);
        self::assertLessThan($zetaPos, $muPos);
    }
}
