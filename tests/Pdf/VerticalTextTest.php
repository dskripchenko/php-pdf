<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 128: Vertical text (CJK/East-Asian writing mode).
 */
final class VerticalTextTest extends TestCase
{
    #[Test]
    public function vertical_text_emits_one_tj_per_char(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showTextVertical('ABCD', 100, 700, StandardFont::Helvetica, 12);
        $bytes = $pdf->toBytes();

        // 4 chars → 4 separate Tj operations.
        self::assertStringContainsString('(A) Tj', $bytes);
        self::assertStringContainsString('(B) Tj', $bytes);
        self::assertStringContainsString('(C) Tj', $bytes);
        self::assertStringContainsString('(D) Tj', $bytes);
    }

    #[Test]
    public function vertical_text_decreases_y_per_char(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        // lineHeight = 12 * 1.2 = 14.4
        $page->showTextVertical('ABC', 100, 700, StandardFont::Helvetica, 12);
        $bytes = $pdf->toBytes();

        // First char at y=700.
        self::assertStringContainsString('100 700 Td', $bytes);
        // Second char at y=685.6 (700 - 14.4).
        self::assertStringContainsString('100 685.6 Td', $bytes);
        // Third char at y=671.2.
        self::assertStringContainsString('100 671.2 Td', $bytes);
    }

    #[Test]
    public function explicit_line_height_overrides_default(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showTextVertical('AB', 100, 700, StandardFont::Helvetica, 12, lineHeightPt: 20.0);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('100 700 Td', $bytes);
        self::assertStringContainsString('100 680 Td', $bytes);
    }

    #[Test]
    public function vertical_text_preserves_x_column(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showTextVertical('XYZW', 250, 500, StandardFont::Helvetica, 16);
        $bytes = $pdf->toBytes();

        // All chars at x=250 (column position).
        self::assertSame(4, preg_match_all('@^250 [0-9.]+ Td$@m', $bytes));
    }

    #[Test]
    public function empty_string_no_op(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showTextVertical('', 100, 700, StandardFont::Helvetica, 12);
        $bytes = $pdf->toBytes();

        // No text operations emitted.
        self::assertSame(0, preg_match_all('@\) Tj$@m', $bytes));
    }

    #[Test]
    public function color_applied_to_vertical_text(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showTextVertical('AB', 100, 700, StandardFont::Helvetica, 12, r: 1.0, g: 0.0, b: 0.0);
        $bytes = $pdf->toBytes();

        // Phase 160: rg emitted один раз для consecutive same-color chars
        // (gstate persistence). Раньше: 1 rg per char.
        self::assertGreaterThanOrEqual(1, preg_match_all('@1 0 0 rg@m', $bytes));
    }

    #[Test]
    public function multi_byte_utf8_chars_split_correctly(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        // Cyrillic "Привет" — 6 multi-byte UTF-8 chars. Standard fonts
        // не поддерживают Cyrillic, но мы проверяем что char count правильный.
        $page->showTextVertical('Привет', 100, 700, StandardFont::Helvetica, 12);
        $bytes = $pdf->toBytes();

        // 6 chars → 6 Td operations on x=100.
        self::assertSame(6, preg_match_all('@^100 [0-9.]+ Td$@m', $bytes));
    }

    #[Test]
    public function embedded_vertical_text_with_pdffont(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $ttf = \Dskripchenko\PhpPdf\Font\Ttf\TtfFile::fromFile($path);
        $font = new \Dskripchenko\PhpPdf\Pdf\PdfFont($ttf);

        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        // Cyrillic vertical text (embedded font supports Cyrillic).
        $page->showEmbeddedTextVertical('АБВ', 100, 700, $font, 14);
        $bytes = $pdf->toBytes();

        // 3 chars → 3 Td operations.
        self::assertSame(3, preg_match_all('@^100 [0-9.]+ Td$@m', $bytes));
        // Cyrillic А = U+0410. Encoded as hex string in PDF.
        // Expect at least one Tj per char with hex content.
        self::assertGreaterThanOrEqual(3, preg_match_all('@<[0-9a-fA-F]+> Tj@', $bytes));
    }
}
