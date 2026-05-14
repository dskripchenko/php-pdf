<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
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

    /**
     * Phase 155: regression — Table в header не должен вызывать infinite
     * forcePageBreak recursion. Bug raised на template 13 у printable где
     * 3-cell branding table в header висла навсегда (forcePageBreak →
     * renderHeaderFooter → renderTable → row не fits в header zone →
     * forcePageBreak → ...).
     */
    #[Test]
    public function table_in_header_does_not_infinite_loop(): void
    {
        $startTime = microtime(true);
        $headerTable = new Table(
            rows: [
                new Row([
                    new Cell([new Paragraph([new Run('LOGO')])]),
                    new Cell([new Paragraph([new Run('Company Name')])]),
                    new Cell([new Paragraph([new Run('Address line 1; Address line 2; +1 555-1234')])]),
                ]),
            ],
            style: new TableStyle(widthPercent: 100),
        );
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Body content')])],
            headerBlocks: [$headerTable],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Must finish quickly — pre-fix this hung forever.
        $elapsed = microtime(true) - $startTime;
        self::assertLessThan(5.0, $elapsed, "Render took {$elapsed}s — likely infinite loop");
        self::assertStringContainsString('%PDF-', substr($bytes, 0, 8));
    }

    /**
     * Phase 156: header overflow должен push body topY вниз (mpdf-style
     * adaptive top margin). Иначе header renders OVER body content.
     */
    #[Test]
    public function tall_header_pushes_body_below(): void
    {
        // Tall header — multi-row table that exceeds default 20mm top margin.
        $tallHeader = new Table(
            rows: [
                new Row([new Cell([new Paragraph([new Run('Line 1: company info')])])]),
                new Row([new Cell([new Paragraph([new Run('Line 2: address line')])])]),
                new Row([new Cell([new Paragraph([new Run('Line 3: contact info')])])]),
                new Row([new Cell([new Paragraph([new Run('Line 4: registration data')])])]),
                new Row([new Cell([new Paragraph([new Run('Line 5: license details')])])]),
            ],
            style: new TableStyle(widthPercent: 100),
        );
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('BODY_MARKER_TEXT')])],
            headerBlocks: [$tallHeader],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Extract Y-positions of text snippets via PDF stream inspection.
        // Header content "Line 1" should be HIGHER on page (larger Y) than body marker.
        if (! preg_match('/(\d+(?:\.\d+)?) Td.*?\(Line 1: company info\) Tj/s', $bytes, $matchHeader)
            || ! preg_match('/(\d+(?:\.\d+)?) Td.*?\(BODY_MARKER_TEXT\) Tj/s', $bytes, $matchBody)
        ) {
            // Streams may be compressed/reordered; falls back на pdftotext.
            self::markTestIncomplete('Cannot extract Y positions via regex; needs pdftotext for layout verification');
        }
        $headerY = (float) $matchHeader[1];
        $bodyY = (float) $matchBody[1];
        self::assertGreaterThan($bodyY, $headerY, 'Header content должен быть выше body на page');
    }
}
