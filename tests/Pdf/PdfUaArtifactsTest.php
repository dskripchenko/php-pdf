<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfUaArtifactsTest extends TestCase
{
    #[Test]
    public function tagged_pdf_with_header_emits_artifact(): void
    {
        $section = new Section(
            body: [new Paragraph([new Run('Body text')])],
            headerBlocks: [new Paragraph([new Run('Header text')])],
        );
        $ast = new AstDocument($section, tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /Artifact BDC wraps header.
        self::assertStringContainsString('/Artifact << /Type /Pagination >> BDC', $bytes);
        // Header text не в struct tree (no /P для header).
        self::assertSame(1, substr_count($bytes, '/Type /StructElem'));
    }

    #[Test]
    public function tagged_pdf_with_watermark_emits_artifact(): void
    {
        $section = new Section(
            body: [new Paragraph([new Run('Body')])],
            watermarkText: 'DRAFT',
        );
        $ast = new AstDocument($section, tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Artifact', $bytes);
    }

    #[Test]
    public function tagged_pdf_no_header_no_artifact(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Plain')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Artifact', $bytes);
    }

    #[Test]
    public function untagged_pdf_no_artifact_even_с_header(): void
    {
        $section = new Section(
            body: [new Paragraph([new Run('Body')])],
            headerBlocks: [new Paragraph([new Run('Header')])],
        );
        $doc = new AstDocument($section);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Artifact', $bytes);
    }

    #[Test]
    public function footer_also_wrapped(): void
    {
        $section = new Section(
            body: [new Paragraph([new Run('Body')])],
            footerBlocks: [new Paragraph([new Run('Page X')])],
        );
        $ast = new AstDocument($section, tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Artifact', $bytes);
    }
}
