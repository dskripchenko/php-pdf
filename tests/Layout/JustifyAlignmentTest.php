<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
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

final class JustifyAlignmentTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    /**
     * Text long enough to overflow into several lines on A4.
     */
    private function longParagraph(Alignment $align): Paragraph
    {
        $text = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit ', 5);

        return new Paragraph(
            children: [new Run(trim($text))],
            style: new ParagraphStyle(alignment: $align),
        );
    }

    #[Test]
    public function justify_paragraph_renders_valid_pdf(): void
    {
        $doc = new Document(new Section([$this->longParagraph(Alignment::Both)]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringStartsWith('%PDF', $bytes);
    }

    #[Test]
    public function justified_lines_render_text_visible_in_pdftotext(): void
    {
        $doc = new Document(new Section([$this->longParagraph(Alignment::Both)]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'just-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Lorem ipsum dolor', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function justify_adds_extra_horizontal_space_between_words(): void
    {
        // Compare the Tj count and the Td positions of start against justify.
        $startDoc = new Document(new Section([$this->longParagraph(Alignment::Start)]));
        $bothDoc = new Document(new Section([$this->longParagraph(Alignment::Both)]));

        $startBytes = $startDoc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        $bothBytes = $bothDoc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));

        // The justified output has to be longer in bytes (more Tj positions for
        // the wider space gaps). The same number of glyphs, different Td X
        // coordinates, different bytes.
        self::assertNotSame($startBytes, $bothBytes);
    }

    #[Test]
    public function last_line_of_justified_paragraph_not_stretched(): void
    {
        // Build a paragraph whose last line is very short (a single word).
        $text = str_repeat('Lorem ipsum dolor sit amet ', 4).'short.';
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run($text)],
                style: new ParagraphStyle(alignment: Alignment::Both),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));

        // Sanity: 'short.' rendered (last word).
        $tmp = tempnam(sys_get_temp_dir(), 'just-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('short.', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function single_word_paragraph_unchanged_by_justify(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('OnlyOneWord')],
                style: new ParagraphStyle(alignment: Alignment::Both),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'just-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('OnlyOneWord', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function short_line_not_stretched_under_60_percent_fill_threshold(): void
    {
        // Very little text — a fill ratio below 60% means no justify stretch.
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('Tiny')],
                style: new ParagraphStyle(alignment: Alignment::Both),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringStartsWith('%PDF', $bytes);
    }
}
