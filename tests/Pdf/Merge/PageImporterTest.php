<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\PageImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FPDI-style page import into a freshly generated php-pdf document
 * (imported page as a Form XObject + new content drawn over it).
 */
final class PageImporterTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/external';

    private function src(string $file): ReaderDocument
    {
        $path = self::DIR . '/' . $file;
        if (!is_file($path)) {
            self::markTestSkipped("Fixture {$file} not present");
        }
        return ReaderDocument::fromBytes((string) file_get_contents($path));
    }

    private function firstForm(ReaderDocument $doc): ?PdfStream
    {
        $resources = $doc->deref($doc->pages()[0]->dict->get('Resources'));
        $xobjects = $resources instanceof PdfDictionary ? $doc->deref($resources->get('XObject')) : null;
        if (!$xobjects instanceof PdfDictionary) {
            return null;
        }
        foreach ($xobjects->all() as $ref) {
            $obj = $doc->deref($ref);
            if ($obj instanceof PdfStream && ($obj->dict->get('Subtype')?->value ?? '') === 'Form') {
                return $obj;
            }
        }
        return null;
    }

    private function pageContent(ReaderDocument $doc): string
    {
        $contents = $doc->deref($doc->pages()[0]->dict->get('Contents'));
        $list = is_array($contents) ? $contents : [$contents];
        $out = '';
        foreach ($list as $entry) {
            $s = $doc->deref($entry);
            if ($s instanceof PdfStream) {
                $out .= $doc->streamData($s);
            }
        }
        return $out;
    }

    #[Test]
    public function imports_page_as_form_and_draws_over_it(): void
    {
        $src = $this->src('pdflatex-image.pdf');

        $doc = new PdfDocument();
        $page = $doc->addPage(customDimensionsPt: [595.0, 842.0]);
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0.0, 0.0, 595.0, 842.0);
        $page->showText('DRAFT', 200.0, 400.0, StandardFont::Helvetica, 48.0);

        $out = ReaderDocument::fromBytes($doc->toBytes());
        self::assertSame(1, $out->pageCount());

        // Page invokes the imported form (Do) and carries the new text.
        $content = $this->pageContent($out);
        self::assertStringContainsString('Do', $content);
        self::assertStringContainsString('DRAFT', $content);
    }

    #[Test]
    public function imported_form_carries_its_own_resources(): void
    {
        $src = $this->src('pdflatex-image.pdf');
        $doc = new PdfDocument();
        $page = $doc->addPage(customDimensionsPt: [595.0, 842.0]);
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0.0, 0.0, 595.0, 842.0);

        $out = ReaderDocument::fromBytes($doc->toBytes());
        $formStream = $this->firstForm($out);
        self::assertInstanceOf(PdfStream::class, $formStream);

        // The form has a non-empty /Resources (foreign fonts/images injected).
        $res = $out->deref($formStream->dict->get('Resources'));
        self::assertInstanceOf(PdfDictionary::class, $res);
        self::assertNotSame([], $res->all());

        // The image XObject from the source resolves as a real stream.
        $xobj = $out->deref($res->get('XObject'));
        if ($xobj instanceof PdfDictionary) {
            foreach ($xobj->all() as $ref) {
                self::assertInstanceOf(PdfStream::class, $out->deref($ref));
            }
        }
    }

    #[Test]
    public function bbox_is_upright_page_size(): void
    {
        $src = $this->src('pdflatex-image.pdf');
        [$w, $h] = PageImporter::pageSize($src, 0);

        $doc = new PdfDocument();
        $page = $doc->addPage();
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0.0, 0.0, $w, $h);

        $out = ReaderDocument::fromBytes($doc->toBytes());
        $formStream = $this->firstForm($out);
        self::assertInstanceOf(PdfStream::class, $formStream);
        $bbox = array_map('floatval', $formStream->dict->get('BBox'));
        self::assertSame([0.0, 0.0, $w, $h], $bbox);
    }

    #[Test]
    public function rejects_out_of_range_page(): void
    {
        $src = $this->src('pdflatex-image.pdf');
        $this->expectException(\OutOfRangeException::class);
        PageImporter::intoDocument(new PdfDocument(), $src, 5);
    }

    #[Test]
    public function works_with_encrypted_output(): void
    {
        if (!\Dskripchenko\PhpPdf\Pdf\Encryption::aesAvailable()) {
            self::markTestSkipped('openssl AES not available');
        }
        $src = $this->src('pdflatex-image.pdf');
        $doc = new PdfDocument();
        $page = $doc->addPage(customDimensionsPt: [595.0, 842.0]);
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0.0, 0.0, 595.0, 842.0);
        $page->showText('CONF', 200.0, 400.0, StandardFont::Helvetica, 40.0);
        $doc->encrypt('', algorithm: \Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm::Aes_128);

        $out = ReaderDocument::fromBytes($doc->toBytes());
        self::assertSame(1, $out->pageCount());
        self::assertStringContainsString('CONF', $this->pageContent($out));
    }

    #[Test]
    public function works_with_object_stream_output(): void
    {
        $src = $this->src('pdflatex-image.pdf');
        $doc = new PdfDocument();
        $page = $doc->addPage(customDimensionsPt: [595.0, 842.0]);
        $form = PageImporter::intoDocument($doc, $src, 0);
        $page->useFormXObject($form, 0.0, 0.0, 595.0, 842.0);
        $doc->useObjectStreams(true);

        $out = ReaderDocument::fromBytes($doc->toBytes());
        self::assertSame(1, $out->pageCount());
        self::assertInstanceOf(PdfStream::class, $this->firstForm($out));
    }
}
