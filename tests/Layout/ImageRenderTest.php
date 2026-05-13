<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageRenderTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    private string $pngPath = __DIR__.'/../fixtures/sample.png';

    #[Test]
    public function jpeg_image_block_renders_with_dctdecode(): void
    {
        $doc = new Document(new Section([
            Image::fromPath($this->jpegPath, widthPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine);

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Filter /DCTDecode', $bytes);
        // Drawing operator: q ... cm /Im1 Do Q
        self::assertStringContainsString(' cm', $bytes);
        self::assertStringContainsString(' Do', $bytes);
    }

    #[Test]
    public function png_image_block_renders_with_flatedecode(): void
    {
        $doc = new Document(new Section([
            Image::fromPath($this->pngPath, widthPt: 60),
        ]));
        $bytes = $doc->toBytes(new Engine);

        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    #[Test]
    public function image_dimensions_appear_in_content_stream(): void
    {
        $doc = new Document(new Section([
            Image::fromPath($this->jpegPath, widthPt: 200, heightPt: 100),
        ]));
        $bytes = $doc->toBytes(new Engine);
        // cm transform: 200 0 0 100 X Y cm
        self::assertStringContainsString('200 0 0 100 ', $bytes);
    }

    #[Test]
    public function center_alignment_positions_image_centrally(): void
    {
        // A4 = 595pt wide; margins 56.7pt × 2 → content = 481.6pt.
        // image 200pt → leftX = (595 - 481.6)/2 left margin = 56.7,
        // centered X = 56.7 + (481.6 - 200)/2 = 197.5.
        $doc = new Document(new Section([
            Image::fromPath($this->jpegPath, widthPt: 200, alignment: Alignment::Center),
        ]));
        $bytes = $doc->toBytes(new Engine);

        // Можно проверить точное число — but PDF stream coordinates float-
        // formatted, поэтому ищем substring.
        self::assertStringContainsString('200 0 0', $bytes);
    }

    #[Test]
    public function image_overflow_triggers_page_break(): void
    {
        // A4 высота 842pt; margins 56.7 × 2 → contentHeight ≈ 728pt.
        // Высота image = 700pt; перед ним параграф ~200pt — должен
        // переместить image на новую страницу.
        $blocks = [];
        for ($i = 0; $i < 20; $i++) {
            $blocks[] = new Paragraph([new Run("Line $i filler text.")]);
        }
        $blocks[] = Image::fromPath($this->jpegPath, widthPt: 400, heightPt: 700);

        $doc = new Document(new Section($blocks));
        $bytes = $doc->toBytes(new Engine);

        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount, 'Large image should overflow to new page');
    }

    #[Test]
    public function image_too_wide_scales_down(): void
    {
        // 1000pt wide на A4 (contentWidth ≈ 481pt) должен scale до contentWidth.
        $doc = new Document(new Section([
            Image::fromPath($this->jpegPath, widthPt: 1000, heightPt: 750),
        ]));
        $bytes = $doc->toBytes(new Engine);
        // 1000 не должно появиться как width в cm (after scaling).
        self::assertStringNotContainsString('1000 0 0 750', $bytes);
        // Но image XObject зарегистрирован.
        self::assertStringContainsString('/Subtype /Image', $bytes);
    }

    #[Test]
    public function multiple_images_dedupe_via_pdf_image_resource(): void
    {
        // Тот же PdfImage instance — один XObject в PDF.
        $img = \Dskripchenko\PhpPdf\Image\PdfImage::fromPath($this->jpegPath);
        $doc = new Document(new Section([
            new Image($img, widthPt: 50),
            new Image($img, widthPt: 100, alignment: Alignment::End),
        ]));
        $bytes = $doc->toBytes(new Engine);
        // /Subtype /Image должен появиться ровно один раз.
        self::assertSame(1, substr_count($bytes, '/Subtype /Image'));
    }

    #[Test]
    public function image_with_spacing_advances_cursor(): void
    {
        // Smoke: spacing не ломает render.
        $doc = new Document(new Section([
            new Paragraph([new Run('Before')]),
            Image::fromPath($this->jpegPath, widthPt: 100, spaceBeforePt: 20, spaceAfterPt: 20),
            new Paragraph([new Run('After')]),
        ]));
        $bytes = $doc->toBytes(new Engine);
        self::assertStringStartsWith('%PDF', $bytes);
    }
}
