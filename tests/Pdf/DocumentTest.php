<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    #[Test]
    public function empty_document_emits_blank_page(): void
    {
        $doc = Document::new(compressStreams: false);
        $pdf = $doc->toBytes();
        self::assertStringStartsWith('%PDF-1.7', $pdf);
        // PDF спека требует ≥ 1 page.
        self::assertMatchesRegularExpression('/\/Count 1\b/', $pdf);
    }

    #[Test]
    public function single_text_page(): void
    {
        $doc = Document::new(compressStreams: false);
        $page = $doc->addPage();
        $page->showText('Hello', 72, 720, StandardFont::TimesRoman, 12);

        $pdf = $doc->toBytes();
        self::assertStringContainsString('Hello', $pdf);
        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
    }

    #[Test]
    public function multi_page_with_unique_count(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        $pdf = $doc->toBytes();
        self::assertMatchesRegularExpression('/\/Count 3\b/', $pdf);
        // 3 Page objects.
        self::assertSame(3, substr_count($pdf, '/Type /Page '));
    }

    #[Test]
    public function shared_standard_font_registered_once(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->showText('A', 72, 720, StandardFont::Helvetica, 12);
        $doc->addPage()->showText('B', 72, 720, StandardFont::Helvetica, 12);
        $doc->addPage()->showText('C', 72, 720, StandardFont::Helvetica, 12);

        $pdf = $doc->toBytes();
        // /BaseFont /Helvetica появляется ровно один раз (один font object).
        self::assertSame(1, substr_count($pdf, '/BaseFont /Helvetica'));
    }

    #[Test]
    public function different_fonts_get_separate_objects(): void
    {
        $doc = Document::new(compressStreams: false);
        $page = $doc->addPage();
        $page->showText('A', 72, 720, StandardFont::TimesRoman, 12);
        $page->showText('B', 72, 700, StandardFont::Helvetica, 12);

        $pdf = $doc->toBytes();
        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
    }

    #[Test]
    public function mixed_orientation_pages_have_different_mediabox(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage(PaperSize::A4, Orientation::Portrait);
        $doc->addPage(PaperSize::A4, Orientation::Landscape);

        $pdf = $doc->toBytes();
        // Portrait A4: 595.28 × 841.89 → format "595.28 841.89".
        self::assertMatchesRegularExpression('/\/MediaBox \[0 0 595\.28 841\.89\]/', $pdf);
        // Landscape A4: swap to 841.89 × 595.28.
        self::assertMatchesRegularExpression('/\/MediaBox \[0 0 841\.89 595\.28\]/', $pdf);
    }

    #[Test]
    public function fill_and_stroke_rectangles(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->fillRect(72, 700, 100, 50, 1, 0, 0)->strokeRect(72, 700, 100, 50, 1);

        $pdf = $doc->toBytes();
        // Fill (rg + re + f) и stroke (RG + re + S) operators в content stream.
        self::assertStringContainsString(' rg', $pdf);
        self::assertStringContainsString(' RG', $pdf);
        self::assertStringContainsString(' re', $pdf);
    }

    #[Test]
    public function to_file_writes_pdf(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->showText('Test', 72, 720, StandardFont::TimesRoman, 12);

        $tmp = tempnam(sys_get_temp_dir(), 'doc-test-');
        $bytes = $doc->toFile($tmp);
        self::assertGreaterThan(0, $bytes);
        $contents = (string) file_get_contents($tmp);
        self::assertSame('%PDF', substr($contents, 0, 4));
        @unlink($tmp);
    }

    #[Test]
    public function pdftotext_extracts_from_built_pdf(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }
        $doc = Document::new(compressStreams: false);
        $p = $doc->addPage();
        $p->showText('Lorem ipsum dolor', 72, 720, StandardFont::TimesRoman, 12);
        $p->showText('sit amet consectetur', 72, 700, StandardFont::Helvetica, 11);

        $tmp = tempnam(sys_get_temp_dir(), 'p1-');
        $doc->toFile($tmp);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Lorem ipsum dolor', $text);
            self::assertStringContainsString('sit amet consectetur', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function default_paper_size_used_when_not_overridden(): void
    {
        $doc = Document::new(PaperSize::A3);
        $doc->addPage();
        $pdf = $doc->toBytes();
        // A3 dims = 841.89 × 1190.55 pt.
        self::assertMatchesRegularExpression('/\/MediaBox \[0 0 841\.89 1190\.55\]/', $pdf);
    }

    #[Test]
    public function us_letter_dimensions(): void
    {
        $doc = Document::new(PaperSize::Letter);
        $doc->addPage();
        $pdf = $doc->toBytes();
        self::assertMatchesRegularExpression('/\/MediaBox \[0 0 612 792\]/', $pdf);
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
