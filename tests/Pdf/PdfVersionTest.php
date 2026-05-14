<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 210: Document::pdfVersion constructor parameter — user-facing PDF
 * version override.
 */
final class PdfVersionTest extends TestCase
{
    private function buildDoc(?string $version = null): Document
    {
        return new Document(
            new Section([new Paragraph([new Run('Hello')])]),
            pdfVersion: $version,
        );
    }

    #[Test]
    public function default_pdf_version_is_1_7(): void
    {
        $doc = $this->buildDoc();
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-1.7\n", $bytes);
    }

    #[Test]
    public function pdf_version_1_4_for_legacy_compat(): void
    {
        $doc = $this->buildDoc('1.4');
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-1.4\n", $bytes);
    }

    #[Test]
    public function pdf_version_2_0_for_modern(): void
    {
        $doc = $this->buildDoc('2.0');
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-2.0\n", $bytes);
    }

    #[Test]
    public function pdf_version_1_6(): void
    {
        $doc = $this->buildDoc('1.6');
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-1.6\n", $bytes);
    }

    #[Test]
    public function xref_stream_bumps_version_when_lower(): void
    {
        // pdfVersion 1.4 + useXrefStream — should bump к 1.5 minimum.
        $doc = new Document(
            new Section([new Paragraph([new Run('Hello')])]),
            useXrefStream: true,
            pdfVersion: '1.4',
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-1.5\n", $bytes);
    }

    #[Test]
    public function xref_stream_preserves_higher_version(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('Hello')])]),
            useXrefStream: true,
            pdfVersion: '2.0',
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith("%PDF-2.0\n", $bytes);
    }

    #[Test]
    public function pdf_version_null_falls_back_to_engine_default(): void
    {
        // null = no override; Engine's default applies.
        $doc = new Document(
            new Section([new Paragraph([new Run('Hello')])]),
            pdfVersion: null,
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Engine default — currently 1.7.
        self::assertStringStartsWith("%PDF-1.7\n", $bytes);
    }
}
