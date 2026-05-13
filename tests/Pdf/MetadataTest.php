<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    #[Test]
    public function metadata_emits_info_dict_and_trailer_reference(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage();
        $doc->metadata(
            title: 'Test Document',
            author: 'Phase 20 Author',
            subject: 'Metadata test',
            keywords: 'pdf, metadata, phase20',
            creator: 'PHPUnit',
        );
        $bytes = $doc->toBytes();

        self::assertStringContainsString('/Title (Test Document)', $bytes);
        self::assertStringContainsString('/Author (Phase 20 Author)', $bytes);
        self::assertStringContainsString('/Subject (Metadata test)', $bytes);
        self::assertStringContainsString('/Keywords (pdf, metadata, phase20)', $bytes);
        self::assertStringContainsString('/Creator (PHPUnit)', $bytes);
        // Trailer reference.
        self::assertStringContainsString('/Info ', $bytes);
        // Auto-added Producer + CreationDate.
        self::assertStringContainsString('/Producer (dskripchenko/php-pdf)', $bytes);
        self::assertMatchesRegularExpression('@/CreationDate \(D:\d{14}@', $bytes);
    }

    #[Test]
    public function no_metadata_no_info_dict(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage();
        $bytes = $doc->toBytes();
        self::assertStringNotContainsString('/Info ', $bytes);
    }

    #[Test]
    public function ast_document_metadata_propagates(): void
    {
        $astDoc = new AstDocument(
            section: new Section([new Paragraph([new Run('content')])]),
            metadata: [
                'Title' => 'AST Test',
                'Author' => 'AST Author',
            ],
        );
        $bytes = $astDoc->toBytes(new \Dskripchenko\PhpPdf\Layout\Engine(compressStreams: false));
        self::assertStringContainsString('/Title (AST Test)', $bytes);
        self::assertStringContainsString('/Author (AST Author)', $bytes);
    }

    #[Test]
    public function metadata_chained_calls_merge(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage();
        $doc->metadata(title: 'T1');
        $doc->metadata(author: 'A1');
        $bytes = $doc->toBytes();
        self::assertStringContainsString('/Title (T1)', $bytes);
        self::assertStringContainsString('/Author (A1)', $bytes);
    }

    #[Test]
    public function metadata_with_special_chars_escaped(): void
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage();
        $doc->metadata(title: 'Title with (parens) and \\backslashes');
        $bytes = $doc->toBytes();
        // Parens should be escaped: \( \)
        self::assertStringContainsString('\\(parens\\)', $bytes);
        // Backslash escaped: \\
        self::assertStringContainsString('\\\\backslashes', $bytes);
    }
}
