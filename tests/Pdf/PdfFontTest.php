<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfFontTest extends TestCase
{
    private string $liberationSansPath;

    protected function setUp(): void
    {
        $this->liberationSansPath = __DIR__
            .'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        if (! is_readable($this->liberationSansPath)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
    }

    #[Test]
    public function encode_text_produces_hex_glyph_id_string(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);

        $hex = $font->encodeText('Hi');
        // H → glyph 43 (0x002B), i → glyph 76 (0x004C) или похожий.
        // Ожидаем `<NNNNMMMM>` — 4-hex per glyph.
        self::assertMatchesRegularExpression('/^<[0-9A-F]{8}>$/', $hex);
        // Начинается с glyph ID для H.
        self::assertStringStartsWith('<002B', $hex);
    }

    #[Test]
    public function encodes_cyrillic_correctly(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        $hex = $font->encodeText('А'); // U+0410
        self::assertMatchesRegularExpression('/^<[0-9A-F]{4}>$/', $hex);
        // glyph ID 961 = 0x03C1
        self::assertSame('<03C1>', $hex);
    }

    #[Test]
    public function widthOfCharPdfUnits_returns_thousandths_of_em(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        // H advance = 1479 FU; 2048 em → 1479 * 1000 / 2048 ≈ 722.
        $w = $font->widthOfCharPdfUnits(0x48);
        self::assertEqualsWithDelta(722, $w, 1);
    }

    #[Test]
    public function register_creates_all_required_objects(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        // Encode something first — accumulates usedGlyphs.
        $font->encodeText('Hello');

        $writer = new Writer;
        $fontId = $font->registerWith($writer);
        // Create dummy catalog to allow toBytes().
        $catalogId = $writer->addObject("<< /Type /Catalog /Pages $fontId 0 R >>");
        $writer->setRoot($catalogId);
        $pdf = $writer->toBytes();

        // Должны быть созданы 5 объектов:
        //  1. FontFile2 stream (TTF binary)
        //  2. FontDescriptor
        //  3. CIDFontType2
        //  4. ToUnicode CMap stream
        //  5. Type0 font dict
        self::assertStringContainsString('/Type /Font /Subtype /Type0', $pdf);
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringContainsString('/Type /FontDescriptor', $pdf);
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertStringContainsString('/Encoding /Identity-H', $pdf);
        self::assertStringContainsString('/ToUnicode', $pdf);
        self::assertStringContainsString('/BaseFont /LiberationSans', $pdf);
        // ToUnicode CMap должен содержать bfchar entries для использованных glyph'ов
        self::assertStringContainsString('beginbfchar', $pdf);
    }

    #[Test]
    public function double_register_returns_same_id(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        $writer = new Writer;
        $id1 = $font->registerWith($writer);
        $id2 = $font->registerWith($writer);
        self::assertSame($id1, $id2);
    }

    #[Test]
    public function flags_for_fixed_pitch_mono(): void
    {
        $monoPath = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationMono-Regular.ttf';
        if (! is_readable($monoPath)) {
            self::markTestSkipped('Mono not cached.');
        }
        $ttf = TtfFile::fromFile($monoPath);
        $font = new PdfFont($ttf);
        $font->encodeText('x');
        $writer = new Writer;
        $font->registerWith($writer);
        $writer->setRoot($writer->addObject('<< /Type /Catalog /Pages 1 0 R >>'));
        $pdf = $writer->toBytes();
        // Bit 1 (FixedPitch) + bit 6 (Nonsymbolic) = 33.
        self::assertMatchesRegularExpression('/\/Flags 33[^0-9]/', $pdf);
    }

    #[Test]
    public function emoji_chars_get_notdef_glyph(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        // 😀 (U+1F600) — не покрывается Liberation; glyph ID = 0 (.notdef).
        $hex = $font->encodeText('😀');
        self::assertSame('<0000>', $hex);
    }
}
