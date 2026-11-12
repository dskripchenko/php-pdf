<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * encodeText() and encodeTextTjArray() against a plain reference: shape with
 * shapedGlyphs(), kern each pair with kerningPdfUnits(), write each glyph as
 * sprintf('%04X', cidForGlyph()). Both the output and what the font records
 * for its ToUnicode map (usedGlyphs, in order) must match.
 */
final class PdfFontTextEncodingTest extends TestCase
{
    private const TEXTS = [
        '',
        'A',
        'AVAST Toward WAVE Yacht',
        // After plain spaces: a glyph shared with the space must now name U+00A0.
        "x\u{A0}y",
        'office affine flow',
        "caf\u{E9} na\u{EF}ve \u{A0}a\u{A0}b c",
        'Привет, мир',
        'שלום עולם',
        'مرحبا بالعالم',
        'mixed AV שלום text',
        "emoji \u{1F600} end",
        "broken \xC3( and \xFF bytes",
        '12 1 15 21',
    ];

    /** @return iterable<string, array{string, bool}> */
    public static function fonts(): iterable
    {
        yield 'TrueType with kerning' => ['liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf', true];
        yield 'CFF with ligatures' => ['SourceSans3-Regular.otf', true];
        // Space and no-break space share one glyph here, so the order of
        // usedGlyphs writes decides which one ToUnicode names.
        yield 'CFF without ligatures' => ['SourceSans3-Regular.otf', false];
        yield 'Arabic' => ['Amiri-Regular.ttf', true];
    }

    #[Test]
    #[DataProvider('fonts')]
    public function tj_array_matches_the_reference(string $file, bool $ligatures): void
    {
        $reference = $this->font($file, $ligatures);
        $font = $this->font($file, $ligatures);
        foreach ([...self::TEXTS, ...self::TEXTS] as $text) {
            self::assertSame(self::referenceTjArray($reference, $text), @$font->encodeTextTjArray($text), $text);
            self::assertSame(self::usedGlyphs($reference), self::usedGlyphs($font), $text);
        }
    }

    #[Test]
    #[DataProvider('fonts')]
    public function hex_string_matches_the_reference(string $file, bool $ligatures): void
    {
        $reference = $this->font($file, $ligatures);
        $font = $this->font($file, $ligatures);
        foreach (self::TEXTS as $text) {
            $hex = '';
            foreach (@$reference->shapedGlyphs($text) as $shaped) {
                $hex .= sprintf('%04X', $reference->ttf()->cidForGlyph($shaped['gid']));
            }
            self::assertSame('<'.$hex.'>', @$font->encodeText($text), $text);
            self::assertSame(self::usedGlyphs($reference), self::usedGlyphs($font), $text);
        }
    }

    #[Test]
    public function decoding_below_hebrew_maps_each_codepoint_through_the_cmap(): void
    {
        $font = $this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf', true);
        $ttf = $font->ttf();
        $expected = [];
        foreach ([0x41, 0xE9, 0x416, 0x1F600, 0x20] as $cp) {
            $expected[] = ['cp' => $cp, 'gid' => $ttf->glyphIdForChar($cp)];
        }
        self::assertSame($expected, $font->decodeUtf8("A\u{E9}\u{416}\u{1F600} "));
        self::assertSame([], $font->decodeUtf8(''));
    }

    private function font(string $file, bool $ligatures): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/'.$file;
        if (! is_readable($path)) {
            self::markTestSkipped($file.' not cached — run scripts/fetch-fonts.sh.');
        }
        $font = new PdfFont(TtfFile::fromFile($path));

        return $ligatures ? $font : $font->disableLigatures();
    }

    /** @return list<string|int> */
    private static function referenceTjArray(PdfFont $font, string $text): array
    {
        $result = [];
        $run = '';
        $prev = null;
        foreach (@$font->shapedGlyphs($text) as $shaped) {
            $gid = $shaped['gid'];
            if ($prev !== null && ($kern = $font->kerningPdfUnits($prev, $gid)) !== 0) {
                $result[] = '<'.$run.'>';
                $result[] = $kern;
                $run = '';
            }
            $run .= sprintf('%04X', $font->ttf()->cidForGlyph($gid));
            $prev = $gid;
        }
        if ($run !== '') {
            $result[] = '<'.$run.'>';
        }

        return $result;
    }

    /** @return array<int, list<int>> */
    private static function usedGlyphs(PdfFont $font): array
    {
        return (new \ReflectionProperty(PdfFont::class, 'usedGlyphs'))->getValue($font);
    }
}
