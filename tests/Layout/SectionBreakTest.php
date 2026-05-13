<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectionBreakTest extends TestCase
{
    #[Test]
    public function single_section_unchanged_behavior(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('SingleSection')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(SingleSection) Tj', $bytes);
        // Один Page object.
        self::assertSame(1, substr_count($bytes, '/Type /Page '));
    }

    #[Test]
    public function additional_section_creates_new_page(): void
    {
        $primary = new Section([new Paragraph([new Run('First')])]);
        $additional = new Section([new Paragraph([new Run('Second')])]);
        $doc = new Document($primary, additionalSections: [$additional]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertSame(2, substr_count($bytes, '/Type /Page '));
        self::assertStringContainsString('(First) Tj', $bytes);
        self::assertStringContainsString('(Second) Tj', $bytes);
    }

    #[Test]
    public function section_with_different_orientation_renders_landscape(): void
    {
        $portrait = new Section(
            body: [new Paragraph([new Run('Portrait')])],
            pageSetup: new PageSetup(orientation: Orientation::Portrait),
        );
        $landscape = new Section(
            body: [new Paragraph([new Run('Landscape')])],
            pageSetup: new PageSetup(orientation: Orientation::Landscape),
        );
        $doc = new Document($portrait, additionalSections: [$landscape]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // A4 portrait: 595.28 × 841.89.
        // A4 landscape: 841.89 × 595.28.
        // Должны быть обе MediaBox dimensions в bytes.
        self::assertMatchesRegularExpression('@MediaBox\s*\[0\s+0\s+595\.28\s+841\.89\]@', $bytes);
        self::assertMatchesRegularExpression('@MediaBox\s*\[0\s+0\s+841\.89\s+595\.28\]@', $bytes);
    }

    #[Test]
    public function section_with_different_paper_size(): void
    {
        $a4 = new Section(
            body: [new Paragraph([new Run('A4 page')])],
            pageSetup: new PageSetup(paperSize: PaperSize::A4),
        );
        $letter = new Section(
            body: [new Paragraph([new Run('Letter page')])],
            pageSetup: new PageSetup(paperSize: PaperSize::Letter),
        );
        $doc = new Document($a4, additionalSections: [$letter]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // A4: 595.28 × 841.89; Letter: 612 × 792.
        self::assertStringContainsString('595.28 841.89', $bytes);
        self::assertStringContainsString('612 792', $bytes);
    }

    #[Test]
    public function multi_section_independent_headers(): void
    {
        $primary = new Section(
            body: [new Paragraph([new Run('Body1')])],
            headerBlocks: [new Paragraph([new Run('HeaderA')])],
        );
        $additional = new Section(
            body: [new Paragraph([new Run('Body2')])],
            headerBlocks: [new Paragraph([new Run('HeaderB')])],
        );
        $doc = new Document($primary, additionalSections: [$additional]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(HeaderA) Tj', $bytes);
        self::assertStringContainsString('(HeaderB) Tj', $bytes);
    }

    #[Test]
    public function multi_section_independent_margins(): void
    {
        $wide = new Section(
            body: [new Paragraph([new Run('WideMargins')])],
            pageSetup: new PageSetup(margins: new PageMargins(leftPt: 144, rightPt: 144, topPt: 72, bottomPt: 72)),
        );
        $narrow = new Section(
            body: [new Paragraph([new Run('NarrowMargins')])],
            pageSetup: new PageSetup(margins: new PageMargins(leftPt: 36, rightPt: 36, topPt: 36, bottomPt: 36)),
        );
        $doc = new Document($wide, additionalSections: [$narrow]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(WideMargins) Tj', $bytes);
        self::assertStringContainsString('(NarrowMargins) Tj', $bytes);
    }

    #[Test]
    public function sections_helper_returns_all(): void
    {
        $primary = new Section;
        $a = new Section;
        $b = new Section;
        $doc = new Document($primary, additionalSections: [$a, $b]);

        $all = $doc->sections();
        self::assertCount(3, $all);
        self::assertSame($primary, $all[0]);
        self::assertSame($a, $all[1]);
        self::assertSame($b, $all[2]);
    }

    #[Test]
    public function three_sections_create_three_starting_pages(): void
    {
        $s1 = new Section([new Paragraph([new Run('S1')])]);
        $s2 = new Section([new Paragraph([new Run('S2')])]);
        $s3 = new Section([new Paragraph([new Run('S3')])]);
        $doc = new Document($s1, additionalSections: [$s2, $s3]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertSame(3, substr_count($bytes, '/Type /Page '));
    }
}
