<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The /Info dictionary is not limited to the six well-known fields —
 * ISO 32000-1 §14.3.3 allows an application to add its own, and readers
 * show them next to the rest. An application's document identifier has
 * nowhere else to live inside the file.
 */
final class CustomInfoKeysTest extends TestCase
{
    #[Test]
    public function writesCustomEntriesAlongsideTheStandardOnes(): void
    {
        $pdf = (new Document(
            new Section(body: [new Paragraph(['x'])]),
            metadata: [
                'Title' => 'Invoice 42',
                'PrintableDocumentId' => '81f87d63-eb01-43c8-8a93-6dd1a3db63e9',
            ],
        ))->toBytes();

        self::assertStringContainsString('/Title (Invoice 42)', $pdf);
        self::assertStringContainsString(
            '/PrintableDocumentId (81f87d63-eb01-43c8-8a93-6dd1a3db63e9)',
            $pdf,
        );
    }

    #[Test]
    public function rejectsKeysThatWouldBreakTheDictionary(): void
    {
        // A PDF name has no room for whitespace or delimiters: letting one
        // through would produce a dictionary readers cannot parse, and the
        // damage would surface far from the call that caused it.
        foreach (['Has Space', 'With/Slash', '1LeadingDigit', 'Скобка(', ''] as $key) {
            try {
                (new Document(
                    new Section(body: [new Paragraph(['x'])]),
                    metadata: ['Title' => 'ok', $key => 'value'],
                ))->toBytes();
                self::fail("ключ «{$key}» принят, хотя ломает словарь");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function customValuesOutsideAsciiStayReadable(): void
    {
        $pdf = (new Document(
            new Section(body: [new Paragraph(['x'])]),
            metadata: ['PrintableIssuer' => 'ООО «Ромашка»'],
        ))->toBytes();

        // Same rule as the standard fields: non-ASCII goes out as UTF-16BE
        // with a BOM, otherwise readers show mojibake.
        self::assertMatchesRegularExpression('/\/PrintableIssuer\s*<FEFF[0-9A-F]+>/', $pdf);
    }
}
