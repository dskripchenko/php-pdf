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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphPaddingTest extends TestCase
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
    public function paragraph_background_emits_fill_rect(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('with bg')],
                style: new ParagraphStyle(backgroundColor: 'ffff99'),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Yellow bg fill: '1 1 0.6... rg' + 're' + 'f'
        self::assertMatchesRegularExpression('@1\s+1\s+0\.6\d*\s+rg@', $bytes);
        self::assertStringContainsString(" re\n", $bytes);
    }

    #[Test]
    public function paragraph_padding_shifts_content(): void
    {
        $docPlain = new Document(new Section([
            new Paragraph([new Run('content')]),
        ]));
        $docPadded = new Document(new Section([
            new Paragraph(
                children: [new Run('content')],
                style: new ParagraphStyle(
                    paddingTopPt: 12,
                    paddingRightPt: 12,
                    paddingBottomPt: 12,
                    paddingLeftPt: 12,
                ),
            ),
        ]));
        $b1 = $docPlain->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $b2 = $docPadded->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertNotSame($b1, $b2);
    }

    #[Test]
    public function background_and_padding_combined(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('callout box')],
                style: new ParagraphStyle(
                    paddingTopPt: 8,
                    paddingRightPt: 12,
                    paddingBottomPt: 8,
                    paddingLeftPt: 12,
                    backgroundColor: 'eeeeee',
                ),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(defaultFont: $this->font()));
        self::assertStringStartsWith('%PDF', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'pp-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('callout box', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function no_padding_no_bg_no_overhead(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('plain')]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // No 're' for plain paragraph (no bg, no padding).
        self::assertStringNotContainsString(" re\n", $bytes);
    }
}
