<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldResolutionTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function pdftotext(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fld-');
        file_put_contents($tmp, $bytes);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function page_field_resolves_to_current_page_number(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Page: '), Field::page()]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('Page: 1', $text);
    }

    #[Test]
    public function numpages_field_resolves_to_total_pages(): void
    {
        // Single-page doc → NUMPAGES = 1.
        $doc = new Document(new Section([
            new Paragraph([new Run('Total: '), Field::totalPages()]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('Total: 1', $text);
    }

    #[Test]
    public function date_field_resolves_to_current_date(): void
    {
        $doc = new Document(new Section([
            new Paragraph([Field::date('yyyy-MM-dd')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString(date('Y-m-d'), $text);
    }

    #[Test]
    public function mergefield_renders_field_name(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Hello, '), Field::mergeField('CustomerName')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('CustomerName', $text);
    }

    #[Test]
    public function numpages_reflects_multi_page_document(): void
    {
        // Stuff 80 paragraphs так что multi-page document.
        $blocks = [
            new Paragraph([new Run('Total pages: '), Field::totalPages()]),
        ];
        for ($i = 0; $i < 100; $i++) {
            $blocks[] = new Paragraph([new Run("Paragraph $i for filler text content")]);
        }
        $blocks[] = new Paragraph([new Run('END total: '), Field::totalPages()]);

        $doc = new Document(new Section($blocks));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        // NUMPAGES должен match'ить актуальное число pages.
        preg_match('/^\/Type \/Page /m', $bytes);
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount);
        self::assertStringContainsString("Total pages: $pageCount", $text);
        self::assertStringContainsString("END total: $pageCount", $text);
    }
}
