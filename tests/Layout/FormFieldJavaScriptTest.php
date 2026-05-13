<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormFieldJavaScriptTest extends TestCase
{
    #[Test]
    public function validate_script_emits_aa_v_entry(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'email', FormField::TYPE_TEXT,
                validateScript: 'if (!event.value.match(/@/)) { app.alert("Invalid email"); event.rc = false; }',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /AA dict с /V reference.
        self::assertMatchesRegularExpression('@/AA << /V\s+\d+\s+0\s+R >>@', $bytes);
        // Action object с /S /JavaScript + script.
        self::assertStringContainsString('/Type /Action /S /JavaScript /JS (', $bytes);
        self::assertStringContainsString('Invalid email', $bytes);
    }

    #[Test]
    public function calculate_script_emits_c_entry(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'total', FormField::TYPE_TEXT,
                calculateScript: 'event.value = this.getField("a").value * this.getField("b").value;',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA << /C\s+\d+\s+0\s+R >>@', $bytes);
    }

    #[Test]
    public function format_script_emits_f_entry(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'price', FormField::TYPE_TEXT,
                formatScript: 'event.value = "$" + util.printf("%.2f", event.value);',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA << /F\s+\d+\s+0\s+R >>@', $bytes);
    }

    #[Test]
    public function keystroke_script_emits_k_entry(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'digits', FormField::TYPE_TEXT,
                keystrokeScript: 'if (event.change && !event.change.match(/[0-9]/)) event.rc = false;',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA << /K\s+\d+\s+0\s+R >>@', $bytes);
    }

    #[Test]
    public function multiple_scripts_combined_in_aa(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'qty', FormField::TYPE_TEXT,
                validateScript: 'event.rc = event.value > 0;',
                formatScript: 'event.value = parseInt(event.value);',
                keystrokeScript: 'if (event.change && !/^\\d$/.test(event.change)) event.rc = false;',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA <<.*?/K\s+\d+\s+0\s+R.*?/V\s+\d+\s+0\s+R.*?/F\s+\d+\s+0\s+R.*?>>@s', $bytes);
    }

    #[Test]
    public function no_scripts_no_aa_entry(): void
    {
        $doc = new Document(new Section([
            new FormField('plain', FormField::TYPE_TEXT),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/AA', $bytes);
    }

    #[Test]
    public function script_works_on_combo_field(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'country', FormField::TYPE_COMBO,
                options: ['USA', 'Canada'],
                validateScript: 'event.rc = true;',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA << /V@', $bytes);
    }

    #[Test]
    public function script_works_on_checkbox(): void
    {
        $doc = new Document(new Section([
            new FormField(
                'agree', FormField::TYPE_CHECKBOX,
                validateScript: 'event.rc = event.value == "Yes";',
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/AA << /V@', $bytes);
    }
}
