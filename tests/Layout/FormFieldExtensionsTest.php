<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormFieldExtensionsTest extends TestCase
{
    #[Test]
    public function multiline_text_field_sets_multiline_flag(): void
    {
        $doc = new Document(new Section([
            new FormField('notes', FormField::TYPE_TEXT_MULTILINE, heightPt: 80),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Tx', $bytes);
        // Multiline flag bit 13 = 4096.
        self::assertMatchesRegularExpression('@/Ff\s+4096\b@', $bytes);
    }

    #[Test]
    public function password_field_sets_password_flag(): void
    {
        $doc = new Document(new Section([
            new FormField('pwd', FormField::TYPE_PASSWORD),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Tx', $bytes);
        // Password flag bit 14 = 8192.
        self::assertMatchesRegularExpression('@/Ff\s+8192\b@', $bytes);
    }

    #[Test]
    public function combo_box_emits_choice_field_with_options(): void
    {
        $doc = new Document(new Section([
            new FormField('country', FormField::TYPE_COMBO,
                defaultValue: 'USA',
                options: ['USA', 'Canada', 'Mexico']),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Ch', $bytes);
        // Combo flag bit 18 = 131072.
        self::assertMatchesRegularExpression('@/Ff\s+131072\b@', $bytes);
        // /Opt array.
        self::assertStringContainsString('/Opt [(USA) (Canada) (Mexico)]', $bytes);
        // Default value emitted.
        self::assertStringContainsString('/V (USA)', $bytes);
    }

    #[Test]
    public function list_field_no_combo_flag(): void
    {
        $doc = new Document(new Section([
            new FormField('cat', FormField::TYPE_LIST,
                options: ['A', 'B', 'C']),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Ch', $bytes);
        // List = /Ch без Combo flag — /Ff 0 (или not present).
        self::assertDoesNotMatchRegularExpression('@/Ff\s+131072\b@', $bytes);
    }

    #[Test]
    public function radio_group_emits_parent_plus_kids(): void
    {
        $doc = new Document(new Section([
            new FormField('size', FormField::TYPE_RADIO_GROUP,
                defaultValue: 'Medium',
                options: ['Small', 'Medium', 'Large']),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Parent — Button с Radio (32768) + NoToggleToOff (16384) = 49152 flag.
        self::assertMatchesRegularExpression('@/Ff\s+49152\b@', $bytes);
        // /Kids array references 3 child widgets.
        self::assertMatchesRegularExpression('@/Kids \[\d+\s+0\s+R\s+\d+\s+0\s+R\s+\d+\s+0\s+R\]@', $bytes);
        // Default value /V /Medium (sanitized name).
        self::assertStringContainsString('/V /Medium', $bytes);
        // Selected widget /AS /Medium.
        self::assertStringContainsString('/AS /Medium', $bytes);
        // Unselected widgets /AS /Off.
        $offCount = substr_count($bytes, '/AS /Off');
        self::assertSame(2, $offCount);
    }

    #[Test]
    public function radio_group_labels_rendered_next_to_buttons(): void
    {
        $doc = new Document(new Section([
            new FormField('color', FormField::TYPE_RADIO_GROUP,
                options: ['Red', 'Green', 'Blue']),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Red) Tj', $bytes);
        self::assertStringContainsString('(Green) Tj', $bytes);
        self::assertStringContainsString('(Blue) Tj', $bytes);
    }

    #[Test]
    public function combo_without_options_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormField('x', FormField::TYPE_COMBO);
    }

    #[Test]
    public function radio_without_options_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormField('x', FormField::TYPE_RADIO_GROUP);
    }

    #[Test]
    public function text_field_still_works_unchanged(): void
    {
        // Regression: original Phase 43 text field flow.
        $doc = new Document(new Section([
            new FormField('name', FormField::TYPE_TEXT, defaultValue: 'John'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/FT /Tx', $bytes);
        self::assertStringContainsString('(name)', $bytes);
        self::assertStringContainsString('(John)', $bytes);
        // Не должно быть multiline/password flags.
        self::assertDoesNotMatchRegularExpression('@/Ff\s+(?:4096|8192|131072)\b@', $bytes);
    }
}
