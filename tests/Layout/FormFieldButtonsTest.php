<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormFieldButtonsTest extends TestCase
{
    #[Test]
    public function submit_button_emits_submit_form_action(): void
    {
        $doc = new Document(new Section([
            new FormField('submit', FormField::TYPE_SUBMIT_BUTTON,
                buttonCaption: 'Send', submitUrl: 'https://api.example.com/form'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Btn', $bytes);
        self::assertStringContainsString('/S /SubmitForm', $bytes);
        self::assertStringContainsString('https://api.example.com/form', $bytes);
        // Pushbutton flag (bit 17 = 65536).
        self::assertMatchesRegularExpression('@/Ff\s+65536@', $bytes);
        // Caption в MK dict.
        self::assertStringContainsString('/CA (Send)', $bytes);
    }

    #[Test]
    public function reset_button_emits_reset_form_action(): void
    {
        $doc = new Document(new Section([
            new FormField('reset', FormField::TYPE_RESET_BUTTON, buttonCaption: 'Clear'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /ResetForm', $bytes);
        self::assertStringContainsString('/CA (Clear)', $bytes);
    }

    #[Test]
    public function push_button_with_click_script(): void
    {
        $doc = new Document(new Section([
            new FormField('act', FormField::TYPE_PUSH_BUTTON,
                buttonCaption: 'Action',
                clickScript: 'app.alert("Hello!");'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /JavaScript', $bytes);
        self::assertStringContainsString('Hello!', $bytes);
    }

    #[Test]
    public function push_button_default_caption_is_type_name(): void
    {
        $doc = new Document(new Section([
            new FormField('btn', FormField::TYPE_PUSH_BUTTON),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/CA (Push)', $bytes);
    }

    #[Test]
    public function button_flags_include_pushbutton_bit(): void
    {
        $doc = new Document(new Section([
            new FormField('btn', FormField::TYPE_RESET_BUTTON, readOnly: true),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 65536 (Pushbutton) | 1 (ReadOnly) = 65537.
        self::assertMatchesRegularExpression('@/Ff\s+65537@', $bytes);
    }
}
