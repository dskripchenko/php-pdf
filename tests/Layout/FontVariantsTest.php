<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontVariantsTest extends TestCase
{
    private function loadFont(string $variant): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-'.$variant.'.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped("Liberation Sans $variant not cached.");
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function bold_run_uses_bold_font_when_registered(): void
    {
        $regular = $this->loadFont('Regular');
        $bold = $this->loadFont('Bold');

        $doc = new Document(new Section([
            new Paragraph([
                new Run('plain '),
                new Run('BOLD', (new RunStyle)->withBold()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $regular,
            boldFont: $bold,
        ));

        // Должны быть зарегистрированы два разных font'а (через
        // SubType /Type0 multi-CID).
        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(2, $subType0Count,
            'Both regular и bold variant должны быть embedded как отдельные fonts');
    }

    #[Test]
    public function italic_run_uses_italic_font_when_registered(): void
    {
        $regular = $this->loadFont('Regular');
        $italic = $this->loadFont('Italic');

        $doc = new Document(new Section([
            new Paragraph([
                new Run('plain '),
                new Run('italic', (new RunStyle)->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $regular,
            italicFont: $italic,
        ));

        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(2, $subType0Count);
    }

    #[Test]
    public function bold_italic_uses_bold_italic_variant(): void
    {
        $regular = $this->loadFont('Regular');
        $boldItalic = $this->loadFont('BoldItalic');

        $doc = new Document(new Section([
            new Paragraph([
                new Run('reg '),
                new Run('bold-italic', (new RunStyle)->withBold()->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $regular,
            boldItalicFont: $boldItalic,
        ));
        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(2, $subType0Count);
    }

    #[Test]
    public function bold_falls_back_to_default_when_no_bold_variant(): void
    {
        $regular = $this->loadFont('Regular');
        // boldFont = null → bold runs render через regular.
        $doc = new Document(new Section([
            new Paragraph([
                new Run('plain '),
                new Run('would-be-bold', (new RunStyle)->withBold()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $regular));

        // Only one Type0 font subset embedded.
        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertSame(1, $subType0Count);
    }

    #[Test]
    public function all_four_variants_register_when_all_used(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('plain '),
                new Run('bold ', (new RunStyle)->withBold()),
                new Run('italic ', (new RunStyle)->withItalic()),
                new Run('bold-italic', (new RunStyle)->withBold()->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $this->loadFont('Regular'),
            boldFont: $this->loadFont('Bold'),
            italicFont: $this->loadFont('Italic'),
            boldItalicFont: $this->loadFont('BoldItalic'),
        ));
        // Four distinct embedded fonts.
        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(4, $subType0Count);
    }

    #[Test]
    public function bold_italic_falls_back_to_bold_when_no_bold_italic(): void
    {
        $regular = $this->loadFont('Regular');
        $bold = $this->loadFont('Bold');
        $italic = $this->loadFont('Italic');

        $doc = new Document(new Section([
            new Paragraph([
                new Run('reg '),
                new Run('bi', (new RunStyle)->withBold()->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $regular,
            boldFont: $bold,
            italicFont: $italic,
            // boldItalicFont not set
        ));
        // 'reg' использует regular, 'bi' (bold+italic) falls back к bold (т.к.
        // boldItalic не задан, chain: boldItalic ?? bold). Итого 2 шрифта.
        $subType0Count = substr_count($bytes, '/Subtype /Type0');
        self::assertSame(2, $subType0Count);
    }
}
