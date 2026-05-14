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

    // -------- Phase 148: X9 filter + L3 mirroring --------

    #[Test]
    public function x9_filters_lre_rle_pdf(): void
    {
        // "A" + LRE (U+202A) + "B" + PDF (U+202C) + "C"
        $cps = [0x41, 0x202A, 0x42, 0x202C, 0x43];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Formatting chars removed — only ABC remain.
        self::assertSame([0x41, 0x42, 0x43], $out);
    }

    #[Test]
    public function x9_filters_embeddings_keeps_isolates(): void
    {
        // Phase 187: LRE/RLE/LRO/RLO/PDF stripped (formatting); isolates
        // LRI/RLI/FSI/PDI preserved as ON-class chars per UAX 9.
        $cps = [0x41, 0x2066, 0x42, 0x2069, 0x43];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Isolates kept в output.
        self::assertContains(0x41, $out);
        self::assertContains(0x42, $out);
        self::assertContains(0x43, $out);
        self::assertContains(0x2066, $out);
        self::assertContains(0x2069, $out);
    }

    #[Test]
    public function l3_mirroring_parens_in_rtl(): void
    {
        // Hebrew "א(ב)" — paren в RTL context should mirror.
        // Logical: א + ( + ב + )
        // After L2 reverse: ) ב ( א
        // After L3 mirror: ( ב ) א
        $cps = [0x05D0, 0x28, 0x05D1, 0x29];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x28, 0x05D1, 0x29, 0x05D0], $out);
    }

    #[Test]
    public function l3_no_mirror_in_ltr(): void
    {
        // "A(B)C" — pure LTR, parens not mirrored.
        $cps = [0x41, 0x28, 0x42, 0x29, 0x43];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x41, 0x28, 0x42, 0x29, 0x43], $out);
    }

    #[Test]
    public function l3_mirroring_brackets(): void
    {
        // Hebrew + brackets: א[ב]
        $cps = [0x05D0, 0x5B, 0x05D1, 0x5D];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Reverse, then mirror: ] ב [ א → [ ב ] א
        self::assertSame([0x5B, 0x05D1, 0x5D, 0x05D0], $out);
    }

    #[Test]
    public function l3_no_mirror_outside_rtl_run(): void
    {
        // "(A)" — LTR text in parens, no mirroring.
        $cps = [0x28, 0x41, 0x29];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x28, 0x41, 0x29], $out);
    }

    // -------- Phase 187: X1-X8 explicit embedding stack --------

    #[Test]
    public function rle_embeds_but_does_not_override_strong_types(): void
    {
        // RLE (0x202B) + "AB" + PDF — A,B stay L-class within RTL embedding.
        // L2: levels [2, 2]; reverse at k=2 then k=1 cancels → original order.
        // Only RLO (override) would forcibly reverse Latin chars.
        $cps = [0x202B, 0x41, 0x42, 0x202C];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x41, 0x42], $out);
    }

    #[Test]
    public function lre_forces_ltr_embedding(): void
    {
        // LRE (0x202A) + Hebrew "א" + "ב" + PDF — forces LTR within RTL paragraph.
        // С LTR paragraph default + LRE: keep order.
        $cps = [0x202A, 0x05D0, 0x05D1, 0x202C];
        $out = BidiAlgorithm::reorderCodepoints($cps, 0);
        // Hebrew chars within LRE embedding still RTL within their level —
        // но LRE adds 2 к base level, so they're at level 3 (odd, RTL).
        // L2 reverses at highest level → [ב, א].
        self::assertSame([0x05D1, 0x05D0], $out);
    }

    #[Test]
    public function rlo_overrides_strong_types_к_rtl(): void
    {
        // RLO (0x202E) + "ABC" + PDF — override forces ALL chars в RTL,
        // even Latin letters. L2 reverses → "CBA".
        $cps = [0x202E, 0x41, 0x42, 0x43, 0x202C];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x43, 0x42, 0x41], $out);
    }

    #[Test]
    public function pdf_pops_embedding_back_to_base(): void
    {
        // RLO (override forces R) + "AB" + PDF + "CD" — "AB" reversed,
        // "CD" stays normal LTR after PDF.
        $cps = [0x202E, 0x41, 0x42, 0x202C, 0x43, 0x44];
        $out = BidiAlgorithm::reorderCodepoints($cps, 0);
        self::assertSame([0x42, 0x41, 0x43, 0x44], $out);
    }

    #[Test]
    public function unmatched_pdf_ignored(): void
    {
        // PDF without preceding LRE/RLE — ignored.
        $cps = [0x41, 0x202C, 0x42];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        self::assertSame([0x41, 0x42], $out);
    }

    #[Test]
    public function nested_embeddings(): void
    {
        // RLE + "A" + RLE + "B" + PDF + "C" + PDF — nested embeddings.
        // All в RTL embedding levels.
        $cps = [0x202B, 0x41, 0x202B, 0x42, 0x202C, 0x43, 0x202C];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // Each level reverses; deeply nested results в complex но deterministic order.
        self::assertContains(0x41, $out);
        self::assertContains(0x42, $out);
        self::assertContains(0x43, $out);
        self::assertCount(3, $out);
    }

    #[Test]
    public function rli_pdi_isolate_pair(): void
    {
        // RLI (0x2067) + "AB" + PDI (0x2069) — isolate в RTL direction.
        $cps = [0x41, 0x2067, 0x42, 0x43, 0x2069, 0x44];
        $out = BidiAlgorithm::reorderCodepoints($cps);
        // RLI/PDI preserved в output; "BC" within isolate reversed.
        self::assertCount(6, $out);
        self::assertContains(0x41, $out);
        self::assertContains(0x44, $out);
    }
}
