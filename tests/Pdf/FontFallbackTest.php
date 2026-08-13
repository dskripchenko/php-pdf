<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontFallbackTest extends TestCase
{
    private function loadFont(string $path): PdfFont
    {
        $ttf = TtfFile::fromFile($path);

        return new PdfFont($ttf, 'TestFont');
    }

    #[Test]
    public function supports_text_ascii(): void
    {
        $candidates = [
            __DIR__.'/../fixtures/LiberationSans-Regular.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];
        $fontPath = null;
        foreach ($candidates as $c) {
            if (is_readable($c)) {
                $fontPath = $c;
                break;
            }
        }
        if ($fontPath === null) {
            $this->markTestSkipped('No usable font file для test');
        }
        try {
            $font = $this->loadFont($fontPath);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot load font: '.$e->getMessage());
        }

        // ASCII space + letters — should be supported by any Latin font.
        self::assertTrue($font->supportsText('Hello World'));
        self::assertTrue($font->supportsText(''));
    }

    #[Test]
    public function engine_accepts_fallback_fonts_list(): void
    {
        // Engine constructor accepts fallbackFonts list (compile-time check).
        $engine = new Engine(fallbackFonts: []);
        self::assertSame([], $engine->fallbackFonts);

        // It works with an empty chain.
        $doc = new Document(new Section([new Paragraph([new Run('Hello')])]));
        $bytes = $doc->toBytes($engine);
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function fallback_chain_field_readable(): void
    {
        $engine = new Engine;
        self::assertIsArray($engine->fallbackFonts);
        self::assertCount(0, $engine->fallbackFonts);
    }

    #[Test]
    public function missing_characters_do_not_borrow_each_others_meaning(): void
    {
        // Every missing character is drawn with the same `.notdef` glyph, so an
        // entry for it in `ToUnicode` would say "this glyph reads as the last of
        // the missing ones". A form with an empty box ☐ and a tick ✔ was
        // extracted as two ticks that way: an unchecked checkbox read as checked
        // changes what the document says.
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $font = new PdfFont(TtfFile::fromFile($path));

        // Liberation Sans has neither character — both give glyph 0.
        self::assertSame(0, $font->ttf()->glyphIdForChar(0x2610));
        self::assertSame(0, $font->ttf()->glyphIdForChar(0x2714));

        $missing = (new Document(new Section([new Paragraph([new Run('[☐✔]')])])))
            ->toBytes(new Engine(compressStreams: false, defaultFont: $font));

        // В карте извлечения не должно быть записи для глифа 0 — иначе один
        // пропавший знак прочитается как другой. Проверяем именно таблицу
        // соответствий: `<0000> <FFFF>` встречается ещё и в объявлении
        // диапазона кодов, и сравнение по всему файлу ловило бы его.
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $missing, $tables);
        foreach ($tables[1] as $table) {
            self::assertStringNotContainsString('<0000>', $table, 'глиф .notdef попал в карту извлечения');
        }

    }
}
