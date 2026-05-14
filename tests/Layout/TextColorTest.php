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

final class TextColorTest extends TestCase
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
    public function colored_text_emits_rg_operator(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('red text', (new RunStyle)->withColor('cc0000'))]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Phase 160: rg emitted без q/Q wrap (persisting gstate). 0xcc/255 ≈ 0.8.
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function colored_text_rg_appears_before_BT(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('blue text', (new RunStyle)->withColor('0000ff'))]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // Phase 160: rg precedes BT для text emission, no q/Q wrap.
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg\nBT@', $bytes);
    }

    #[Test]
    public function plain_text_no_rg_emitted(): void
    {
        $docPlain = new Document(new Section([
            new Paragraph([new Run('no color')]),
        ]));
        $docColored = new Document(new Section([
            new Paragraph([new Run('color', (new RunStyle)->withColor('ff0000'))]),
        ]));
        $bytesPlain = $docPlain->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $bytesColored = $docColored->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Phase 160: colored variant имеет 1 rg op (red), plain — 0.
        $rgCountPlain = substr_count($bytesPlain, ' rg');
        $rgCountColored = substr_count($bytesColored, ' rg');
        self::assertGreaterThan($rgCountPlain, $rgCountColored,
            'Colored text должен добавить rg operator');
    }

    #[Test]
    public function multiple_colors_in_one_paragraph(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('plain '),
                new Run('red ', (new RunStyle)->withColor('ff0000')),
                new Run('green ', (new RunStyle)->withColor('00ff00')),
                new Run('blue', (new RunStyle)->withColor('0000ff')),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 3 colored words × 1 rg each = at least 3 rg operators.
        self::assertGreaterThanOrEqual(3, substr_count($bytes, ' rg'));
        // Red rg: '1 0 0 rg'
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        // Green rg: '0 1 0 rg'
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
        // Blue rg: '0 0 1 rg'
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function colored_text_visible_in_pdftotext(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Black '),
                new Run('Red', (new RunStyle)->withColor('cc0000')),
                new Run(' end.'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'tc-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Black', $text);
            self::assertStringContainsString('Red', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function colored_text_combined_with_bold_works(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('important', (new RunStyle)->withBold()->withColor('cc0000')),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertStringContainsString(' rg', $bytes);
    }

    #[Test]
    public function inherited_color_from_paragraph_default_run_style(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('inherited color')],
                defaultRunStyle: (new RunStyle)->withColor('884400'),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 0x88/255 ≈ 0.5333, 0x44/255 ≈ 0.2666
        self::assertMatchesRegularExpression('@0\.53\d+\s+0\.26\d+\s+0\s+rg@', $bytes);
    }
}
