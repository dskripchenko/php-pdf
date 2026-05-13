<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageAltTextTest extends TestCase
{
    private string $jpegPath = __DIR__.'/../fixtures/sample.jpg';

    #[Test]
    public function tagged_image_emits_figure_struct_element(): void
    {
        $ast = new Document(
            new Section([Image::fromPath($this->jpegPath, widthPt: 100, altText: 'Sample photo')]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /Figure struct element + /Alt entry.
        self::assertStringContainsString('/S /Figure', $bytes);
        self::assertStringContainsString('/Alt (Sample photo)', $bytes);
        // BDC/EMC wrapping в content stream.
        self::assertStringContainsString('/Figure << /MCID 0 >> BDC', $bytes);
        self::assertStringContainsString('EMC', $bytes);
    }

    #[Test]
    public function image_without_alttext_no_alt_entry(): void
    {
        $ast = new Document(
            new Section([Image::fromPath($this->jpegPath, widthPt: 100)]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /Figure', $bytes);
        // No /Alt entry.
        self::assertDoesNotMatchRegularExpression('@/Alt\s+\(@', $bytes);
    }

    #[Test]
    public function untagged_image_no_struct_element(): void
    {
        $doc = new Document(new Section([
            Image::fromPath($this->jpegPath, widthPt: 100, altText: 'Ignored'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/S /Figure', $bytes);
        self::assertStringNotContainsString('BDC', $bytes);
    }

    #[Test]
    public function mixed_paragraph_and_image_separately_tagged(): void
    {
        $ast = new Document(
            new Section([
                new Paragraph([new Run('Intro')]),
                Image::fromPath($this->jpegPath, widthPt: 100, altText: 'Diagram'),
                new Paragraph([new Run('Outro')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // Sequence: /P (MCID 0), /Figure (MCID 1), /P (MCID 2).
        self::assertStringContainsString('/P << /MCID 0 >> BDC', $bytes);
        self::assertStringContainsString('/Figure << /MCID 1 >> BDC', $bytes);
        self::assertStringContainsString('/P << /MCID 2 >> BDC', $bytes);
        // 3 struct elements.
        self::assertSame(3, substr_count($bytes, '/Type /StructElem'));
    }

    #[Test]
    public function alt_text_special_chars_escaped(): void
    {
        $ast = new Document(
            new Section([Image::fromPath($this->jpegPath, widthPt: 100, altText: 'Has (parens) and \\backslash')]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // PDF literal string escapes ( ) \ → \( \) \\.
        self::assertStringContainsString('/Alt (Has \\(parens\\) and \\\\backslash)', $bytes);
    }
}
