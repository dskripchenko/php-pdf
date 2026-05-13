<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeaderFooterTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function pdftotext(string $bytes, int $page = 0): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hf-');
        file_put_contents($tmp, $bytes);
        try {
            $flag = $page > 0 ? "-f $page -l $page " : '';

            return (string) shell_exec('pdftotext '.$flag.escapeshellarg($tmp).' - 2>&1');
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function header_renders_on_page(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body content here')])],
            headerBlocks: [new Paragraph([new Run('HEADER MARKER')])],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('HEADER MARKER', $text);
    }

    #[Test]
    public function footer_renders_on_page(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body')])],
            footerBlocks: [new Paragraph([new Run('FOOTER MARKER')])],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('FOOTER MARKER', $text);
    }

    #[Test]
    public function header_repeats_on_every_page(): void
    {
        $body = [];
        for ($i = 0; $i < 60; $i++) {
            $body[] = new Paragraph([new Run("Body paragraph $i with text")]);
        }
        $doc = new Document(new Section(
            body: $body,
            headerBlocks: [new Paragraph([new Run('TOP_OF_PAGE')])],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount);
        $page1 = $this->pdftotext($bytes, 1);
        $page2 = $this->pdftotext($bytes, 2);
        self::assertStringContainsString('TOP_OF_PAGE', $page1);
        self::assertStringContainsString('TOP_OF_PAGE', $page2);
    }

    #[Test]
    public function footer_can_use_page_of_total_field(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Page 1 body')]),
                new \Dskripchenko\PhpPdf\Element\PageBreak,
                new Paragraph([new Run('Page 2 body')]),
            ],
            footerBlocks: [
                new Paragraph(
                    children: [
                        new Run('Page '),
                        Field::page(),
                        new Run(' of '),
                        Field::totalPages(),
                    ],
                    style: new ParagraphStyle(alignment: Alignment::Center),
                ),
            ],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $page1 = $this->pdftotext($bytes, 1);
        $page2 = $this->pdftotext($bytes, 2);
        self::assertStringContainsString('Page 1 of 2', $page1);
        self::assertStringContainsString('Page 2 of 2', $page2);
    }

    #[Test]
    public function no_header_no_footer_works_without_them(): void
    {
        $doc = new Document(new Section([new Paragraph([new Run('Plain body')])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('Plain body', $text);
    }
}
