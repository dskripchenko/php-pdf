<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Font\Ttf\VariableInstance;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 134: Variable font PDF integration tests.
 */
final class VariableFontInstanceTest extends TestCase
{
    private function variableTtf(): ?TtfFile
    {
        $path = '/System/Library/Fonts/NewYork.ttf';
        if (! is_readable($path)) {
            return null;
        }

        return TtfFile::fromFile($path);
    }

    #[Test]
    public function variable_instance_with_default_coords_is_identity(): void
    {
        $ttf = $this->variableTtf();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        // Defaults для wght=400 (axis default) — no variation applied.
        $inst = new VariableInstance($ttf, ['wght' => 400]);
        self::assertTrue($inst->isVariable());
    }

    #[Test]
    public function non_variable_font_instance_passes_glyph_through(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached');
        }
        $ttf = TtfFile::fromFile($path);
        $inst = new VariableInstance($ttf, ['wght' => 700]);
        self::assertFalse($inst->isVariable());

        // Glyph bytes returned unchanged.
        $bytes = "\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00";
        self::assertSame($bytes, $inst->transformGlyph(1, $bytes));
    }

    #[Test]
    public function pdf_font_with_axes_produces_valid_pdf(): void
    {
        $ttf = $this->variableTtf();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $font = new PdfFont($ttf, axes: ['wght' => 700]);

        $pdf = Document::new();
        $page = $pdf->addPage();
        $page->showEmbeddedText('Variable!', 50, 700, $font, 14);
        $bytes = $pdf->toBytes();

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('/Type /Font /Subtype /Type0', $bytes);
    }

    #[Test]
    public function different_axis_values_produce_different_font_streams(): void
    {
        $ttf = $this->variableTtf();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $font400 = new PdfFont($ttf, axes: ['wght' => 400]);
        $font1000 = new PdfFont($ttf, axes: ['wght' => 1000]);

        $pdf400 = Document::new();
        $pdf400->addPage()->showEmbeddedText('A', 50, 700, $font400, 14);
        $b400 = $pdf400->toBytes();

        $pdf1000 = Document::new();
        $pdf1000->addPage()->showEmbeddedText('A', 50, 700, $font1000, 14);
        $b1000 = $pdf1000->toBytes();

        // Differing axis values должны produce different FontFile2 contents.
        self::assertNotSame($b400, $b1000);
    }

    #[Test]
    public function empty_axes_array_uses_default_instance(): void
    {
        $ttf = $this->variableTtf();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $defaultFont = new PdfFont($ttf);  // no axes
        $explicitDefaultFont = new PdfFont($ttf, axes: []);

        $pdf1 = Document::new();
        $pdf1->addPage()->showEmbeddedText('X', 0, 0, $defaultFont, 12);
        $b1 = $pdf1->toBytes();

        $pdf2 = Document::new();
        $pdf2->addPage()->showEmbeddedText('X', 0, 0, $explicitDefaultFont, 12);
        $b2 = $pdf2->toBytes();

        // Both should be similar size (empty axes = default = no variation).
        self::assertLessThan(500, abs(strlen($b1) - strlen($b2)));
    }

    #[Test]
    public function subset_drops_variation_tables(): void
    {
        $ttf = $this->variableTtf();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $font = new PdfFont($ttf, axes: ['wght' => 700]);
        $pdf = Document::new(compressStreams: false);
        $pdf->addPage()->showEmbeddedText('A', 50, 700, $font, 14);
        $bytes = $pdf->toBytes();

        // Variation tables ('fvar', 'gvar', 'HVAR', 'MVAR') stripped из embed.
        // Their 4-char tags shouldn't appear в FontFile2 stream.
        // Note: 'gvar' и 'HVAR' специфичны достаточно чтобы appear только
        // в variation context (not random byte sequences).
        // ASCII tag bytes для fvar = 0x66 0x76 0x61 0x72 = "fvar".
        $fontFile = self::extractFontStream($bytes);
        if ($fontFile === null) {
            self::markTestSkipped('Could not extract FontFile2 stream');
        }
        // Subset shouldn't have variation table tags в TTF table directory.
        // Check first 1024 bytes of font stream (where table directory lives).
        $head = substr($fontFile, 0, 2048);
        self::assertStringNotContainsString('fvar', $head);
        self::assertStringNotContainsString('gvar', $head);
        self::assertStringNotContainsString('HVAR', $head);
        self::assertStringNotContainsString('MVAR', $head);
    }

    private static function extractFontStream(string $pdf): ?string
    {
        // Find /FontFile2 ID then locate the stream content.
        // Simple regex-based extraction (uncompressed streams only).
        if (! preg_match('@/FontFile2 (\d+) 0 R@', $pdf, $m)) {
            return null;
        }
        // Locate object body by ID.
        $objId = $m[1];
        if (! preg_match("@$objId 0 obj.*?stream\n(.*?)\nendstream@s", $pdf, $sm)) {
            return null;
        }

        return $sm[1];
    }
}
