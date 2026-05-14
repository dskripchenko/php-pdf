<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 109: Markup annotations (Text/Highlight/Underline/StrikeOut/FreeText).
 */
final class MarkupAnnotationTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function text_sticky_note_emits_subtype_text_with_icon(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addTextAnnotation(100, 200, 'Hello note', title: 'Alice', icon: 'Comment'));

        self::assertStringContainsString('/Subtype /Text', $bytes);
        self::assertStringContainsString('/Name /Comment', $bytes);
        self::assertStringContainsString('/Contents (Hello note)', $bytes);
        self::assertStringContainsString('/T (Alice)', $bytes);
    }

    #[Test]
    public function text_annotation_default_icon_is_note(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addTextAnnotation(0, 0, 'x'));
        self::assertStringContainsString('/Name /Note', $bytes);
    }

    #[Test]
    public function text_annotation_invalid_icon_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addTextAnnotation(0, 0, 'x', icon: 'BogusIcon');
    }

    #[Test]
    public function highlight_emits_subtype_and_quadpoints(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addHighlightAnnotation(100, 200, 50, 20, 'flagged'));

        self::assertStringContainsString('/Subtype /Highlight', $bytes);
        self::assertStringContainsString('/QuadPoints [100 220 150 220 100 200 150 200]', $bytes);
        self::assertStringContainsString('/C [1 1 0]', $bytes);
        self::assertStringContainsString('/Contents (flagged)', $bytes);
    }

    #[Test]
    public function underline_emits_subtype_underline(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addUnderlineAnnotation(50, 60, 100, 12));

        self::assertStringContainsString('/Subtype /Underline', $bytes);
        self::assertStringContainsString('/C [0 0.5 1]', $bytes);
    }

    #[Test]
    public function strikeout_emits_subtype_strikeout(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addStrikeOutAnnotation(50, 60, 100, 12));

        self::assertStringContainsString('/Subtype /StrikeOut', $bytes);
        self::assertStringContainsString('/C [1 0 0]', $bytes);
    }

    #[Test]
    public function custom_color_overrides_default(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addHighlightAnnotation(0, 0, 10, 10, color: [0.2, 0.4, 0.6]));

        self::assertStringContainsString('/C [0.2 0.4 0.6]', $bytes);
    }

    #[Test]
    public function freetext_emits_subtype_with_da_string(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFreeTextAnnotation(50, 60, 200, 30, 'Stamp text', fontSize: 14));

        self::assertStringContainsString('/Subtype /FreeText', $bytes);
        self::assertStringContainsString('/Contents (Stamp text)', $bytes);
        self::assertStringContainsString('/DA (/Helv 14 Tf 0 g)', $bytes);
    }

    #[Test]
    public function freetext_with_color_uses_rg_in_da(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFreeTextAnnotation(0, 0, 10, 10, 'x', color: [0.5, 0.5, 0.5]));

        self::assertStringContainsString('/DA (/Helv 11 Tf 0.5 0.5 0.5 rg)', $bytes);
    }

    #[Test]
    public function multiple_markups_emit_multiple_objects(): void
    {
        $bytes = $this->emit(function ($p) {
            $p->addTextAnnotation(10, 10, 'a');
            $p->addHighlightAnnotation(20, 20, 5, 5);
            $p->addUnderlineAnnotation(30, 30, 5, 5);
            $p->addStrikeOutAnnotation(40, 40, 5, 5);
            $p->addFreeTextAnnotation(50, 50, 30, 10, 'free');
        });

        self::assertStringContainsString('/Subtype /Text', $bytes);
        self::assertStringContainsString('/Subtype /Highlight', $bytes);
        self::assertStringContainsString('/Subtype /Underline', $bytes);
        self::assertStringContainsString('/Subtype /StrikeOut', $bytes);
        self::assertStringContainsString('/Subtype /FreeText', $bytes);
    }

    #[Test]
    public function annotations_appear_in_page_annots_array(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addHighlightAnnotation(10, 10, 5, 5));

        // Page contains /Annots [N 0 R].
        self::assertMatchesRegularExpression('@/Annots \[\d+ 0 R\]@', $bytes);
    }
}
