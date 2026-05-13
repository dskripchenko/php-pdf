<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentLangTest extends TestCase
{
    #[Test]
    public function ast_document_lang_propagates(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Hello')])]),
            lang: 'ru-RU',
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Lang (ru-RU)', $bytes);
    }

    #[Test]
    public function pdf_document_set_lang(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setLang('en-US');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Lang (en-US)', $bytes);
    }

    #[Test]
    public function no_lang_no_entry(): void
    {
        $ast = new AstDocument(new Section([new Paragraph([new Run('No lang')])]));
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Lang', $bytes);
    }

    #[Test]
    public function tagged_pdf_with_lang(): void
    {
        // Tagged PDF + /Lang ⇒ both в Catalog.
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Tagged')])]),
            tagged: true,
            lang: 'en',
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Lang (en)', $bytes);
        self::assertStringContainsString('/MarkInfo', $bytes);
    }
}
