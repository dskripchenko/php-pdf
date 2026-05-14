<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Text;

use Dskripchenko\PhpPdf\Text\BidiAlgorithm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 136: Unicode Bidi Algorithm UAX 9 tests.
 */
final class BidiAlgorithmTest extends TestCase
{
    private function toUtf8(array $cps): string
    {
        $out = '';
        foreach ($cps as $cp) {
            $out .= mb_chr($cp, 'UTF-8');
        }

        return $out;
    }

    #[Test]
    public function pure_ltr_text_unchanged(): void
    {
        $out = BidiAlgorithm::reorder('Hello World');
        self::assertSame('Hello World', $this->toUtf8($out));
    }

    #[Test]
    public function pure_rtl_text_reversed(): void
    {
        // Hebrew "שלום" (peace) U+05E9 U+05DC U+05D5 U+05DD
        $input = $this->toUtf8([0x05E9, 0x05DC, 0x05D5, 0x05DD]);
        $out = BidiAlgorithm::reorder($input);
        // Pure RTL → reversed.
        self::assertSame([0x05DD, 0x05D5, 0x05DC, 0x05E9], $out);
    }

    #[Test]
    public function arabic_text_reversed(): void
    {
        // "العربية" (the Arabic)
        $input = "\xD8\xA7\xD9\x84\xD8\xB9\xD8\xB1\xD8\xA8\xD9\x8A\xD8\xA9";
        $out = BidiAlgorithm::reorder($input);
        // Reversed for RTL.
        self::assertSame([0x0629, 0x064A, 0x0628, 0x0631, 0x0639, 0x0644, 0x0627], $out);
    }

    #[Test]
    public function ltr_paragraph_with_rtl_segment(): void
    {
        // "Hello עברית world" — paragraph LTR, Hebrew segment reversed inside.
        $cps = array_merge(
            [0x48, 0x65, 0x6C, 0x6C, 0x6F, 0x20],   // "Hello "
            [0x05E2, 0x05D1, 0x05E8, 0x05D9, 0x05EA],  // "עברית"
            [0x20, 0x77, 0x6F, 0x72, 0x6C, 0x64],   // " world"
        );
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Expected: "Hello " + reverse("עברית") + " world"
        $expected = array_merge(
            [0x48, 0x65, 0x6C, 0x6C, 0x6F, 0x20],
            [0x05EA, 0x05D9, 0x05E8, 0x05D1, 0x05E2],
            [0x20, 0x77, 0x6F, 0x72, 0x6C, 0x64],
        );
        self::assertSame($expected, $out);
    }

    #[Test]
    public function digits_in_arabic_keep_order(): void
    {
        // "كتاب 123 صفحة" — book 123 pages
        // Bidi: digits в RTL paragraph stay в logical order (don't reverse).
        $cps = array_merge(
            [0x0643, 0x062A, 0x0627, 0x0628, 0x20],   // كتاب + space
            [0x31, 0x32, 0x33, 0x20],                  // 123 + space
            [0x0635, 0x0641, 0x062D, 0x0629],          // صفحة
        );
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Verify 123 appears в same order (digits don't reverse).
        $positions = [];
        foreach ($out as $i => $cp) {
            if ($cp === 0x31) {
                $positions[] = $i;
            }
            if ($cp === 0x32) {
                $positions[] = $i;
            }
            if ($cp === 0x33) {
                $positions[] = $i;
            }
        }
        self::assertCount(3, $positions);
        self::assertLessThan($positions[1], $positions[0]); // '1' before '2'
        self::assertLessThan($positions[2], $positions[1]); // '2' before '3'
    }

    #[Test]
    public function rtl_paragraph_first_strong_arabic(): void
    {
        // "أهلا hello" — RTL paragraph (first strong is Arabic AL).
        // Result visually: English appears на LEFT, Arabic на RIGHT.
        // Output codepoints (left-to-right в PDF): "hello " + reverse("أهلا")
        $cps = array_merge(
            [0x0623, 0x0647, 0x0644, 0x0627],  // أهلا
            [0x20, 0x68, 0x65, 0x6C, 0x6C, 0x6F],  // " hello"
        );
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // First chars в output should be Latin (positioned LEFT visually).
        self::assertSame(0x68, $out[0]); // 'h'
        self::assertSame(0x65, $out[1]); // 'e'
    }

    #[Test]
    public function paragraph_level_forced_ltr(): void
    {
        // Force LTR paragraph for RTL-only text — affects neutrals' direction.
        $cps = [0x05E9, 0x05DC, 0x20, 0x68, 0x65]; // "של he"
        $out = BidiAlgorithm::reorderCodepoints($cps, 0);
        // LTR paragraph: Hebrew run reversed, English in normal order.
        // Expected: reverse("של") + " he"
        self::assertSame([0x05DC, 0x05E9, 0x20, 0x68, 0x65], $out);
    }

    #[Test]
    public function bidi_class_detection(): void
    {
        self::assertSame(BidiAlgorithm::L, BidiAlgorithm::bidiClass(0x41));  // 'A'
        self::assertSame(BidiAlgorithm::R, BidiAlgorithm::bidiClass(0x05D0)); // Hebrew
        self::assertSame(BidiAlgorithm::AL, BidiAlgorithm::bidiClass(0x0627)); // Arabic
        self::assertSame(BidiAlgorithm::EN, BidiAlgorithm::bidiClass(0x30)); // '0'
        self::assertSame(BidiAlgorithm::AN, BidiAlgorithm::bidiClass(0x0660)); // Arabic-Indic 0
        self::assertSame(BidiAlgorithm::WS, BidiAlgorithm::bidiClass(0x20)); // space
        self::assertSame(BidiAlgorithm::B, BidiAlgorithm::bidiClass(0x0A)); // LF
    }

    #[Test]
    public function empty_input(): void
    {
        self::assertSame([], BidiAlgorithm::reorder(''));
    }

    #[Test]
    public function neutrals_take_paragraph_direction_when_unmatched(): void
    {
        // "Hello!" — final '!' is ON (neutral). Should resolve к paragraph
        // direction (LTR). Output unchanged.
        $out = BidiAlgorithm::reorder('Hello!');
        self::assertSame('Hello!', $this->toUtf8($out));
    }
}
