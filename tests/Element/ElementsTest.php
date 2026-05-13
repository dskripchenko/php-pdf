<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Element;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\InlineElement;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ElementsTest extends TestCase
{
    #[Test]
    public function run_has_text_and_style(): void
    {
        $r = new Run('Hello', (new RunStyle)->withBold());
        self::assertSame('Hello', $r->text);
        self::assertTrue($r->style->bold);
        self::assertInstanceOf(InlineElement::class, $r);
    }

    #[Test]
    public function paragraph_holds_inlines(): void
    {
        $p = new Paragraph([
            new Run('Hello '),
            new Run('world', (new RunStyle)->withBold()),
        ]);
        self::assertCount(2, $p->children);
        self::assertInstanceOf(BlockElement::class, $p);
        self::assertNull($p->headingLevel);
    }

    #[Test]
    public function heading_has_level(): void
    {
        $h = new Paragraph(children: [new Run('Title')], headingLevel: 1);
        self::assertSame(1, $h->headingLevel);
    }

    #[Test]
    public function paragraph_style_alignment(): void
    {
        $p = new Paragraph(
            children: [new Run('x')],
            style: new ParagraphStyle(alignment: Alignment::Center),
        );
        self::assertSame(Alignment::Center, $p->style->alignment);
    }

    #[Test]
    public function pagebreak_is_block_and_inline(): void
    {
        $pb = new PageBreak;
        self::assertInstanceOf(BlockElement::class, $pb);
        self::assertInstanceOf(InlineElement::class, $pb);
    }

    #[Test]
    public function linebreak_is_inline_only(): void
    {
        $lb = new LineBreak;
        self::assertInstanceOf(InlineElement::class, $lb);
        self::assertNotInstanceOf(BlockElement::class, $lb);
    }

    #[Test]
    public function horizontalrule_is_block(): void
    {
        $hr = new HorizontalRule;
        self::assertInstanceOf(BlockElement::class, $hr);
    }

    #[Test]
    public function hyperlink_external_vs_internal(): void
    {
        $ext = Hyperlink::external('https://example.com', [new Run('Link')]);
        self::assertFalse($ext->isInternal());
        self::assertSame('https://example.com', $ext->href);
        self::assertNull($ext->anchor);

        $int = Hyperlink::internal('chapter1', [new Run('go')]);
        self::assertTrue($int->isInternal());
        self::assertSame('chapter1', $int->anchor);
        self::assertNull($int->href);
    }

    #[Test]
    public function bookmark_has_name_and_children(): void
    {
        $b = new Bookmark('section1', [new Run('Section 1')]);
        self::assertSame('section1', $b->name);
        self::assertCount(1, $b->children);

        $empty = new Bookmark('mark');
        self::assertSame([], $empty->children);
    }

    #[Test]
    public function field_factories(): void
    {
        self::assertSame('PAGE', Field::page()->kind());
        self::assertSame('NUMPAGES', Field::totalPages()->kind());
        self::assertSame('DATE', Field::date('yyyy-MM-dd')->kind());
        self::assertSame('yyyy-MM-dd', Field::date('yyyy-MM-dd')->format());
        self::assertSame('CustomerName', Field::mergeField('CustomerName')->format());
    }

    #[Test]
    public function field_with_no_format_returns_empty(): void
    {
        $f = Field::page();
        self::assertSame('', $f->format());
    }

    #[Test]
    public function paragraph_isEmpty(): void
    {
        self::assertTrue((new Paragraph)->isEmpty());
        self::assertFalse((new Paragraph([new Run('x')]))->isEmpty());
    }
}
