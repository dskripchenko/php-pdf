<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 231: Heading anchor + named destinations.
 */
final class HeadingAnchorTest extends TestCase
{
    #[Test]
    public function heading_with_anchor_emits_named_dest(): void
    {
        $doc = new Document(new Section([
            new Heading(1, [new Run('Chapter One')], anchor: 'chapter-1'),
            new Paragraph([new Run('Content')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Named destination "chapter-1" в /Names tree.
        self::assertStringContainsString('(chapter-1)', $bytes);
    }

    #[Test]
    public function heading_without_anchor_no_dest(): void
    {
        $doc = new Document(new Section([
            new Heading(1, [new Run('No anchor')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // No /Dests entry для no anchor.
        self::assertStringNotContainsString('/Dests', $bytes);
    }

    #[Test]
    public function internal_hyperlink_resolves_к_heading_anchor(): void
    {
        $doc = new Document(new Section([
            new Heading(1, [new Run('Section 1')], anchor: 'sec-1'),
            new Paragraph([new Run('Content')]),
            new Heading(1, [new Run('Section 2')], anchor: 'sec-2'),
            new Paragraph([
                Hyperlink::internal('sec-1', [new Run('back к first section')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Both anchors registered.
        self::assertStringContainsString('(sec-1)', $bytes);
        self::assertStringContainsString('(sec-2)', $bytes);
    }

    #[Test]
    public function auto_anchor_derives_slug(): void
    {
        $h = new Heading(1, [new Run('Hello World — Foo Bar 123')]);
        self::assertSame('hello-world-foo-bar-123', $h->autoAnchor());
    }

    #[Test]
    public function auto_anchor_unicode(): void
    {
        $h = new Heading(2, [new Run('Привет, Мир!')]);
        self::assertSame('привет-мир', $h->autoAnchor());
    }

    #[Test]
    public function html_id_attribute_becomes_anchor(): void
    {
        $blocks = (new HtmlParser)->parse('<h1 id="intro">Introduction</h1>');
        $h = $blocks[0];
        self::assertInstanceOf(Heading::class, $h);
        self::assertSame('intro', $h->anchor);
    }

    #[Test]
    public function html_no_id_attribute_no_anchor(): void
    {
        $blocks = (new HtmlParser)->parse('<h1>No ID here</h1>');
        $h = $blocks[0];
        self::assertNull($h->anchor);
    }

    #[Test]
    public function html_anchor_with_internal_link(): void
    {
        $blocks = (new HtmlParser)->parse(
            '<h1 id="top">Top</h1>
             <p>Some content</p>
             <p><a href="#top">Back to top</a></p>'
        );
        // Heading has anchor.
        self::assertSame('top', $blocks[0]->anchor);
        // Hyperlink references it.
        $linkPara = $blocks[2];
        $link = $linkPara->children[0];
        self::assertInstanceOf(Hyperlink::class, $link);
        self::assertTrue($link->isInternal());
        self::assertSame('top', $link->anchor);
    }

    #[Test]
    public function rejects_invalid_heading_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Heading(7, [new Run('too deep')], anchor: 'x');
    }
}
