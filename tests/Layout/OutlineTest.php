<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutlineTest extends TestCase
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
    public function single_heading_creates_outline_entry(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph(children: [new Run('Title One')], headingLevel: 1),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('/Type /Outlines', $bytes);
        self::assertStringContainsString('/Title (Title One)', $bytes);
        self::assertStringContainsString('/PageMode /UseOutlines', $bytes);
    }

    #[Test]
    public function multiple_top_level_headings_link_prev_next(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph(children: [new Run('A')], headingLevel: 1),
            new Paragraph(children: [new Run('B')], headingLevel: 1),
            new Paragraph(children: [new Run('C')], headingLevel: 1),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('/Title (A)', $bytes);
        self::assertStringContainsString('/Title (B)', $bytes);
        self::assertStringContainsString('/Title (C)', $bytes);
        // B должна иметь /Prev и /Next.
        self::assertMatchesRegularExpression(
            '@/Title \(B\)[^>]*/Prev \d+ 0 R[^>]*/Next \d+ 0 R@s',
            $bytes,
        );
    }

    #[Test]
    public function nested_headings_become_children(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph(children: [new Run('Chapter 1')], headingLevel: 1),
            new Paragraph(children: [new Run('Section 1.1')], headingLevel: 2),
            new Paragraph(children: [new Run('Section 1.2')], headingLevel: 2),
            new Paragraph(children: [new Run('Chapter 2')], headingLevel: 1),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Chapter 1 должен иметь /First (children).
        self::assertMatchesRegularExpression(
            '@/Title \(Chapter 1\)[^>]*?/First \d+ 0 R[^>]*?/Last \d+ 0 R[^>]*?/Count 2@s',
            $bytes,
        );
    }

    #[Test]
    public function outline_destination_references_correct_page(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph(children: [new Run('Heading')], headingLevel: 1),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // /Dest [<pageId> 0 R /XYZ <x> <y> 0]
        self::assertMatchesRegularExpression(
            '@/Dest \[\d+ 0 R /XYZ [\d.]+ [\d.]+ 0\]@',
            $bytes,
        );
    }

    #[Test]
    public function no_headings_no_outline_emitted(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph([new Run('Just body, no headings.')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringNotContainsString('/Type /Outlines', $bytes);
        self::assertStringNotContainsString('/PageMode', $bytes);
    }

    #[Test]
    public function builder_smoke_creates_full_outline_tree(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Introduction')
            ->paragraph('intro body')
            ->heading(2, 'Background')
            ->paragraph('background body')
            ->heading(2, 'Goals')
            ->paragraph('goals body')
            ->heading(1, 'Implementation')
            ->paragraph('impl body')
            ->heading(2, 'Architecture')
            ->paragraph('arch')
            ->heading(3, 'Layer 1')
            ->paragraph('l1')
            ->heading(3, 'Layer 2')
            ->paragraph('l2')
            ->toBytes();

        self::assertStringContainsString('/Type /Outlines', $bytes);
        foreach (['Introduction', 'Background', 'Goals', 'Implementation',
                  'Architecture', 'Layer 1', 'Layer 2'] as $title) {
            self::assertStringContainsString("($title)", $bytes);
        }
    }
}
