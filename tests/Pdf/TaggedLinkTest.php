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

        // A /Link struct element with an /OBJR reference.
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
        // The link annotation survives without tagging too.
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

    // -------- Phase 151: /StructParent on link annotations --------

    #[Test]
    public function tagged_link_emits_structparent(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([
                    Hyperlink::external('https://example.com', [new Run('Click')]),
                ]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // The /Link annot includes /StructParent N (where N ≥ the page count = 1).
        self::assertStringContainsString('/Subtype /Link', $bytes);
        self::assertMatchesRegularExpression('@/StructParent \d+@', $bytes);
    }

    #[Test]
    public function untagged_link_no_structparent(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph([
                Hyperlink::external('https://example.com', [new Run('Click')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Without tagging no /StructParent is emitted on the links.
        self::assertStringNotContainsString('/StructParent', $bytes);
    }

    #[Test]
    public function parenttree_includes_link_entries(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([
                    Hyperlink::external('https://a.com', [new Run('A')]),
                ]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // The /Nums array of the ParentTree should map a struct-parent key (≥1)
        // to a struct element reference. With 1 page and 1 link: key 0 (the page
        // array form) and key 1 (the link single ref).
        self::assertStringContainsString('/Nums', $bytes);
        // Check ParentTreeNextKey reflects link count (2 = pages + 1 link).
        self::assertMatchesRegularExpression('@/ParentTreeNextKey 2\b@', $bytes);
    }
}
