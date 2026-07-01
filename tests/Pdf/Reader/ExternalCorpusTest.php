<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfParseException;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the reader/merge subsystem against real third-party PDFs from
 * diverse producers (pdfTeX, LibreOffice, Google Docs/Skia, Qt/pdfkit,
 * Ghostscript, ImageMagick, FPDF2, pypdf). See fixtures/external/README.md.
 *
 * Round-tripping only against php-pdf's own output can mask spec deviations;
 * this suite is the guard against that.
 */
final class ExternalCorpusTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/external';

    /** @return iterable<string, array{string, int}> file => expected page count */
    public static function corpus(): iterable
    {
        yield 'w3c-dummy' => ['w3c-dummy.pdf', 1];
        yield 'pypdf-minimal' => ['pypdf-minimal.pdf', 1];
        yield 'pypdf-libreoffice' => ['pypdf-libreoffice.pdf', 1];
        yield 'pdflatex-image' => ['pdflatex-image.pdf', 1];
        yield 'pdflatex-4pages' => ['pdflatex-4pages.pdf', 4];
        yield 'pdflatex-outline' => ['pdflatex-outline.pdf', 4];
        yield 'imagemagick-lzw' => ['imagemagick-lzw.pdf', 1];
        yield 'imagemagick-ccitt' => ['imagemagick-ccitt.pdf', 1];
        yield 'google-doc' => ['google-doc.pdf', 1];
        yield 'pdfkit' => ['pdfkit.pdf', 1];
        yield 'crazyones-pdfa' => ['crazyones-pdfa.pdf', 1];
        yield 'cropped-rotated-scaled' => ['cropped-rotated-scaled.pdf', 4];
        yield 'annotated' => ['annotated.pdf', 1];
    }

    private function read(string $file, string $password = ''): ReaderDocument
    {
        $path = self::DIR . '/' . $file;
        if (!is_file($path)) {
            self::markTestSkipped("Fixture {$file} not present");
        }
        return ReaderDocument::fromBytes((string) file_get_contents($path), $password);
    }

    #[Test]
    #[DataProvider('corpus')]
    public function reads_page_count_and_geometry(string $file, int $expectedPages): void
    {
        $doc = $this->read($file);
        self::assertSame($expectedPages, $doc->pageCount(), $file);

        $page = $doc->pages()[0];
        self::assertGreaterThan(0, $page->width(), "{$file} width");
        self::assertGreaterThan(0, $page->height(), "{$file} height");
    }

    #[Test]
    #[DataProvider('corpus')]
    public function decodes_first_page_content_without_error(string $file, int $expectedPages): void
    {
        $doc = $this->read($file);
        $contents = $doc->deref($doc->pages()[0]->dict->get('Contents'));
        $streams = is_array($contents) ? $contents : [$contents];

        $decoded = 0;
        foreach ($streams as $entry) {
            $stream = $doc->deref($entry);
            if ($stream instanceof PdfStream) {
                $decoded += strlen($doc->streamData($stream)); // must not throw
            }
        }
        self::assertGreaterThanOrEqual(0, $decoded, $file);
        unset($expectedPages);
    }

    #[Test]
    public function opens_encrypted_libreoffice_with_user_password(): void
    {
        $doc = $this->read('libreoffice-password.pdf', 'openpassword');
        self::assertSame(1, $doc->pageCount());

        // Content decrypts to real operators (foreign RC4-128 producer).
        $contents = $doc->deref($doc->pages()[0]->dict->get('Contents'));
        $stream = $doc->deref(is_array($contents) ? $contents[0] : $contents);
        self::assertInstanceOf(PdfStream::class, $stream);
        self::assertStringContainsString('BT', $doc->streamData($stream));
    }

    #[Test]
    public function wrong_password_fails_fast_with_clear_error(): void
    {
        $path = self::DIR . '/libreoffice-password.pdf';
        if (!is_file($path)) {
            self::markTestSkipped('encrypted fixture not present');
        }
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('wrong or missing password');
        ReaderDocument::fromBytes((string) file_get_contents($path), 'not-the-password');
    }

    #[Test]
    public function decodes_foreign_lzw_image_stream(): void
    {
        // imagemagick-lzw.pdf compresses its image with LZWDecode — exercises
        // our LZW decoder against a foreign encoder.
        $doc = $this->read('imagemagick-lzw.pdf');
        $resources = $doc->deref($doc->pages()[0]->dict->get('Resources'));
        self::assertInstanceOf(PdfDictionary::class, $resources);
        $xobjects = $doc->deref($resources->get('XObject'));
        self::assertInstanceOf(PdfDictionary::class, $xobjects);

        $decodedAny = false;
        foreach ($xobjects->all() as $ref) {
            $img = $doc->deref($ref);
            if ($img instanceof PdfStream) {
                self::assertNotSame('', $doc->streamData($img)); // LZW decode must not throw / be empty
                $decodedAny = true;
            }
        }
        self::assertTrue($decodedAny, 'expected an image XObject');
    }

    #[Test]
    public function merges_pages_across_producers(): void
    {
        foreach (['w3c-dummy.pdf', 'pdflatex-4pages.pdf', 'google-doc.pdf'] as $f) {
            if (!is_file(self::DIR . '/' . $f)) {
                self::markTestSkipped("missing {$f}");
            }
        }

        $bytes = PdfMerger::create()
            ->append(PdfSource::fromFile(self::DIR . '/w3c-dummy.pdf'))          // 1
            ->append(PdfSource::fromFile(self::DIR . '/pdflatex-4pages.pdf'))    // 4
            ->append(PdfSource::fromFile(self::DIR . '/google-doc.pdf'), pages: [1]) // 1
            ->toBytes();

        self::assertSame(6, ReaderDocument::fromBytes($bytes)->pageCount());
    }

    #[Test]
    public function stamps_a_real_page_as_overlay(): void
    {
        foreach (['pdflatex-4pages.pdf', 'w3c-dummy.pdf'] as $f) {
            if (!is_file(self::DIR . '/' . $f)) {
                self::markTestSkipped("missing {$f}");
            }
        }

        $bytes = PdfMerger::create()
            ->append(PdfSource::fromFile(self::DIR . '/pdflatex-4pages.pdf'))
            ->stamp(PdfSource::fromFile(self::DIR . '/w3c-dummy.pdf'), onPages: [1])
            ->toBytes();

        $out = ReaderDocument::fromBytes($bytes);
        self::assertSame(4, $out->pageCount());
        $resources = $out->deref($out->pages()[0]->dict->get('Resources'));
        self::assertInstanceOf(PdfDictionary::class, $out->deref($resources->get('XObject')));
    }
}
