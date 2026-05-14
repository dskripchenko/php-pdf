<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 194: PdfFont vertical writing mode tests.
 */
final class PdfFontVerticalTest extends TestCase
{
    private function font(): TtfFile
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return TtfFile::fromFile($path);
    }

    #[Test]
    public function vertical_writing_rejected_without_vmtx(): void
    {
        $ttf = $this->font();
        // Liberation Sans doesn't ship vhea/vmtx (Latin font).
        if ($ttf->hasVerticalMetrics()) {
            self::markTestSkipped('Test font unexpectedly has vmtx — try другой fixture.');
        }
        $this->expectException(\InvalidArgumentException::class);
        new PdfFont($ttf, verticalWriting: true);
    }

    #[Test]
    public function default_pdfFont_is_horizontal(): void
    {
        $font = new PdfFont($this->font());
        self::assertFalse($font->isVerticalWriting());
    }

    #[Test]
    public function has_vertical_metrics_query(): void
    {
        $ttf = $this->font();
        // Should return bool без exception.
        self::assertIsBool($ttf->hasVerticalMetrics());
    }

    #[Test]
    public function advance_height_returns_null_without_vmtx(): void
    {
        $ttf = $this->font();
        if (! $ttf->hasVerticalMetrics()) {
            self::assertNull($ttf->advanceHeight(65)); // 'A' glyph
        } else {
            self::assertIsInt($ttf->advanceHeight(65));
        }
    }
}
