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
        // H → glyph 43 (0x002B), i → glyph 76 (0x004C) or thereabouts.
        // `<NNNNMMMM>` is expected — 4 hex digits per glyph.
        self::assertMatchesRegularExpression('/^<[0-9A-F]{8}>$/', $hex);
        // It starts with the glyph ID of H.
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

        // Five objects have to be created:
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
        self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+LiberationSans ~', $pdf);
        // The ToUnicode CMap has to hold bfchar entries for the glyphs used
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
        // 😀 (U+1F600) is not covered by Liberation; the glyph ID is 0 (.notdef).
        $hex = $font->encodeText('😀');
        self::assertSame('<0000>', $hex);
    }

    #[Test]
    public function a_subset_font_is_named_with_a_six_letter_tag(): void
    {
        $font = new PdfFont(TtfFile::fromFile($this->liberationSansPath));
        $font->encodeText('Hello');
        $pdf = $this->embed($font);

        // ISO 32000-1 §9.6.4: the same tag on both /BaseFont entries and /FontName.
        self::assertSame(1, preg_match('~/Subtype /Type0 /BaseFont /([A-Z]{6})\+LiberationSans ~', $pdf, $m));
        self::assertStringContainsString('/Subtype /CIDFontType2 /BaseFont /'.$m[1].'+LiberationSans ', $pdf);
        self::assertStringContainsString('/FontName /'.$m[1].'+LiberationSans ', $pdf);
    }

    #[Test]
    public function different_subsets_of_one_font_get_different_tags(): void
    {
        $tag = function (string $text): string {
            $font = new PdfFont(TtfFile::fromFile($this->liberationSansPath));
            $font->encodeText($text);
            preg_match('~/FontName /([A-Z]{6})\+~', $this->embed($font), $m);

            return $m[1];
        };

        self::assertNotSame($tag('Hello'), $tag('World'));
        self::assertSame($tag('Hello'), $tag('Hello'), 'the same subset must get the same tag');
    }

    #[Test]
    public function a_font_embedded_whole_has_no_tag(): void
    {
        $font = new PdfFont(TtfFile::fromFile($this->liberationSansPath), subset: false);
        $font->encodeText('Hello');
        $pdf = $this->embed($font);

        self::assertStringContainsString('/BaseFont /LiberationSans ', $pdf);
        self::assertStringContainsString('/FontName /LiberationSans ', $pdf);
        self::assertDoesNotMatchRegularExpression('~/[A-Z]{6}\+LiberationSans~', $pdf);
    }

    #[Test]
    public function delimiters_in_a_font_name_are_escaped(): void
    {
        // Same-length swap of the PostScript name in both name-table encodings.
        $bytes = (string) file_get_contents($this->liberationSansPath);
        $bytes = str_replace('LiberationSans', 'Liberation(S/s', $bytes);
        $bytes = str_replace(
            mb_convert_encoding('LiberationSans', 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding('Liberation(S/s', 'UTF-16BE', 'UTF-8'),
            $bytes,
        );
        $ttf = new TtfFile($bytes);
        self::assertSame('Liberation(S/s', $ttf->postScriptName());

        $font = new PdfFont($ttf, subset: false);
        $font->encodeText('Hi');
        $pdf = $this->embed($font);

        self::assertStringContainsString('/BaseFont /Liberation#28S#2Fs ', $pdf);
        self::assertStringContainsString('/FontName /Liberation#28S#2Fs ', $pdf);
    }

    #[Test]
    public function a_subset_font_lists_every_glyph_of_the_subset_in_a_cid_set(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $font = new PdfFont($ttf);
        // "é" is a composite glyph in Liberation Sans; its components come in
        // the subset too.
        $font->encodeText('Hé');
        $pdf = $this->embed($font, compress: false);

        self::assertSame(1, preg_match('~/FontDescriptor .*?/CIDSet (\d+) 0 R~', $pdf, $m));
        self::assertSame(1, preg_match('~\b'.$m[1].' 0 obj\s*<< /Length (\d+) >>\nstream\n~', $pdf, $s, PREG_OFFSET_CAPTURE));
        $cidSet = substr($pdf, $s[0][1] + strlen($s[0][0]), (int) $s[1][0]);

        // The subset keeps the glyph numbering, so every GID below numGlyphs
        // is in the font program (ISO 19005-2 §6.2.11.4.2) — and nothing past it.
        $numGlyphs = $ttf->numGlyphs();
        self::assertSame(intdiv($numGlyphs + 7, 8), strlen($cidSet));
        for ($gid = 0; $gid < strlen($cidSet) * 8; $gid++) {
            $set = (ord($cidSet[$gid >> 3]) & (0x80 >> ($gid & 7))) !== 0;
            self::assertSame($gid < $numGlyphs, $set, "CID $gid");
        }
        self::assertSame($numGlyphs, $this->subsetNumGlyphs($pdf));
    }

    #[Test]
    public function a_font_embedded_whole_has_no_cid_set(): void
    {
        $font = new PdfFont(TtfFile::fromFile($this->liberationSansPath), subset: false);
        $font->encodeText('Hello');

        self::assertStringNotContainsString('/CIDSet', $this->embed($font));
    }

    #[Test]
    public function a_cff_font_has_no_cid_set(): void
    {
        $font = new PdfFont(TtfFile::fromFile(__DIR__.'/../fixtures/fonts/MiniCff-Regular.otf'));
        $font->encodeText('A');

        self::assertStringNotContainsString('/CIDSet', $this->embed($font));
    }

    /** numGlyphs in the `maxp` table of the embedded (uncompressed) FontFile2. */
    private function subsetNumGlyphs(string $pdf): int
    {
        self::assertSame(1, preg_match('~<< /Length (\d+) /Length1 \d+ >>\nstream\n~', $pdf, $m, PREG_OFFSET_CAPTURE));
        $ttf = new TtfFile(substr($pdf, $m[0][1] + strlen($m[0][0]), (int) $m[1][0]));

        return $ttf->numGlyphs();
    }

    private function embed(PdfFont $font, bool $compress = true): string
    {
        $writer = new Writer;
        $fontId = $font->registerWith($writer, $compress);
        $writer->setRoot($writer->addObject("<< /Type /Catalog /Pages $fontId 0 R >>"));

        return $writer->toBytes();
    }
}
