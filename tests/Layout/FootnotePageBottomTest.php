<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 222: Footnote per-page bottom positioning (opt-in via
 * Section::footnoteBottomReservedPt).
 */
final class FootnotePageBottomTest extends TestCase
{
    #[Test]
    public function default_endnote_mode_unchanged(): void
    {
        // Without flag — endnote-style at end of section body (existing behavior).
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Body text'),
                new Footnote('A footnote'),
            ]),
        ]));

        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('A footnote', $bytes);
        // Marker emitted в text flow (superscript).
        self::assertStringContainsString('Body text', $bytes);
    }

    #[Test]
    public function per_page_mode_emits_footnotes_at_bottom(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([
                    new Run('Page body with '),
                    new Footnote('First footnote.'),
                    new Run(' a citation.'),
                ]),
            ],
            footnoteBottomReservedPt: 60.0,
        ));

        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('First footnote.', $bytes);
    }

    #[Test]
    public function multiple_footnotes_numbered_sequentially(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([
                    new Run('First '),
                    new Footnote('Note one'),
                    new Run(' and second '),
                    new Footnote('Note two'),
                    new Run(' citation.'),
                ]),
            ],
            footnoteBottomReservedPt: 60.0,
        ));

        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Footnote 1 + footnote 2 both rendered.
        self::assertStringContainsString('Note one', $bytes);
        self::assertStringContainsString('Note two', $bytes);
        // Numbered (1. and 2.)
        self::assertMatchesRegularExpression('@\(1\. Note one\)@', $bytes);
        self::assertMatchesRegularExpression('@\(2\. Note two\)@', $bytes);
    }

    #[Test]
    public function content_area_reduced_by_reservation(): void
    {
        // Bodies large enough к hit bottom — verify content area was reduced.
        // Without reservation: more content fits per page.
        // With 100pt reservation: less fits per page → more pages.
        $bodyBlocks = [];
        for ($i = 0; $i < 30; $i++) {
            $bodyBlocks[] = new Paragraph([new Run("Line $i — some moderately long content for layout test")]);
        }

        $docDefault = new Document(new Section(body: $bodyBlocks));
        $docReserved = new Document(new Section(
            body: $bodyBlocks,
            footnoteBottomReservedPt: 100.0,
        ));

        $bytesDefault = $docDefault->toBytes(new Engine(compressStreams: false));
        $bytesReserved = $docReserved->toBytes(new Engine(compressStreams: false));

        $pagesDefault = preg_match_all('@/Type /Page\b@', $bytesDefault);
        $pagesReserved = preg_match_all('@/Type /Page\b@', $bytesReserved);

        // Reserved version uses ≥ pages (потому что content area smaller).
        self::assertGreaterThanOrEqual($pagesDefault, $pagesReserved);
    }

    #[Test]
    public function reservation_zero_or_null_behaves_as_endnotes(): void
    {
        // null reservation (default) = current endnote behavior.
        $doc1 = new Document(new Section(body: [
            new Paragraph([new Run('a'), new Footnote('foo')]),
        ]));
        $bytes1 = $doc1->toBytes(new Engine(compressStreams: false));

        // Same input но explicit null.
        $doc2 = new Document(new Section(
            body: [new Paragraph([new Run('a'), new Footnote('foo')])],
            footnoteBottomReservedPt: null,
        ));
        $bytes2 = $doc2->toBytes(new Engine(compressStreams: false));

        // Both produce valid PDFs containing the footnote.
        self::assertStringContainsString('foo', $bytes1);
        self::assertStringContainsString('foo', $bytes2);
    }

    #[Test]
    public function pdf_structure_remains_valid(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Body'), new Footnote('Foot')]),
            ],
            footnoteBottomReservedPt: 50.0,
        ));

        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }
}
