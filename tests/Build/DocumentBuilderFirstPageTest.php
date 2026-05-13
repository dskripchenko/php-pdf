<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\HeaderFooterBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderFirstPageTest extends TestCase
{
    #[Test]
    public function first_page_header_propagates(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h->paragraph('reg'))
            ->firstPageHeader(fn(HeaderFooterBuilder $h) => $h->paragraph('first'))
            ->paragraph('body')
            ->build();

        self::assertNotNull($doc->section->firstPageHeaderBlocks);
        self::assertCount(1, $doc->section->firstPageHeaderBlocks);
    }

    #[Test]
    public function no_header_footer_on_first_page_helper(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h->paragraph('reg'))
            ->footer(fn(HeaderFooterBuilder $f) => $f->paragraph('reg-f'))
            ->noHeaderFooterOnFirstPage()
            ->paragraph('body')
            ->build();

        self::assertSame([], $doc->section->firstPageHeaderBlocks);
        self::assertSame([], $doc->section->firstPageFooterBlocks);
    }

    #[Test]
    public function effective_blocks_falls_back_to_regular_when_null(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h->paragraph('reg'))
            ->paragraph('body')
            ->build();

        // First-page header не задан явно → возвращает обычный.
        self::assertCount(1, $doc->section->effectiveHeaderBlocksFor(1));
        self::assertCount(1, $doc->section->effectiveHeaderBlocksFor(5));
    }

    #[Test]
    public function full_smoke_renders_cover_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h->paragraph(fn(ParagraphBuilder $p) => $p
                ->alignRight()->text('Internal use')))
            ->footer(fn(HeaderFooterBuilder $f) => $f->paragraph(fn(ParagraphBuilder $p) => $p
                ->alignCenter()->text('Page ')->pageNumber()->text(' of ')->totalPages()))
            ->noHeaderFooterOnFirstPage()
            ->heading(1, 'Cover Page')
            ->paragraph('Cover content without header/footer.')
            ->pageBreak()
            ->heading(1, 'Page 2 — with header')
            ->paragraph('Regular header/footer kick in.')
            ->toBytes();

        self::assertStringStartsWith('%PDF', $bytes);
    }
}
