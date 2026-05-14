<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcroFormDefaultAppearanceTest extends TestCase
{
    #[Test]
    public function da_string_emitted(): void
    {
        $doc = new Document(new Section([
            new FormField('name', FormField::TYPE_TEXT),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /DA с font reference + size + color.
        self::assertStringContainsString('/DA (/Helv 11 Tf 0 g)', $bytes);
    }

    #[Test]
    public function dr_dict_includes_helv_font(): void
    {
        $doc = new Document(new Section([
            new FormField('name', FormField::TYPE_TEXT),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/DR << /Font << /Helv\s+\d+\s+0\s+R >> >>@', $bytes);
        // Helvetica font object emitted.
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
    }

    #[Test]
    public function no_form_fields_no_da(): void
    {
        $doc = new Document(new Section([]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/DA ', $bytes);
        self::assertStringNotContainsString('/DR ', $bytes);
    }
}
