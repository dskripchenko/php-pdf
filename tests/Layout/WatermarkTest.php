<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WatermarkTest extends TestCase
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
    public function watermark_emits_rotated_text_matrix(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('Content')])],
            watermarkText: 'DRAFT',
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Tm operator должен присутствовать (rotated text).
        self::assertStringContainsString(' Tm', $bytes);
        // Light-gray non-stroke color.
        self::assertStringContainsString('0.88 0.88 0.88 rg', $bytes);
    }

    #[Test]
    public function watermark_renders_on_every_page(): void
    {
        $body = [];
        for ($i = 0; $i < 80; $i++) {
            $body[] = new Paragraph([new Run("Filler paragraph $i text content")]);
        }
        $doc = new Document(new Section(body: $body, watermarkText: 'CONFIDENTIAL'));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount);
        // Watermark Tm matrix должен появиться ≥ pageCount раз (один на page).
        self::assertGreaterThanOrEqual($pageCount, substr_count($bytes, ' Tm'));
    }

    #[Test]
    public function no_watermark_no_rotated_text(): void
    {
        $doc = new Document(new Section([new Paragraph([new Run('Hi')])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringNotContainsString(' Tm', $bytes);
    }

    #[Test]
    public function builder_watermark_propagates_to_section(): void
    {
        $doc = DocumentBuilder::new()
            ->watermark('SAMPLE')
            ->paragraph('content')
            ->build();
        self::assertSame('SAMPLE', $doc->section->watermarkText);
        self::assertTrue($doc->section->hasWatermark());
    }

    #[Test]
    public function builder_watermark_null_disables(): void
    {
        $doc = DocumentBuilder::new()->paragraph('content')->build();
        self::assertNull($doc->section->watermarkText);
        self::assertFalse($doc->section->hasWatermark());
    }

    #[Test]
    public function watermark_visible_in_pdftotext(): void
    {
        $bytes = DocumentBuilder::new()
            ->watermark('DRAFT')
            ->paragraph('Some body text content')
            ->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'wm-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            // pdftotext НЕ reassembles rotated glyphs, символы появляются
            // на отдельных линиях — проверяем что каждый символ присутствует
            // (rotation matrix работает + glyph emitted).
            foreach (str_split('DRAFT') as $ch) {
                self::assertStringContainsString($ch, $text);
            }
            self::assertStringContainsString('Some body text', $text);
        } finally {
            @unlink($tmp);
        }
    }
}
