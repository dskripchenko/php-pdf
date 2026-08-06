<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A PDF text string outside ASCII must be UTF-16BE with a byte order mark
 * (ISO 32000-1 §7.9.2.2).
 *
 * A literal string is read as PDFDocEncoding, so UTF-8 bytes reach the
 * reader as mojibake: a Cyrillic /Info Author showed up as `ÐžÐžÐž` in every
 * viewer, pdfinfo included. Only strings the spec calls text strings are
 * affected — URIs and file specifications stay literal.
 */
final class TextStringEncodingTest extends TestCase
{
    #[Test]
    public function nonAsciiMetadataIsReadableByViewers(): void
    {
        $pdf = (new Document(
            new Section(body: [new Paragraph(['x'])]),
            metadata: ['Title' => 'Счёт СЧ-42', 'Author' => 'ООО «Ромашка»'],
        ))->toBytes();

        self::assertSame('Счёт СЧ-42', self::infoValue($pdf, 'Title'));
        self::assertSame('ООО «Ромашка»', self::infoValue($pdf, 'Author'));

        // The raw UTF-8 bytes must be gone: that encoding is precisely what
        // readers misinterpret.
        self::assertStringNotContainsString('ООО «Ромашка»', $pdf);
    }

    #[Test]
    public function asciiMetadataStaysALiteralString(): void
    {
        $pdf = (new Document(
            new Section(body: [new Paragraph(['x'])]),
            metadata: ['Title' => 'Invoice 42'],
        ))->toBytes();

        self::assertStringContainsString('/Title (Invoice 42)', $pdf);
    }

    /** Reads an /Info entry back the way a viewer would. */
    private static function infoValue(string $pdf, string $key): ?string
    {
        if (preg_match('/\/'.$key.'\s*<([0-9A-Fa-f]+)>/', $pdf, $m) === 1) {
            $bytes = (string) hex2bin($m[1]);

            return str_starts_with($bytes, "\xFE\xFF")
                ? (string) iconv('UTF-16BE', 'UTF-8', substr($bytes, 2))
                : $bytes;
        }

        return preg_match('/\/'.$key.'\s*\(([^)]*)\)/', $pdf, $m) === 1 ? $m[1] : null;
    }
}
