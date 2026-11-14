<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\Text\GlyphNames;
use Dskripchenko\PhpPdf\Pdf\Reader\Text\SimpleEncodings;
use Dskripchenko\PhpPdf\Pdf\Reader\Text\ToUnicodeMap;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextExtractorTest extends TestCase
{
    private const EXTERNAL = __DIR__.'/../../fixtures/external/';

    #[Test]
    public function reads_lines_and_words_of_a_standard_font_page(): void
    {
        $doc = new PdfDocument();
        $doc->addPage()
            ->showText('Quarterly report', 72, 720, StandardFont::HelveticaBold, 18)
            ->showText('Revenue grew', 72, 690, StandardFont::Helvetica, 12)
            ->showText('by 12%', 160, 690, StandardFont::Helvetica, 12)
            ->showText('Caf'."\u{00E9}".' — fine', 72, 600, StandardFont::TimesRoman, 12);
        $doc->addPage()->showText('Second page', 72, 720, StandardFont::Courier, 10);

        $text = ReaderDocument::fromBytes($doc->toBytes())->text();

        self::assertSame("Quarterly report\nRevenue grew by 12%\n\nCafé — fine\n\nSecond page", $text);
    }

    #[Test]
    public function reads_cyrillic_of_an_embedded_font_through_its_to_unicode_map(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (!is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $font = new PdfFont(TtfFile::fromFile($path));
        $doc = new PdfDocument();
        $doc->addPage()
            ->showEmbeddedText('Техническое задание', 72, 720, $font, 14)
            ->showEmbeddedText('Мощность двигателя: 50–500 л. с.', 72, 700, $font, 11);

        self::assertSame("Техническое задание\nМощность двигателя: 50–500 л. с.", ReaderDocument::fromBytes($doc->toBytes())->text());
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function corpus(): array
    {
        return [
            'Google Docs' => ['google-doc.pdf', ['Example document', 'Beautiful is better than ugly.', 'Special cases aren\'t special enough to break the rules.']],
            'pdflatex' => ['pdflatex-4pages.pdf', ['Hello, here is some text without a meaning.', '“Huardest gefburn”?']],
            'pdflatex, a chapter' => ['pdflatex-image.pdf', ['1 Your Chapter', 'Lorem ipsum dolor sit amet']],
            'PDF/A with ligatures' => ['crazyones-pdfa.pdf', ['The Crazy Ones', 'The misfits. The rebels.', 'who see things differently']],
            'pdfkit' => ['pdfkit.pdf', ['Header']],
            'annotated' => ['annotated.pdf', ["Line 1\nLine 2"]],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('corpus')]
    public function reads_documents_of_other_producers(string $file, array $expected): void
    {
        $text = ReaderDocument::fromBytes((string) file_get_contents(self::EXTERNAL.$file))->text();

        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $text);
        }
    }

    #[Test]
    public function a_scan_without_text_gives_nothing(): void
    {
        self::assertSame('', ReaderDocument::fromBytes((string) file_get_contents(self::EXTERNAL.'imagemagick-lzw.pdf'))->text());
    }

    #[Test]
    public function maps_codes_by_char_and_by_range_with_the_code_space(): void
    {
        $map = ToUnicodeMap::parse(<<<'CMAP'
            /CIDInit /ProcSet findresource begin
            12 dict begin
            begincmap
            1 begincodespacerange
            <0000> <FFFF>
            endcodespacerange
            2 beginbfchar
            <0003> <0020>
            <0010> <0054 0065>
            endbfchar
            2 beginbfrange
            <0020> <0022> <0410>
            <0030> <0031> [<0061> <D83D DE00>]
            endbfrange
            endcmap
            CMAP);

        self::assertSame(' ', $map->lookup("\x00\x03"));
        self::assertSame('Te', $map->lookup("\x00\x10"));
        self::assertSame('А', $map->lookup("\x00\x20"));
        self::assertSame('В', $map->lookup("\x00\x22"));
        self::assertSame('a', $map->lookup("\x00\x30"));
        self::assertSame("\u{1F600}", $map->lookup("\x00\x31"));
        self::assertNull($map->lookup("\x00\x23"));
        self::assertSame(2, $map->codeLength("\x00\x30\x00\x31", 2));
        self::assertFalse($map->isEmpty());
        self::assertNull(ToUnicodeMap::parse('begincmap endcmap')->codeLength("\x00", 0));
    }

    #[Test]
    public function names_glyphs_as_the_glyph_list_does(): void
    {
        self::assertSame('A', GlyphNames::toUnicode('A'));
        self::assertSame('é', GlyphNames::toUnicode('eacute'));
        self::assertSame('’', GlyphNames::toUnicode('quoteright'));
        self::assertSame('Я', GlyphNames::toUnicode('afii10049'));
        self::assertSame('ё', GlyphNames::toUnicode('afii10071'));
        self::assertSame('Ё', GlyphNames::toUnicode('afii10023'));
        self::assertSame('щ', GlyphNames::toUnicode('afii10091'));
        self::assertSame('ЖЗ', GlyphNames::toUnicode('uni04160417'));
        self::assertSame("\u{1F600}", GlyphNames::toUnicode('u1F600'));
        self::assertSame('fi', GlyphNames::toUnicode('f_i'));
        self::assertSame('a', GlyphNames::toUnicode('a.sc'));
        self::assertSame('½', GlyphNames::toUnicode('onehalf'));
        self::assertNull(GlyphNames::toUnicode('g123'));
        self::assertNull(GlyphNames::toUnicode('.notdef'));
    }

    #[Test]
    public function knows_the_base_encodings(): void
    {
        self::assertSame('’', SimpleEncodings::map('StandardEncoding')[0x27]);
        self::assertSame('ﬁ', SimpleEncodings::map('StandardEncoding')[0xAE]);
        self::assertSame('€', SimpleEncodings::map('WinAnsiEncoding')[0x80]);
        self::assertSame("'", SimpleEncodings::map('WinAnsiEncoding')[0x27]);
        self::assertSame('Ä', SimpleEncodings::map('MacRomanEncoding')[0x80]);
        self::assertSame('ˇ', SimpleEncodings::map('MacRomanEncoding')[0xFF]);
    }
}
