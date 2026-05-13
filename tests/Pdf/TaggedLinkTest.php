<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaggedLinkTest extends TestCase
{
    #[Test]
    public function uri_link_emits_link_struct_with_objr(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([
                    Hyperlink::external('https://example.com', [new Run('Click here')]),
                ]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /Link struct element с /OBJR reference.
        self::assertStringContainsString('/S /Link', $bytes);
        self::assertMatchesRegularExpression('@/K << /Type /OBJR /Obj \d+\s+0\s+R >>@', $bytes);
    }

    #[Test]
    public function untagged_link_no_link_struct(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph([
                Hyperlink::external('https://example.com', [new Run('Click')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/S /Link', $bytes);
        // Link annotation сохраняется и без tagging.
        self::assertStringContainsString('/Subtype /Link', $bytes);
    }

    #[Test]
    public function multiple_links_separate_structs(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([
                    Hyperlink::external('https://a.com', [new Run('A')]),
                    new Run(' and '),
                    Hyperlink::external('https://b.com', [new Run('B')]),
                ]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertSame(2, substr_count($bytes, '/S /Link'));
    }
}
