<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Text;

use Dskripchenko\PhpPdf\Text\ArabicShaper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 135: Arabic basic shaping tests.
 *
 * Verifies:
 *  - Joining class assignment
 *  - Form resolution (isol/init/medi/fina)
 *  - Lam-alef ligature substitution
 *  - RTL output ordering
 *  - Non-Arabic chars pass through
 */
final class ArabicShaperTest extends TestCase
{
    #[Test]
    public function single_alef_is_isolated(): void
    {
        // ا (alef) standalone → FE8D isolated.
        $out = ArabicShaper::shape("\xD8\xA7"); // U+0627
        self::assertSame([0xFE8D], $out);
    }

    #[Test]
    public function single_beh_is_isolated(): void
    {
        // ب standalone → FE8F isolated.
        $out = ArabicShaper::shape("\xD8\xA8"); // U+0628
        self::assertSame([0xFE8F], $out);
    }

    #[Test]
    public function ktab_word_shapes_correctly(): void
    {
        // كتاب "book": ك(D) ت(D) ا(R) ب(D)
        // Logical sequence shaping:
        //  pos 0 ك: prev=null, next=ت(D) → init (FEDB)
        //  pos 1 ت: prev=ك(D), next=ا(R) → medial (FE98)
        //  pos 2 ا: prev=ت(D), next=ب(D) → final (FE8E) (alef is R, only fina/isol)
        //  pos 3 ب: prev=ا(R), next=null → isolated (FE8F)
        //    (ب's right doesn't connect because alef is R, can't be joined on left)
        // After RTL reversal: [FE8F, FE8E, FE98, FEDB]
        $out = ArabicShaper::shape("\xD9\x83\xD8\xAA\xD8\xA7\xD8\xA8");
        self::assertSame([0xFE8F, 0xFE8E, 0xFE98, 0xFEDB], $out);
    }

    #[Test]
    public function lam_alef_ligature_applied(): void
    {
        // لا = LAM + ALEF → ﻻ (FEFB isolated) or ﻼ (FEFC final).
        // Bare LAM + ALEF (no neighbors): LAM in initial form pre-ligature
        // (joins left to alef), then ligature substitutes.
        // After ligature, sequence = [FEFB or FEFC]. RTL reverse.
        $out = ArabicShaper::shape("\xD9\x84\xD8\xA7"); // U+0644 U+0627
        self::assertCount(1, $out);
        self::assertContains($out[0], [0xFEFB, 0xFEFC]);
    }

    #[Test]
    public function salam_shapes_with_lam_alef(): void
    {
        // السلام = ا ل س ل ا م
        // Expected output (visually right-to-left, RTL-reversed for PDF):
        //   meem-isol, lam-alef-final, seen-medial, lam-init, alef-isol
        $out = ArabicShaper::shape("\xD8\xA7\xD9\x84\xD8\xB3\xD9\x84\xD8\xA7\xD9\x85");
        // 6 input chars → 5 (lam+alef → ligature) → reversed for output.
        self::assertCount(5, $out);
        self::assertSame(0xFEE1, $out[0]); // meem isolated
        self::assertContains($out[1], [0xFEFB, 0xFEFC]); // lam-alef ligature
        self::assertSame(0xFEB4, $out[2]); // seen medial
        self::assertSame(0xFEDF, $out[3]); // lam initial
        self::assertSame(0xFE8D, $out[4]); // alef isolated
    }

    #[Test]
    public function non_arabic_text_pass_through(): void
    {
        $out = ArabicShaper::shape('Hello');
        self::assertSame([ord('H'), ord('e'), ord('l'), ord('l'), ord('o')], $out);
    }

    #[Test]
    public function empty_input_returns_empty(): void
    {
        self::assertSame([], ArabicShaper::shape(''));
    }

    #[Test]
    public function diacritics_are_transparent(): void
    {
        // ب + fatha (U+064E) should still shape ب based on its other neighbors.
        // Just fatha = transparent, doesn't affect ب shape.
        // ب alone → isolated FE8F. ب + fatha alone → ب still isolated.
        // Diacritic itself passes through unchanged.
        $out = ArabicShaper::shape("\xD8\xA8\xD9\x8E"); // ب + fatha
        // RTL reversed: [fatha, FE8F-isol-beh]
        self::assertCount(2, $out);
        self::assertContains(0x064E, $out); // fatha untouched
        self::assertContains(0xFE8F, $out); // ب isolated
    }

    #[Test]
    public function dal_only_has_isolated_and_final(): void
    {
        // د (DAL, R-only) — initial/medial forms не exist (gracefully fall back).
        // د + ب: DAL would be isol/fina (DAL is R, doesn't join left), ب joins
        // depending on right neighbor.
        // Just د alone → FEA9 isolated.
        $out = ArabicShaper::shape("\xD8\xAF");
        self::assertSame([0xFEA9], $out);
    }

    #[Test]
    public function long_alef_at_end_is_final_form(): void
    {
        // ب ا : ب(D) ا(R)
        //  ب: prev=null, next=ا(R) → joinsLeft? D AND R → true. joinsRight false. → init FE91
        //  ا: prev=ب(D), next=null → joinsRight true. → final FE8E
        // RTL: [FE8E, FE91]
        $out = ArabicShaper::shape("\xD8\xA8\xD8\xA7");
        self::assertSame([0xFE8E, 0xFE91], $out);
    }
}
