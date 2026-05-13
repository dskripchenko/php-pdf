<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    #[Test]
    public function new_returns_fresh_builder(): void
    {
        $b1 = DocumentBuilder::new();
        $b2 = DocumentBuilder::new();
        self::assertNotSame($b1, $b2);
    }

    #[Test]
    public function build_returns_document_with_empty_section_by_default(): void
    {
        $doc = DocumentBuilder::new()->build();
        self::assertInstanceOf(Document::class, $doc);
        self::assertSame([], $doc->section->body);
    }

    #[Test]
    public function string_paragraph_creates_single_run(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('Hello')
            ->build();

        self::assertCount(1, $doc->section->body);
        $p = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(1, $p->children);
        $run = $p->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('Hello', $run->text);
        self::assertNull($p->headingLevel);
    }

    #[Test]
    public function closure_paragraph_builds_via_paragraph_builder(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn(ParagraphBuilder $p) => $p
                ->text('Plain ')
                ->bold('bold')
                ->text(' tail')
            )
            ->build();

        $p = $doc->section->body[0];
        self::assertCount(3, $p->children);
        self::assertSame('Plain ', $p->children[0]->text);
        self::assertSame('bold', $p->children[1]->text);
        self::assertTrue($p->children[1]->style->bold);
        self::assertSame(' tail', $p->children[2]->text);
        self::assertFalse($p->children[2]->style->bold);
    }

    #[Test]
    public function heading_sets_level(): void
    {
        $doc = DocumentBuilder::new()
            ->heading(1, 'Title')
            ->heading(2, 'Subtitle')
            ->build();

        self::assertSame(1, $doc->section->body[0]->headingLevel);
        self::assertSame(2, $doc->section->body[1]->headingLevel);
    }

    #[Test]
    public function heading_with_closure(): void
    {
        $doc = DocumentBuilder::new()
            ->heading(1, fn(ParagraphBuilder $p) => $p
                ->text('Part ')
                ->italic('I')
            )
            ->build();

        $p = $doc->section->body[0];
        self::assertSame(1, $p->headingLevel);
        self::assertCount(2, $p->children);
        self::assertTrue($p->children[1]->style->italic);
    }

    #[Test]
    public function invalid_heading_level_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentBuilder::new()->heading(7, 'oops');
    }

    #[Test]
    public function invalid_heading_level_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentBuilder::new()->heading(0, 'oops');
    }

    #[Test]
    public function page_break_adds_block(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('Page 1')
            ->pageBreak()
            ->paragraph('Page 2')
            ->build();

        self::assertCount(3, $doc->section->body);
        self::assertInstanceOf(PageBreak::class, $doc->section->body[1]);
    }

    #[Test]
    public function horizontal_rule_adds_block(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('Above')
            ->horizontalRule()
            ->paragraph('Below')
            ->build();

        self::assertInstanceOf(HorizontalRule::class, $doc->section->body[1]);
    }

    #[Test]
    public function empty_line_adds_empty_paragraph(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('Top')
            ->emptyLine()
            ->paragraph('Bot')
            ->build();

        self::assertCount(3, $doc->section->body);
        $empty = $doc->section->body[1];
        self::assertInstanceOf(Paragraph::class, $empty);
        self::assertTrue($empty->isEmpty());
    }

    #[Test]
    public function block_escape_hatch_appends_raw_node(): void
    {
        $custom = new PageBreak;
        $doc = DocumentBuilder::new()
            ->block($custom)
            ->build();

        self::assertSame($custom, $doc->section->body[0]);
    }

    #[Test]
    public function page_setup_propagates_to_section(): void
    {
        $setup = new PageSetup(
            paperSize: PaperSize::Letter,
            orientation: Orientation::Landscape,
        );
        $doc = DocumentBuilder::new()
            ->pageSetup($setup)
            ->paragraph('Hello')
            ->build();

        self::assertSame($setup, $doc->section->pageSetup);
    }

    #[Test]
    public function to_bytes_produces_valid_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Title')
            ->paragraph('Body text')
            ->toBytes();

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('/Type /Catalog', $bytes);
    }

    #[Test]
    public function to_file_writes_pdf(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'builder-');
        try {
            $bytes = DocumentBuilder::new()->paragraph('Hi')->toFile($tmp);
            self::assertGreaterThan(0, $bytes);
            self::assertStringStartsWith('%PDF', (string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function paragraph_accepts_ast_node_directly(): void
    {
        $p = new Paragraph([new Run('raw')]);
        $doc = DocumentBuilder::new()->paragraph($p)->build();
        self::assertSame($p, $doc->section->body[0]);
    }

    #[Test]
    public function full_smoke_renders_multi_block_pdf(): void
    {
        $fontPath = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        $engine = is_readable($fontPath)
            ? new Engine(compressStreams: false, defaultFont: new PdfFont(TtfFile::fromFile($fontPath)))
            : new Engine(compressStreams: false);

        $bytes = DocumentBuilder::new()
            ->heading(1, 'Invoice #42')
            ->paragraph(fn(ParagraphBuilder $p) => $p
                ->text('Customer: ')
                ->bold('Acme Co.')
            )
            ->horizontalRule()
            ->paragraph(fn(ParagraphBuilder $p) => $p
                ->alignCenter()
                ->text('Page ')
                ->pageNumber()
                ->text(' of ')
                ->totalPages()
            )
            ->pageBreak()
            ->paragraph('Continued on page 2.')
            ->toBytes($engine);

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('/Count 2', $bytes);
    }

    #[Test]
    public function text_convenience_creates_paragraph(): void
    {
        $doc = DocumentBuilder::new()
            ->text('shortcut')
            ->build();

        self::assertCount(1, $doc->section->body);
        $p = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertSame('shortcut', $p->children[0]->text);
    }
}
