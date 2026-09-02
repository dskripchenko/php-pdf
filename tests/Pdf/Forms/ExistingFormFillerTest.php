<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use Dskripchenko\PhpPdf\Pdf\Forms\FieldTree;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
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

    /** @return list<string> AS state of every widget of $name, in fixture order */
    private function widgetStates(ReaderDocument $doc, string $name): array
    {
        $node = (new FieldTree())->build($doc)[$name];
        $states = [];
        foreach ($node->widgetObjNums as $objNum) {
            $widget = $doc->deref(new PdfReference($objNum, 0));
            $as = $widget->get('AS');
            $states[] = $as instanceof PdfName ? $as->value : '';
        }

        return $states;
    }

    #[Test]
    public function sets_a_text_field_value(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());
        $out = $filler->setValue('full_name', 'John Doe')->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        self::assertSame('John Doe', $result->fields()['full_name']->value);
    }

    #[Test]
    public function fill_sets_need_appearances_on_the_acroform(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());
        $out = $filler->setValue('full_name', 'John Doe')->toBytes();

        self::assertStringContainsString('/NeedAppearances true', $out);
    }

    #[Test]
    public function sets_a_field_reachable_only_through_parent_inheritance(): void
    {
        $path = __DIR__.'/../../fixtures/forms/parent-inherited.pdf';
        if (!is_file($path)) {
            self::markTestSkipped('Fixture parent-inherited.pdf not present');
        }

        $filler = ExistingFormFiller::fromFile($path);
        $out = $filler->setValue('employer.name', 'Acme Corp')->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        self::assertSame('Acme Corp', $result->fields()['employer.name']->value);
    }

    #[Test]
    public function flattening_a_parent_inherited_field_removes_the_acroform(): void
    {
        $path = __DIR__.'/../../fixtures/forms/parent-inherited.pdf';
        if (!is_file($path)) {
            self::markTestSkipped('Fixture parent-inherited.pdf not present');
        }

        $out = ExistingFormFiller::fromFile($path)
            ->setValue('employer.name', 'Acme Corp')
            ->flatten()
            ->toBytes();

        self::assertSame([], ExistingFormFiller::fromBytes($out)->fields());
        self::assertStringNotContainsString('/AcroForm', $out);
    }

    #[Test]
    public function setting_an_unknown_field_throws(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());
        $this->expectException(\InvalidArgumentException::class);
        $filler->setValue('does_not_exist', 'x');
    }

    #[Test]
    public function checks_a_checkbox(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('checkbox', 'subscribe', 100, 700, 14, 14);
        $bytes = $pdf->toBytes();

        $out = ExistingFormFiller::fromBytes($bytes)->setValue('subscribe', 'yes')->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        self::assertSame('Yes', $result->fields()['subscribe']->value);
        self::assertSame(['Yes'], $this->widgetStates(ReaderDocument::fromBytes($out), 'subscribe'));
    }

    #[Test]
    public function unchecks_a_checkbox(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('checkbox', 'subscribe', 100, 700, 14, 14, defaultValue: 'on');
        $bytes = $pdf->toBytes();

        $out = ExistingFormFiller::fromBytes($bytes)->setValue('subscribe', 'no')->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        self::assertSame('Off', $result->fields()['subscribe']->value);
        self::assertSame(['Off'], $this->widgetStates(ReaderDocument::fromBytes($out), 'subscribe'));
    }

    #[Test]
    public function selects_one_radio_option_and_unselects_the_rest(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('radio-group', 'size', 100, 700, 60, 14,
            options: ['S', 'M', 'L'],
            radioWidgets: [
                ['x' => 100, 'y' => 700, 'w' => 14, 'h' => 14],
                ['x' => 120, 'y' => 700, 'w' => 14, 'h' => 14],
                ['x' => 140, 'y' => 700, 'w' => 14, 'h' => 14],
            ],
        );
        $bytes = $pdf->toBytes();

        $out = ExistingFormFiller::fromBytes($bytes)->setValue('size', 'M')->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        self::assertSame('M', $result->fields()['size']->value);
        self::assertSame(['Off', 'M', 'Off'], $this->widgetStates(ReaderDocument::fromBytes($out), 'size'));
    }

    #[Test]
    public function does_not_mutate_the_original_source_document(): void
    {
        $bytes = $this->textFieldPdf();
        $filler = ExistingFormFiller::fromBytes($bytes);
        $filler->setValue('full_name', 'Changed')->toBytes();

        // A fresh filler over the same original bytes must still see the
        // original value — the mutation must not have touched the source.
        $original = ExistingFormFiller::fromBytes($bytes);
        self::assertSame('Jane Roe', $original->fields()['full_name']->value);
    }

    #[Test]
    public function flattening_all_fields_removes_the_acroform(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());
        $out = $filler->setValue('full_name', 'Baked In')->flatten()->toBytes();

        self::assertSame([], ExistingFormFiller::fromBytes($out)->fields());
        self::assertStringNotContainsString('/AcroForm', $out);
    }

    private function pageText(ReaderDocument $doc, int $index): string
    {
        $contents = $doc->pages()[$index]->dict->get('Contents');
        $streams = is_array($contents) ? $contents : [$contents];
        $parts = [];
        foreach ($streams as $entry) {
            $stream = $doc->deref($entry);
            if ($stream instanceof PdfStream) {
                $parts[] = $doc->streamData($stream);
            }
        }

        return implode("\n", $parts);
    }

    #[Test]
    public function flattened_value_is_drawn_on_the_page(): void
    {
        $filler = ExistingFormFiller::fromBytes($this->textFieldPdf());
        $out = $filler->setValue('full_name', 'Baked In')->flatten()->toBytes();

        self::assertStringContainsString('(Baked In) Tj', $this->pageText(ReaderDocument::fromBytes($out), 0));
    }

    #[Test]
    public function flattening_a_subset_leaves_other_fields_interactive(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('text', 'first', 100, 700, 200, 20, defaultValue: 'A');
        $page->addFormField('text', 'second', 100, 650, 200, 20, defaultValue: 'B');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())
            ->setValue('first', 'Baked')
            ->flatten(['first'])
            ->toBytes();

        $result = ExistingFormFiller::fromBytes($out);
        $fields = $result->fields();
        self::assertArrayNotHasKey('first', $fields);
        self::assertArrayHasKey('second', $fields);
        self::assertSame('B', $fields['second']->value);
    }

    #[Test]
    public function stamps_an_opaque_image_standalone(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        $out = ExistingFormFiller::fromBytes($bytes)
            ->stampImage(0, __DIR__ . '/../../fixtures/sample.png', 10, 20, 40, 30)
            ->toBytes();

        self::assertStringContainsString('/Subtype /Image', $out);
        self::assertMatchesRegularExpression('@/Stamp\d+ Do@', $out);
        self::assertStringNotContainsString('/SMask', $out);
    }

    #[Test]
    public function stamps_a_transparent_image_with_an_smask(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        $out = ExistingFormFiller::fromBytes($bytes)
            ->stampImage(0, __DIR__ . '/../../fixtures/1x1.png', 0, 0, 10, 10)
            ->toBytes();

        self::assertStringContainsString('/SMask', $out);
    }

    #[Test]
    public function composes_fill_flatten_and_stamp(): void
    {
        $bytes = $this->textFieldPdf();

        $out = ExistingFormFiller::fromBytes($bytes)
            ->setValue('full_name', 'Combined')
            ->flatten()
            ->stampImage(0, __DIR__ . '/../../fixtures/sample.png', 10, 10, 20, 15)
            ->toBytes();

        $doc = ReaderDocument::fromBytes($out);
        self::assertSame(1, $doc->pageCount());
        self::assertStringContainsString('(Combined) Tj', $this->pageText($doc, 0));
        self::assertStringContainsString('/Subtype /Image', $out);
    }

    #[Test]
    public function rejects_an_out_of_range_page(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();

        $this->expectException(\OutOfRangeException::class);
        ExistingFormFiller::fromBytes($pdf->toBytes())
            ->stampImage(1, __DIR__ . '/../../fixtures/sample.png', 0, 0, 10, 10);
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
