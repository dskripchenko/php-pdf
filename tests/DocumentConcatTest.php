<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 223: Document::concat() multi-document merge tests.
 */
final class DocumentConcatTest extends TestCase
{
    private function makeDoc(string $text, array $metadata = []): Document
    {
        return new Document(
            new Section([new Paragraph([new Run($text)])]),
            metadata: $metadata,
        );
    }

    #[Test]
    public function concat_two_documents(): void
    {
        $doc1 = $this->makeDoc('First doc');
        $doc2 = $this->makeDoc('Second doc');
        $combined = Document::concat([$doc1, $doc2]);

        $sections = $combined->sections();
        self::assertCount(2, $sections);

        $bytes = $combined->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('First doc', $bytes);
        self::assertStringContainsString('Second doc', $bytes);
    }

    #[Test]
    public function concat_three_documents(): void
    {
        $docs = [
            $this->makeDoc('A'),
            $this->makeDoc('B'),
            $this->makeDoc('C'),
        ];
        $combined = Document::concat($docs);
        self::assertCount(3, $combined->sections());
    }

    #[Test]
    public function concat_preserves_additional_sections(): void
    {
        // Doc1 has 2 sections, doc2 has 1 → combined = 3 sections.
        $doc1 = new Document(
            section: new Section([new Paragraph([new Run('1a')])]),
            additionalSections: [
                new Section([new Paragraph([new Run('1b')])]),
            ],
        );
        $doc2 = $this->makeDoc('2');

        $combined = Document::concat([$doc1, $doc2]);
        self::assertCount(3, $combined->sections());
    }

    #[Test]
    public function concat_inherits_metadata_from_first(): void
    {
        $doc1 = $this->makeDoc('first', metadata: ['Title' => 'Combined']);
        $doc2 = $this->makeDoc('second', metadata: ['Title' => 'Ignored']);

        $combined = Document::concat([$doc1, $doc2]);
        self::assertSame(['Title' => 'Combined'], $combined->metadata);
    }

    #[Test]
    public function concat_inherits_xref_stream_flag(): void
    {
        $doc1 = new Document(
            new Section([new Paragraph([new Run('a')])]),
            useXrefStream: true,
        );
        $doc2 = $this->makeDoc('b');

        $combined = Document::concat([$doc1, $doc2]);
        self::assertTrue($combined->useXrefStream);
    }

    #[Test]
    public function concat_rejects_empty_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Document::concat([]);
    }

    #[Test]
    public function concat_rejects_non_documents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Document::concat([$this->makeDoc('a'), 'not a document']);
    }

    #[Test]
    public function single_document_concat_unchanged(): void
    {
        $orig = $this->makeDoc('only one');
        $combined = Document::concat([$orig]);

        self::assertCount(1, $combined->sections());
        $bytes = $combined->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('only one', $bytes);
    }

    #[Test]
    public function combined_renders_valid_pdf(): void
    {
        $combined = Document::concat([
            $this->makeDoc('Part A'),
            $this->makeDoc('Part B'),
            $this->makeDoc('Part C'),
        ]);
        $bytes = $combined->toBytes(new Engine(compressStreams: false));

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
        // 3 sections → 3 pages.
        $pageCount = preg_match_all('@/Type /Page\b@', $bytes);
        self::assertGreaterThanOrEqual(3, $pageCount);
    }
}
