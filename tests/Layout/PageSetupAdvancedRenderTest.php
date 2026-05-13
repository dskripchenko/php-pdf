<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageSetupAdvancedRenderTest extends TestCase
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
    public function custom_dimensions_emit_correct_mediabox(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Tiny page')])],
            pageSetup: new PageSetup(customDimensionsPt: [240, 320]),
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('/MediaBox [0 0 240 320]', $bytes);
    }

    #[Test]
    public function first_page_number_offsets_page_field(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Page: '), Field::page()]),
                new PageBreak,
                new Paragraph([new Run('Page: '), Field::page()]),
            ],
            pageSetup: new PageSetup(firstPageNumber: 47),
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'pn-');
        file_put_contents($tmp, $bytes);
        try {
            $p1 = (string) shell_exec('pdftotext -f 1 -l 1 '.escapeshellarg($tmp).' - 2>&1');
            $p2 = (string) shell_exec('pdftotext -f 2 -l 2 '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Page: 47', $p1);
            self::assertStringContainsString('Page: 48', $p2);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function first_page_number_offsets_numpages_field(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Total: '), Field::totalPages()]),
                new PageBreak,
                new Paragraph([new Run('Total: '), Field::totalPages()]),
            ],
            pageSetup: new PageSetup(firstPageNumber: 100),
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'np-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            // 2 pages + firstPageNumber=100 → NUMPAGES = 100 + 2 - 1 = 101
            self::assertStringContainsString('Total: 101', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function first_page_header_overrides_regular_header(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Body p1')]),
                new PageBreak,
                new Paragraph([new Run('Body p2')]),
            ],
            headerBlocks: [new Paragraph([new Run('REGULAR_HEADER')])],
            firstPageHeaderBlocks: [new Paragraph([new Run('COVER_HEADER')])],
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'fh-');
        file_put_contents($tmp, $bytes);
        try {
            $p1 = (string) shell_exec('pdftotext -f 1 -l 1 '.escapeshellarg($tmp).' - 2>&1');
            $p2 = (string) shell_exec('pdftotext -f 2 -l 2 '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('COVER_HEADER', $p1);
            self::assertStringNotContainsString('REGULAR_HEADER', $p1);
            self::assertStringContainsString('REGULAR_HEADER', $p2);
            self::assertStringNotContainsString('COVER_HEADER', $p2);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function blank_first_page_header_omits_header_on_first_page(): void
    {
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('Cover page')]),
                new PageBreak,
                new Paragraph([new Run('Inner page')]),
            ],
            headerBlocks: [new Paragraph([new Run('REGULAR_HEADER')])],
            firstPageHeaderBlocks: [],  // empty list = blank header.
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'fh-');
        file_put_contents($tmp, $bytes);
        try {
            $p1 = (string) shell_exec('pdftotext -f 1 -l 1 '.escapeshellarg($tmp).' - 2>&1');
            $p2 = (string) shell_exec('pdftotext -f 2 -l 2 '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringNotContainsString('REGULAR_HEADER', $p1);
            self::assertStringContainsString('REGULAR_HEADER', $p2);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function mirrored_margins_swap_on_even_pages(): void
    {
        // Wide-asymmetric margins: leftPt=20, rightPt=80, mirrored.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 20, rightPt: 80, mirrored: true),
        );
        $doc = new Document(new Section(
            body: [
                new Paragraph([new Run('ODD_LEFT')]),
                new PageBreak,
                new Paragraph([new Run('EVEN_LEFT')]),
            ],
            pageSetup: $setup,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Sanity: pdf still валиден.
        self::assertStringStartsWith('%PDF', $bytes);
        $tmp = tempnam(sys_get_temp_dir(), 'mm-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('ODD_LEFT', $text);
            self::assertStringContainsString('EVEN_LEFT', $text);
        } finally {
            @unlink($tmp);
        }
    }
}
