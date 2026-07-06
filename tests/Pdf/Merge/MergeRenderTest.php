<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\PageImporter;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Merge output must be renderable by a real PDF engine — not just re-readable
 * by our own (lenient) reader.
 *
 * Regression guard for the double-indirection bug where an imported stream was
 * written as `N 0 obj  M 0 R  endobj` (a reference-to-a-reference). Our reader
 * dereferenced the chain, but real readers (poppler/Ghostscript/viewers)
 * rejected /Contents as "weird page contents" and rendered blank pages.
 */
final class MergeRenderTest extends TestCase
{
    private static function pdftotext(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if (is_executable($dir . '/pdftotext')) {
                return $dir . '/pdftotext';
            }
        }
        return null;
    }

    private function extractText(string $pdfBytes): string
    {
        $bin = self::pdftotext();
        if ($bin === null) {
            self::markTestSkipped('pdftotext (poppler) not installed');
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'merge-render-');
        file_put_contents($tmp, $pdfBytes);
        try {
            return (string) shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($tmp) . ' - 2>&1');
        } finally {
            @unlink($tmp);
        }
    }

    private function docWithText(string $text): string
    {
        $pdf = new PdfDocument();
        $pdf->addPage()->showText($text, 72, 700, StandardFont::Helvetica, 20);
        return $pdf->toBytes();
    }

    #[Test]
    public function appended_page_content_is_rendered_not_blank(): void
    {
        $bytes = PdfMerger::create()
            ->append(PdfSource::fromBytes($this->docWithText('MergeRenderAlpha')))
            ->toBytes();

        $text = $this->extractText($bytes);
        self::assertStringNotContainsString('Weird page contents', $text);
        self::assertStringContainsString('MergeRenderAlpha', $text);
    }

    #[Test]
    public function two_appended_documents_both_render(): void
    {
        $bytes = PdfMerger::create()
            ->append(PdfSource::fromBytes($this->docWithText('MergeRenderOne')))
            ->append(PdfSource::fromBytes($this->docWithText('MergeRenderTwo')))
            ->toBytes();

        self::assertSame(2, ReaderDocument::fromBytes($bytes)->pageCount());
        $text = $this->extractText($bytes);
        self::assertStringContainsString('MergeRenderOne', $text);
        self::assertStringContainsString('MergeRenderTwo', $text);
    }

    #[Test]
    public function fpdi_style_import_is_rendered(): void
    {
        $src = ReaderDocument::fromBytes($this->docWithText('ImportedBackground'));
        [$w, $h] = PageImporter::pageSize($src, 0);

        $doc = new PdfDocument();
        $page = $doc->addPage(customDimensionsPt: [$w, $h]);
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0, 0, $w, $h);
        $page->showText('OverlayText', 72, 400, StandardFont::Helvetica, 20);

        $text = $this->extractText($doc->toBytes());
        self::assertStringContainsString('ImportedBackground', $text);
        self::assertStringContainsString('OverlayText', $text);
    }
}
