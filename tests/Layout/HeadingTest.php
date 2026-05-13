<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadingTest extends TestCase
{
    #[Test]
    public function heading_renders_text_with_larger_font(): void
    {
        $doc = new Document(new Section([
            new Heading(1, [new Run('Title')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Title) Tj', $bytes);
        // H1 default = 24pt → Tf size 24.
        self::assertMatchesRegularExpression('@\s24\s+Tf@', $bytes);
    }

    #[Test]
    public function heading_levels_have_distinct_sizes(): void
    {
        $sizes = [1 => 24, 2 => 20, 3 => 16, 4 => 14, 5 => 12, 6 => 11];
        foreach ($sizes as $level => $size) {
            $doc = new Document(new Section([
                new Heading($level, [new Run("H$level")]),
            ]));
            $bytes = $doc->toBytes(new Engine(compressStreams: false));
            self::assertMatchesRegularExpression(
                '@\s'.$size.'\s+Tf@',
                $bytes,
                "H$level should use font size $size",
            );
        }
    }

    #[Test]
    public function heading_level_out_of_range_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Heading(0, [new Run('X')]);
    }

    #[Test]
    public function heading_level_7_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Heading(7, [new Run('X')]);
    }

    #[Test]
    public function tagged_pdf_emits_h_struct_element(): void
    {
        $ast = new Document(
            new Section([
                new Heading(1, [new Run('Title')]),
                new Paragraph([new Run('Body text')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // Heading wrapped в /H1 BDC.
        self::assertStringContainsString('/H1 << /MCID 0 >> BDC', $bytes);
        // Paragraph wrapped в /P BDC.
        self::assertStringContainsString('/P << /MCID 1 >> BDC', $bytes);
        // StructElem с /S /H1.
        self::assertStringContainsString('/S /H1', $bytes);
        // StructElem с /S /P (для paragraph).
        self::assertStringContainsString('/S /P', $bytes);
    }

    #[Test]
    public function multiple_heading_levels_in_tagged_mode(): void
    {
        $ast = new Document(
            new Section([
                new Heading(1, [new Run('Main')]),
                new Heading(2, [new Run('Sub')]),
                new Heading(3, [new Run('Sub-sub')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /H1', $bytes);
        self::assertStringContainsString('/S /H2', $bytes);
        self::assertStringContainsString('/S /H3', $bytes);
    }

    #[Test]
    public function untagged_heading_no_bdc(): void
    {
        $doc = new Document(new Section([
            new Heading(1, [new Run('Title')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('BDC', $bytes);
    }
}
