<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormFieldTest extends TestCase
{
    #[Test]
    public function text_field_emits_acroform_dict(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Name:')]),
            new FormField('name', FormField::TYPE_TEXT, defaultValue: 'John'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Catalog references AcroForm.
        self::assertMatchesRegularExpression('@/AcroForm\s+\d+\s+0\s+R@', $bytes);
        // AcroForm dict has Fields array.
        self::assertStringContainsString('/Fields [', $bytes);
        // Widget annotation present.
        self::assertStringContainsString('/Subtype /Widget', $bytes);
        self::assertStringContainsString('/FT /Tx', $bytes);
        // Field name + default value visible.
        self::assertStringContainsString('(name)', $bytes);
        self::assertStringContainsString('(John)', $bytes);
    }

    #[Test]
    public function checkbox_field_emits_btn_with_appearance_state(): void
    {
        $doc = new Document(new Section([
            new FormField('agree', FormField::TYPE_CHECKBOX, defaultValue: 'yes'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Btn', $bytes);
        // checked → /V /Yes + /AS /Yes.
        self::assertStringContainsString('/V /Yes', $bytes);
        self::assertStringContainsString('/AS /Yes', $bytes);
    }

    #[Test]
    public function checkbox_unchecked_off_state(): void
    {
        $doc = new Document(new Section([
            new FormField('subscribe', FormField::TYPE_CHECKBOX),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/V /Off', $bytes);
        self::assertStringContainsString('/AS /Off', $bytes);
    }

    #[Test]
    public function read_only_and_required_flags(): void
    {
        $doc = new Document(new Section([
            new FormField('email', FormField::TYPE_TEXT,
                required: true, readOnly: false),
            new FormField('locked', FormField::TYPE_TEXT,
                required: false, readOnly: true),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Required = bit 2 = value 2; ReadOnly = bit 1 = value 1.
        self::assertMatchesRegularExpression('@/Ff 2\b@', $bytes);
        self::assertMatchesRegularExpression('@/Ff 1\b@', $bytes);
    }

    #[Test]
    public function tooltip_emitted_as_tu(): void
    {
        $doc = new Document(new Section([
            new FormField('phone', FormField::TYPE_TEXT,
                tooltip: 'Enter phone number'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/TU (Enter phone number)', $bytes);
    }

    #[Test]
    public function multiple_fields_all_in_fields_array(): void
    {
        $doc = new Document(new Section([
            new FormField('first', FormField::TYPE_TEXT),
            new FormField('second', FormField::TYPE_TEXT),
            new FormField('agree', FormField::TYPE_CHECKBOX),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Fields array должен иметь 3 references.
        preg_match('@/Fields \[([^\]]+)\]@', $bytes, $m);
        self::assertNotEmpty($m);
        $refs = preg_match_all('@\d+\s+0\s+R@', $m[1]);
        self::assertSame(3, $refs);
    }

    #[Test]
    public function no_form_fields_no_acroform(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Plain document')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/AcroForm', $bytes);
    }

    #[Test]
    public function invalid_type_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormField('x', 'radio');
    }

    #[Test]
    public function field_widget_border_drawn(): void
    {
        // visual border = strokeRect → emits S operator.
        $doc = new Document(new Section([
            new FormField('name', FormField::TYPE_TEXT),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('S', $bytes);
    }
}
