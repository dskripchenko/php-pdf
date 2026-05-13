<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Run;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphBuilderLinkTest extends TestCase
{
    #[Test]
    public function link_with_string_creates_styled_external_link(): void
    {
        $p = ParagraphBuilder::new()
            ->text('Click ')
            ->link('https://example.com', 'here')
            ->build();

        self::assertCount(2, $p->children);
        $link = $p->children[1];
        self::assertInstanceOf(Hyperlink::class, $link);
        self::assertFalse($link->isInternal());
        self::assertSame('https://example.com', $link->href);
        // Default styling: underline + blue.
        $linkText = $link->children[0];
        self::assertInstanceOf(Run::class, $linkText);
        self::assertTrue($linkText->style->underline);
        self::assertSame('0066cc', $linkText->style->color);
    }

    #[Test]
    public function link_with_closure_uses_custom_inline_content(): void
    {
        $p = ParagraphBuilder::new()
            ->link('https://x.test', fn(ParagraphBuilder $b) => $b
                ->text('prefix ')
                ->bold('bold link')
            )
            ->build();

        $link = $p->children[0];
        self::assertCount(2, $link->children);
        self::assertSame('prefix ', $link->children[0]->text);
        self::assertTrue($link->children[1]->style->bold);
    }

    #[Test]
    public function internal_link_creates_anchor_link(): void
    {
        $p = ParagraphBuilder::new()
            ->internalLink('section-2', 'go to section 2')
            ->build();
        $link = $p->children[0];
        self::assertTrue($link->isInternal());
        self::assertSame('section-2', $link->anchor);
    }

    #[Test]
    public function bookmark_without_content(): void
    {
        $p = ParagraphBuilder::new()
            ->text('Before ')
            ->bookmark('marker')
            ->text('after')
            ->build();

        $bm = $p->children[1];
        self::assertInstanceOf(Bookmark::class, $bm);
        self::assertSame('marker', $bm->name);
        self::assertSame([], $bm->children);
    }

    #[Test]
    public function bookmark_with_string_wraps_text(): void
    {
        $p = ParagraphBuilder::new()
            ->bookmark('section-1', 'Section 1 title')
            ->build();

        $bm = $p->children[0];
        self::assertSame('Section 1 title', $bm->children[0]->text);
    }

    #[Test]
    public function full_smoke_links_rendered_in_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Phase 7 Smoke')
            ->paragraph(fn(ParagraphBuilder $p) => $p
                ->bookmark('intro')
                ->text('Visit ')
                ->link('https://github.com', 'GitHub')
                ->text(' or jump to ')
                ->internalLink('end', 'the end')
                ->text('.')
            )
            ->paragraph('Some middle text.')
            ->paragraph(fn(ParagraphBuilder $p) => $p
                ->bookmark('end')
                ->text('This is the end of the document.')
            )
            ->toBytes();

        self::assertStringContainsString('/URI (https://github.com)', $bytes);
        self::assertStringContainsString('/Dest (end)', $bytes);
        self::assertStringContainsString('(intro)', $bytes);
        self::assertStringContainsString('(end)', $bytes);
    }
}
