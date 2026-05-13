<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private PdfFont $font;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->font = new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function empty_document_renders_blank_page(): void
    {
        $doc = new Document(new Section);
        $pdf = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertStringContainsString('/Count 1', $pdf);
    }

    #[Test]
    public function single_paragraph_renders(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Hello, world!')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        $tmp = tempnam(sys_get_temp_dir(), 'engine-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Hello, world!', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function heading_uses_bigger_font_size(): void
    {
        $doc = new Document(new Section([
            new Paragraph(children: [new Run('H1')], headingLevel: 1),
            new Paragraph([new Run('body')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        // H1 = 24pt — должно быть "24 Tf" в content stream.
        // Body = 11pt default.
        self::assertStringContainsString(' 24 Tf', $bytes);
        self::assertStringContainsString(' 11 Tf', $bytes);
    }

    #[Test]
    public function pagebreak_creates_new_page(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Page 1')]),
            new PageBreak,
            new Paragraph([new Run('Page 2')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));
        self::assertStringContainsString('/Count 2', $bytes);
    }

    #[Test]
    public function horizontal_rule_emits_stroke(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Above')]),
            new HorizontalRule,
            new Paragraph([new Run('Below')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));
        // strokeRectangle → "RG" (stroke color) + "S\n" (stroke).
        self::assertStringContainsString(' RG', $bytes);
        self::assertStringContainsString("S\n", $bytes);
    }

    #[Test]
    public function long_text_wraps_to_multiple_lines(): void
    {
        $longText = str_repeat('Lorem ipsum dolor sit amet consectetur. ', 20);
        $doc = new Document(new Section([
            new Paragraph([new Run($longText)]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        // Многие "Tj" operators (по одному на line).
        $tjCount = substr_count($bytes, ' Tj');
        $tjArrayCount = substr_count($bytes, ' TJ');
        self::assertGreaterThan(3, $tjCount + $tjArrayCount,
            'Long paragraph should wrap to multiple lines');
    }

    #[Test]
    public function content_overflow_triggers_auto_page_break(): void
    {
        // 100 параграфов — заполнят несколько страниц.
        $paragraphs = [];
        for ($i = 0; $i < 100; $i++) {
            $paragraphs[] = new Paragraph([new Run("Paragraph $i with some text")]);
        }
        $doc = new Document(new Section($paragraphs));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        // Должно быть несколько page'ей.
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount, 'Auto page break should happen on overflow');
    }

    #[Test]
    public function center_alignment(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('Centered')],
                style: new ParagraphStyle(alignment: Alignment::Center),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));
        // Just ensures text shows. X-coord centering — visual check (Phase L test corpus).
        $tmp = tempnam(sys_get_temp_dir(), 'engine-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Centered', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function custom_paper_size_through_section(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('US Letter doc')])],
            pageSetup: new PageSetup(paperSize: \Dskripchenko\PhpPdf\Style\PaperSize::Letter),
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));
        // Letter = 612 × 792 pt.
        self::assertStringContainsString('/MediaBox [0 0 612 792]', $bytes);
    }

    #[Test]
    public function default_font_fallback_to_standard(): void
    {
        // Без defaultFont — engine использует StandardFont::Helvetica fallback.
        $doc = new Document(new Section([
            new Paragraph([new Run('Latin only with base-14')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));  // нет defaultFont
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
    }

    #[Test]
    public function multi_run_paragraph_with_mixed_styles(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Plain '),
                new Run('bold', (new RunStyle)->withBold()),
                new Run(' and '),
                new Run('italic', (new RunStyle)->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        $tmp = tempnam(sys_get_temp_dir(), 'engine-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Plain', $text);
            self::assertStringContainsString('bold', $text);
            self::assertStringContainsString('italic', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function cyrillic_text_via_embedded_font(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Привет, мир!')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));

        $tmp = tempnam(sys_get_temp_dir(), 'engine-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Привет, мир!', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function paragraph_spacing_before_and_after(): void
    {
        // Sanity: spacing не должен ломать render.
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('Paragraph 1')],
                style: new ParagraphStyle(spaceAfterPt: 24),
            ),
            new Paragraph(
                children: [new Run('Paragraph 2 with space before')],
                style: new ParagraphStyle(spaceBeforePt: 24),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font));
        self::assertStringStartsWith('%PDF', $bytes);
    }
}
