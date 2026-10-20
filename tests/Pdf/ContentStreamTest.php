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

        // ( ) \ have to be escaped with a backslash.
        self::assertStringContainsString('(\\(hello\\)\\\\world) Tj', $s);
    }

    #[Test]
    public function non_ascii_bytes_become_octal_escape(): void
    {
        // The Cyrillic Й (U+0419, UTF-8: 0xD0 0x99) → the octal \320\231.
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
        // q…Q wrap it so that the graphics state is not polluted.
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
    public function emitPath_fill_evenodd_uses_f_star(): void
    {
        $cs = (new ContentStream)->emitPath(self::twoSquares(), 'fill-evenodd', ['r' => 1, 'g' => 0, 'b' => 0]);
        $s = $cs->toString();

        self::assertStringContainsString('1 0 0 rg', $s);
        self::assertStringContainsString("h\nf*\nQ\n", $s);
    }

    #[Test]
    public function emitPath_fillstroke_evenodd_uses_B_star(): void
    {
        $cs = (new ContentStream)->emitPath(
            self::twoSquares(), 'fillstroke-evenodd',
            ['r' => 1, 'g' => 0, 'b' => 0], ['r' => 0, 'g' => 0, 'b' => 1], 2.0,
        );
        $s = $cs->toString();

        // Both colours and the line width are set, as for 'fillstroke'.
        self::assertStringContainsString('1 0 0 rg', $s);
        self::assertStringContainsString('0 0 1 RG', $s);
        self::assertStringContainsString('2 w', $s);
        self::assertStringContainsString("h\nB*\nQ\n", $s);
    }

    #[Test]
    public function emitPath_keeps_nonzero_operators_and_unknown_mode_falls_back_to_f(): void
    {
        $squares = self::twoSquares();

        self::assertStringContainsString("h\nf\nQ\n", (new ContentStream)->emitPath($squares, 'fill')->toString());
        self::assertStringContainsString("h\nS\nQ\n", (new ContentStream)->emitPath($squares, 'stroke')->toString());
        self::assertStringContainsString("h\nB\nQ\n", (new ContentStream)->emitPath($squares, 'fillstroke')->toString());
        self::assertStringContainsString("h\nf\nQ\n", (new ContentStream)->emitPath($squares, 'bogus')->toString());
    }

    #[Test]
    public function fillPathWithPattern_selects_the_pattern_and_fills(): void
    {
        $s = (new ContentStream)->fillPathWithPattern(self::twoSquares(), 'P1')->toString();
        self::assertStringStartsWith("q\n/Pattern cs\n/P1 scn\n0 0 m\n", $s);
        self::assertStringEndsWith("h\nf\nQ\n", $s);

        $evenOdd = (new ContentStream)->fillPathWithPattern(self::twoSquares(), 'P2', evenOdd: true)->toString();
        self::assertStringEndsWith("h\nf*\nQ\n", $evenOdd);
        self::assertTrue((new ContentStream)->fillPathWithPattern([], 'P1')->isEmpty());
    }

    /**
     * A square with a smaller square inside it, both drawn the same way
     * round: under the nonzero rule the inner one fills, under even-odd it
     * is a hole.
     *
     * @return list<array{0: string, 1: float, 2: float}|string>
     */
    private static function twoSquares(): array
    {
        return [
            ['M', 0, 0], ['L', 0, 100], ['L', 100, 100], ['L', 100, 0], 'Z',
            ['M', 25, 25], ['L', 25, 75], ['L', 75, 75], ['L', 75, 25], 'Z',
        ];
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
