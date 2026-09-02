<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fill an existing PDF's own AcroForm (a template designed elsewhere), as
 * opposed to {@see \Dskripchenko\PhpPdf\Element\FormField}, which authors a
 * new form from scratch.
 */
final class ExistingFormFillerTest extends TestCase
{
    private function textFieldPdf(bool $objStm = false): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        if ($objStm) {
            $pdf->useObjectStreams(true);
        }
        $page = $pdf->addPage();
        $page->addFormField('text', 'full_name', 100, 700, 200, 20, defaultValue: 'Jane Roe');

        return $pdf->toBytes();
    }

    #[Test]
    public function enumerates_a_text_field(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());

        $fields = $filler->fields();

        self::assertArrayHasKey('full_name', $fields);
        $field = $fields['full_name'];
        self::assertSame('full_name', $field->name);
        self::assertSame('text', $field->type);
        self::assertSame('Jane Roe', $field->value);
        self::assertFalse($field->required);
        self::assertFalse($field->readOnly);
    }

    #[Test]
    public function enumerates_fields_from_a_compressed_xref_source(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf(objStm: true));

        $fields = $filler->fields();

        self::assertArrayHasKey('full_name', $fields);
        self::assertSame('Jane Roe', $fields['full_name']->value);
    }

    #[Test]
    public function reads_a_required_readonly_field(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('text', 'locked', 0, 0, 100, 20, required: true, readOnly: true);

        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());
        $field = $filler->fields()['locked'];

        self::assertTrue($field->required);
        self::assertTrue($field->readOnly);
    }

    #[Test]
    public function opens_from_a_file_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php-pdf-forms-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $this->textFieldPdf());
            $filler = ExistingFormFiller::fromFile($path);
            self::assertArrayHasKey('full_name', $filler->fields());
        } finally {
            unlink($path);
        }
    }
}
