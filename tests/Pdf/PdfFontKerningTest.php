<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfFontKerningTest extends TestCase
{
    private PdfFont $font;

    private TtfFile $ttf;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->ttf = TtfFile::fromFile($path);
        $this->font = new PdfFont($this->ttf);
    }

    #[Test]
    public function kerning_pdf_units_positive_for_tight_pairs(): void
    {
        $aGid = $this->ttf->glyphIdForChar(0x41); // A
        $vGid = $this->ttf->glyphIdForChar(0x56); // V

        $kern = $this->font->kerningPdfUnits($aGid, $vGid);
        // AV: GPOS xAdvance = -152, em = 2048
        // PDF TJ value = -(-152) * 1000 / 2048 = 74
        self::assertGreaterThan(0, $kern, 'AV должен быть tighter (positive PDF kerning)');
        self::assertEqualsWithDelta(74, $kern, 1);
    }

    #[Test]
    public function kerning_zero_for_non_kerned_pairs(): void
    {
        $aGid = $this->ttf->glyphIdForChar(0x41);
        $bGid = $this->ttf->glyphIdForChar(0x42);

        self::assertSame(0, $this->font->kerningPdfUnits($aGid, $bGid));
    }

    #[Test]
    public function encodeTextTjArray_single_run_no_kerning(): void
    {
        $tjOps = $this->font->encodeTextTjArray('AB');
        // Нет kerning AB → один single hex run.
        self::assertCount(1, $tjOps);
        self::assertIsString($tjOps[0]);
    }

    #[Test]
    public function encodeTextTjArray_splits_at_kerning_pair(): void
    {
        $tjOps = $this->font->encodeTextTjArray('AV');
        // AV → [<hexA> N <hexV>]
        self::assertCount(3, $tjOps);
        self::assertIsString($tjOps[0]);
        self::assertIsInt($tjOps[1]);
        self::assertIsString($tjOps[2]);
        // adjustment должен быть positive (tighter pair).
        self::assertGreaterThan(0, $tjOps[1]);
    }

    #[Test]
    public function encodeTextTjArray_multiple_kerned_pairs(): void
    {
        // ToWa имеет kerning между T-o, W-a (W-a в Liberation Sans).
        $tjOps = $this->font->encodeTextTjArray('AVAYTo');
        // Ожидаем несколько разделений.
        $intCount = 0;
        foreach ($tjOps as $op) {
            if (is_int($op)) {
                $intCount++;
            }
        }
        self::assertGreaterThan(2, $intCount, 'Multiple kerning adjustments в AVAYTo');
    }

    #[Test]
    public function encodeText_still_works_without_kerning_split(): void
    {
        // Backward-compat: encodeText всегда возвращает single hex string,
        // независимо от kerning'а.
        $hex = $this->font->encodeText('AV');
        self::assertStringStartsWith('<', $hex);
        self::assertStringEndsWith('>', $hex);
    }

    #[Test]
    public function pdf_output_uses_tj_array_for_kerned_text(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->showEmbeddedText('AV', 72, 720, $this->font, 24);
        $pdf = $doc->toBytes();
        // TJ operator (uppercase) — для kerning. Tj (lowercase) — для simple.
        self::assertStringContainsString(' TJ', $pdf);
    }

    #[Test]
    public function pdf_output_uses_simple_tj_for_unkerned_text(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->showEmbeddedText('B', 72, 720, $this->font, 24);
        $pdf = $doc->toBytes();
        // Только Tj (одна буква — точно нет kerning'а).
        self::assertStringContainsString(' Tj', $pdf);
    }
}
