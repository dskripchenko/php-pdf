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

        // С пустым chain works.
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
}
