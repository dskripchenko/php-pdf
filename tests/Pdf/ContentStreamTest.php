<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\ContentStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentStreamTest extends TestCase
{
    #[Test]
    public function text_emits_BT_Tf_Td_Tj_ET(): void
    {
        $cs = (new ContentStream)->text('F1', 12, 72, 720, 'Hi');
        $s = $cs->toString();

        self::assertStringContainsString('BT', $s);
        self::assertStringContainsString('/F1 12 Tf', $s);
        self::assertStringContainsString('72 720 Td', $s);
        self::assertStringContainsString('(Hi) Tj', $s);
        self::assertStringContainsString('ET', $s);
    }

    #[Test]
    public function literal_string_escapes_special_chars(): void
    {
        $cs = (new ContentStream)->text('F1', 12, 0, 0, '(hello)\\world');
        $s = $cs->toString();

        // ( ) \ должны быть escape'нуты обратным слешем.
        self::assertStringContainsString('(\\(hello\\)\\\\world) Tj', $s);
    }

    #[Test]
    public function non_ascii_bytes_become_octal_escape(): void
    {
        // Cyrillic Й (U+0419, UTF-8: 0xD0 0x99) → octal \320\231.
        $cs = (new ContentStream)->text('F1', 12, 0, 0, 'Й');
        $s = $cs->toString();
        self::assertStringContainsString('\\320\\231', $s);
    }

    #[Test]
    public function fillRectangle_emits_re_f_with_color(): void
    {
        $cs = (new ContentStream)->fillRectangle(10, 20, 100, 50, 1.0, 0, 0);
        $s = $cs->toString();

        self::assertStringContainsString('1 0 0 rg', $s);
        self::assertStringContainsString('10 20 100 50 re', $s);
        self::assertStringContainsString("f\n", $s);
        // q…Q обрамляют, чтобы не загрязнять graphics state.
        self::assertStringContainsString("q\n", $s);
        self::assertStringContainsString("Q\n", $s);
    }

    #[Test]
    public function number_formatting_strips_trailing_zeros(): void
    {
        $cs = (new ContentStream)->text('F1', 12.5, 0, 100.0000, 'x');
        $s = $cs->toString();
        // 12.5 must stay 12.5 (not 12.5000); 100.0000 → 100.
        self::assertStringContainsString('/F1 12.5 Tf', $s);
        self::assertStringContainsString('0 100 Td', $s);
    }

    #[Test]
    public function empty_stream_isEmpty(): void
    {
        $cs = new ContentStream;
        self::assertTrue($cs->isEmpty());
        $cs->text('F1', 12, 0, 0, 'x');
        self::assertFalse($cs->isEmpty());
    }
}
