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

final class SupSubSizingTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function superscript_text_uses_smaller_font_size(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('H'),
                new Run('2', (new RunStyle)->withSuperscript()->withSizePt(11)),
                new Run('O'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Sup '2' uses 11 × 0.7 = 7.7pt → '7.7 Tf'.
        self::assertMatchesRegularExpression('@7\.7 Tf@', $bytes);
    }

    #[Test]
    public function subscript_text_uses_smaller_font_size(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('CO'),
                new Run('2', (new RunStyle)->withSubscript()->withSizePt(11)),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertMatchesRegularExpression('@7\.7 Tf@', $bytes);
    }

    #[Test]
    public function plain_text_no_size_reduction(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('plain', (new RunStyle)->withSizePt(11))]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // 11 Tf, not 7.7 Tf.
        self::assertStringContainsString(' 11 Tf', $bytes);
        self::assertStringNotContainsString('7.7 Tf', $bytes);
    }
}
