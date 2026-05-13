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
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LetterSpacingTest extends TestCase
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
    public function letter_spacing_emits_Tc_operator(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('spaced', (new RunStyle)->withLetterSpacingPt(2)),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Tc operator: '2 Tc' (character spacing 2pt)
        self::assertStringContainsString("2 Tc\n", $bytes);
    }

    #[Test]
    public function zero_letter_spacing_no_Tc_emitted(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('plain')]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringNotContainsString(' Tc', $bytes);
    }

    #[Test]
    public function inherited_letter_spacing_from_default_run_style(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('inherit')],
                defaultRunStyle: (new RunStyle)->withLetterSpacingPt(1.5),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringContainsString('1.5 Tc', $bytes);
    }

    #[Test]
    public function line_height_multiplier_applied(): void
    {
        $doc1 = new Document(new Section([
            new Paragraph(
                children: [str_repeat('Word ', 30) ? new Run(str_repeat('Word ', 30)) : new Run('')],
                style: new ParagraphStyle(lineHeightMult: 1.0),
            ),
        ]));
        $doc2 = new Document(new Section([
            new Paragraph(
                children: [new Run(str_repeat('Word ', 30))],
                style: new ParagraphStyle(lineHeightMult: 2.0),
            ),
        ]));

        $b1 = $doc1->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        $b2 = $doc2->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Different line-heights → different cursorY positions → different output.
        self::assertNotSame($b1, $b2);
    }
}
