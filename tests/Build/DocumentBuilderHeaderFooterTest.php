<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\HeaderFooterBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderHeaderFooterTest extends TestCase
{
    #[Test]
    public function header_blocks_propagate_to_section(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h
                ->paragraph(fn(ParagraphBuilder $p) => $p->alignRight()->text('Confidential'))
            )
            ->paragraph('Body')
            ->build();

        self::assertCount(1, $doc->section->headerBlocks);
        self::assertTrue($doc->section->hasHeader());
        self::assertFalse($doc->section->hasFooter());
    }

    #[Test]
    public function footer_blocks_propagate_to_section(): void
    {
        $doc = DocumentBuilder::new()
            ->footer(fn(HeaderFooterBuilder $f) => $f
                ->paragraph(fn(ParagraphBuilder $p) => $p
                    ->alignCenter()
                    ->text('Page ')
                    ->pageNumber()
                    ->text(' of ')
                    ->totalPages()
                )
            )
            ->paragraph('Body')
            ->build();

        self::assertCount(1, $doc->section->footerBlocks);
        self::assertTrue($doc->section->hasFooter());
    }

    #[Test]
    public function full_smoke_renders_pdf_with_field_resolution(): void
    {
        $bytes = DocumentBuilder::new()
            ->header(fn(HeaderFooterBuilder $h) => $h
                ->paragraph(fn(ParagraphBuilder $p) => $p
                    ->alignRight()->text('My Company — Confidential')
                )
            )
            ->footer(fn(HeaderFooterBuilder $f) => $f
                ->paragraph(fn(ParagraphBuilder $p) => $p
                    ->alignCenter()->text('Page ')->pageNumber()->text(' of ')->totalPages()
                )
            )
            ->heading(1, 'Document')
            ->paragraph('Body text on first page.')
            ->toBytes();

        // Just a sanity smoke — bytes start with %PDF + content present.
        self::assertStringStartsWith('%PDF', $bytes);
        // Verify по pdftotext'у (без embedded шрифта текст эмитится в
        // WinAnsi, не как plain ASCII в bytes).
        $tmp = tempnam(sys_get_temp_dir(), 'hf-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('My Company', $text);
            self::assertStringContainsString('Document', $text);
        } finally {
            @unlink($tmp);
        }
    }
}
