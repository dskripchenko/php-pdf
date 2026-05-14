<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Text;

use Dskripchenko\PhpPdf\Text\IndicShaper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 137: Indic pre-base matra reordering tests.
 */
final class IndicShaperTest extends TestCase
{
    #[Test]
    public function devanagari_ki_reordered(): void
    {
        // क (U+0915) + ि (U+093F) → ि क (matra moves before base)
        $out = IndicShaper::shape([0x0915, 0x093F]);
        self::assertSame([0x093F, 0x0915], $out);
    }

    #[Test]
    public function devanagari_long_i_not_pre_base(): void
    {
        // ी (U+0940) — post-base, не reordered.
        $out = IndicShaper::shape([0x0915, 0x0940]);
        self::assertSame([0x0915, 0x0940], $out);
    }

    #[Test]
    public function bengali_pre_base_matra(): void
    {
        // ক (U+0995) + ি (U+09BF) → ি ক
        $out = IndicShaper::shape([0x0995, 0x09BF]);
        self::assertSame([0x09BF, 0x0995], $out);
    }

    #[Test]
    public function tamil_pre_base_matra(): void
    {
        // க (U+0B95) + ெ (U+0BC6) → ெ க
        $out = IndicShaper::shape([0x0B95, 0x0BC6]);
        self::assertSame([0x0BC6, 0x0B95], $out);
    }

    #[Test]
    public function conjunct_with_pre_base_matra(): void
    {
        // क्ति = क + ् + त + ि → ि + क + ् + т
        // Matra moves к start of conjunct cluster (across halant+consonant).
        $out = IndicShaper::shape([0x0915, 0x094D, 0x0924, 0x093F]);
        self::assertSame([0x093F, 0x0915, 0x094D, 0x0924], $out);
    }

    #[Test]
    public function multiple_words_in_sentence(): void
    {
        // "कि ति" = (क + ि) + space + (त + ि)
        $out = IndicShaper::shape([0x0915, 0x093F, 0x20, 0x0924, 0x093F]);
        // → (ि + к) + space + (ि + т)
        self::assertSame([0x093F, 0x0915, 0x20, 0x093F, 0x0924], $out);
    }

    #[Test]
    public function ascii_text_unchanged(): void
    {
        $out = IndicShaper::shape([0x48, 0x65, 0x6C, 0x6C, 0x6F]);
        self::assertSame([0x48, 0x65, 0x6C, 0x6C, 0x6F], $out);
    }

    #[Test]
    public function utf8_helper_works(): void
    {
        // "कि" в UTF-8: 0xE0 0xA4 0x95 0xE0 0xA4 0xBF
        $out = IndicShaper::shapeUtf8("\xE0\xA4\x95\xE0\xA4\xBF");
        self::assertSame([0x093F, 0x0915], $out);
    }

    #[Test]
    public function matra_without_preceding_consonant_left_alone(): void
    {
        // Just ि at start (no consonant to reorder before) — left alone.
        $out = IndicShaper::shape([0x093F, 0x0915]);
        self::assertSame([0x093F, 0x0915], $out);
    }

    #[Test]
    public function character_predicates(): void
    {
        self::assertTrue(IndicShaper::isPreBaseMatra(0x093F));
        self::assertFalse(IndicShaper::isPreBaseMatra(0x0940));
        self::assertTrue(IndicShaper::isVirama(0x094D));
        self::assertTrue(IndicShaper::isConsonant(0x0915));
        self::assertFalse(IndicShaper::isConsonant(0x093F));
    }

    #[Test]
    public function double_conjunct_with_matra(): void
    {
        // क + ् + त + ् + र + ि → ि + к + ् + т + ् + р
        // Multi-step halant-consonant traversal.
        $out = IndicShaper::shape([0x0915, 0x094D, 0x0924, 0x094D, 0x0930, 0x093F]);
        self::assertSame([0x093F, 0x0915, 0x094D, 0x0924, 0x094D, 0x0930], $out);
    }

    // -------------------- Phase 138: Reph reorder --------------------

    #[Test]
    public function devanagari_reph_simple(): void
    {
        // र + ् + क → к + र + ्   (reph candidate moved к end of syllable)
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915]);
        self::assertSame([0x0915, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function devanagari_reph_with_pre_base_matra(): void
    {
        // "र्कि" = р + ् + к + ि
        // Reph reorder: [к, ि, р, ्]
        // Pre-base matra reorder: [ि, к, р, ्]
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915, 0x093F]);
        self::assertSame([0x093F, 0x0915, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function devanagari_reph_with_post_base_matra(): void
    {
        // "र्का" = р + ् + к + ा (post-base matra 0x093E)
        // Reph reorder: [к, ा, р, ्]
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915, 0x093E]);
        self::assertSame([0x0915, 0x093E, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function devanagari_reph_with_conjunct(): void
    {
        // "र्क्ति" = р + ् + к + ् + т + ि
        // Reph reorder: [к, ्, т, ि, р, ्]
        // Pre-base matra reorder: [ि, к, ्, т, р, ्]
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915, 0x094D, 0x0924, 0x093F]);
        self::assertSame([0x093F, 0x0915, 0x094D, 0x0924, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function devanagari_reph_with_anusvara(): void
    {
        // "र्कं" = р + ् + к + anusvara (U+0902)
        // Reph reorder: [к, anusvara, р, ्]
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915, 0x0902]);
        self::assertSame([0x0915, 0x0902, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function ra_without_halant_not_reph(): void
    {
        // р + к — no halant between, not reph candidate.
        $out = IndicShaper::shape([0x0930, 0x0915]);
        self::assertSame([0x0930, 0x0915], $out);
    }

    #[Test]
    public function ra_halant_at_end_not_reph(): void
    {
        // р + ् without following consonant — incomplete, not reph.
        $out = IndicShaper::shape([0x0930, 0x094D]);
        self::assertSame([0x0930, 0x094D], $out);
    }

    #[Test]
    public function ra_halant_followed_by_non_consonant_not_reph(): void
    {
        // р + ् + ा (matra, not consonant) — not RA+H+C pattern.
        $out = IndicShaper::shape([0x0930, 0x094D, 0x093E]);
        self::assertSame([0x0930, 0x094D, 0x093E], $out);
    }

    #[Test]
    public function bengali_reph(): void
    {
        // Bengali "র্ক" = р(0x09B0) + ্(0x09CD) + к(0x0995)
        $out = IndicShaper::shape([0x09B0, 0x09CD, 0x0995]);
        self::assertSame([0x0995, 0x09B0, 0x09CD], $out);
    }

    #[Test]
    public function gujarati_reph(): void
    {
        // Gujarati ર (0x0AB0) + ્ (0x0ACD) + к (0x0A95)
        $out = IndicShaper::shape([0x0AB0, 0x0ACD, 0x0A95]);
        self::assertSame([0x0A95, 0x0AB0, 0x0ACD], $out);
    }

    #[Test]
    public function reph_in_middle_of_word(): void
    {
        // "к + р + ् + т" — К consonant starts syllable 1; R+H+T at i=1
        // forms reph syllable. Output: [к, т, р, ्]
        $out = IndicShaper::shape([0x0915, 0x0930, 0x094D, 0x0924]);
        self::assertSame([0x0915, 0x0924, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function two_reph_syllables_in_sentence(): void
    {
        // "र्क р्т" = (р+्+к) space (р+्+т)
        $out = IndicShaper::shape([0x0930, 0x094D, 0x0915, 0x20, 0x0930, 0x094D, 0x0924]);
        self::assertSame([0x0915, 0x0930, 0x094D, 0x20, 0x0924, 0x0930, 0x094D], $out);
    }

    #[Test]
    public function ra_predicate(): void
    {
        self::assertTrue(IndicShaper::isRA(0x0930));   // Devanagari
        self::assertTrue(IndicShaper::isRA(0x09B0));   // Bengali
        self::assertTrue(IndicShaper::isRA(0x0DBB));   // Sinhala
        self::assertFalse(IndicShaper::isRA(0x0915));  // К is not RA
        self::assertFalse(IndicShaper::isRA(0x41));    // ASCII
    }

    #[Test]
    public function syllable_mark_predicate(): void
    {
        self::assertTrue(IndicShaper::isSyllableMark(0x0902));  // anusvara
        self::assertTrue(IndicShaper::isSyllableMark(0x093F));  // pre-base matra
        self::assertTrue(IndicShaper::isSyllableMark(0x093E));  // post-base matra
        self::assertTrue(IndicShaper::isSyllableMark(0x094D));  // virama
        self::assertFalse(IndicShaper::isSyllableMark(0x0915)); // consonant
        self::assertFalse(IndicShaper::isSyllableMark(0x41));   // ASCII
    }

    // -------------------- Phase 139: Two-part matras --------------------

    #[Test]
    public function bengali_o_decomposes(): void
    {
        // "কো" (ko) = ক (0x0995) + ো (0x09CB)
        // Decompose ো → ে (0x09C7, pre-base) + া (0x09BE, post-base)
        // After decomp: [0x0995, 0x09C7, 0x09BE]
        // Pre-base matra reorder: [0x09C7, 0x0995, 0x09BE]
        $out = IndicShaper::shape([0x0995, 0x09CB]);
        self::assertSame([0x09C7, 0x0995, 0x09BE], $out);
    }

    #[Test]
    public function bengali_au_decomposes(): void
    {
        // "কৌ" (kau) = ক + ৌ (0x09CC) → ে + ৗ
        $out = IndicShaper::shape([0x0995, 0x09CC]);
        self::assertSame([0x09C7, 0x0995, 0x09D7], $out);
    }

    #[Test]
    public function tamil_o_decomposes(): void
    {
        // "கொ" (ko) = க (0x0B95) + ொ (0x0BCA) → ெ (0x0BC6, pre-base) + ா (0x0BBE)
        $out = IndicShaper::shape([0x0B95, 0x0BCA]);
        self::assertSame([0x0BC6, 0x0B95, 0x0BBE], $out);
    }

    #[Test]
    public function tamil_oo_decomposes(): void
    {
        // "கோ" (koo) = க + ோ (0x0BCB) → ே (0x0BC7, pre-base) + ா (0x0BBE)
        $out = IndicShaper::shape([0x0B95, 0x0BCB]);
        self::assertSame([0x0BC7, 0x0B95, 0x0BBE], $out);
    }

    #[Test]
    public function malayalam_o_decomposes(): void
    {
        // "കൊ" (ko) = ക (0x0D15) + ൊ (0x0D4A) → െ (0x0D46) + ാ (0x0D3E)
        $out = IndicShaper::shape([0x0D15, 0x0D4A]);
        self::assertSame([0x0D46, 0x0D15, 0x0D3E], $out);
    }

    #[Test]
    public function oriya_o_decomposes(): void
    {
        // "କୋ" = କ (0x0B15) + ୋ (0x0B4B) → େ (0x0B47, pre-base) + ା (0x0B3E)
        $out = IndicShaper::shape([0x0B15, 0x0B4B]);
        self::assertSame([0x0B47, 0x0B15, 0x0B3E], $out);
    }

    #[Test]
    public function kannada_o_decomposes(): void
    {
        // "ಕೊ" = ಕ (0x0C95) + ೊ (0x0CCA) → ೆ (0x0CC6, pre-base) + ು (0x0CC2)
        $out = IndicShaper::shape([0x0C95, 0x0CCA]);
        self::assertSame([0x0CC6, 0x0C95, 0x0CC2], $out);
    }

    #[Test]
    public function kannada_oo_three_part_decomposes(): void
    {
        // "ಕೋ" = ಕ + ೋ (0x0CCB) → ೆ + ು + ೕ (three parts!)
        // After decomp: [0x0C95, 0x0CC6, 0x0CC2, 0x0CD5]
        // Pre-base matra reorder: [0x0CC6, 0x0C95, 0x0CC2, 0x0CD5]
        $out = IndicShaper::shape([0x0C95, 0x0CCB]);
        self::assertSame([0x0CC6, 0x0C95, 0x0CC2, 0x0CD5], $out);
    }

    #[Test]
    public function sinhala_o_decomposes(): void
    {
        // "කො" = ක (0x0D9A) + ො (0x0DDC) → ෙ (0x0DD9, pre-base) + ා (0x0DCF)
        $out = IndicShaper::shape([0x0D9A, 0x0DDC]);
        self::assertSame([0x0DD9, 0x0D9A, 0x0DCF], $out);
    }

    #[Test]
    public function two_part_matra_with_reph(): void
    {
        // Bengali "র্কো" = র + ্ + ক + ো
        // Decompose: [0x09B0, 0x09CD, 0x0995, 0x09C7, 0x09BE]
        // Reph reorder (RA+halant+K + matras → K matras RA halant):
        //   [0x0995, 0x09C7, 0x09BE, 0x09B0, 0x09CD]
        // Pre-base matra reorder (0x09C7 moves before к):
        //   [0x09C7, 0x0995, 0x09BE, 0x09B0, 0x09CD]
        $out = IndicShaper::shape([0x09B0, 0x09CD, 0x0995, 0x09CB]);
        self::assertSame([0x09C7, 0x0995, 0x09BE, 0x09B0, 0x09CD], $out);
    }

    #[Test]
    public function two_part_matra_predicate(): void
    {
        self::assertTrue(IndicShaper::isTwoPartMatra(0x09CB));  // Bengali O
        self::assertTrue(IndicShaper::isTwoPartMatra(0x0BCA));  // Tamil O
        self::assertTrue(IndicShaper::isTwoPartMatra(0x0CCB));  // Kannada OO (3-part)
        self::assertFalse(IndicShaper::isTwoPartMatra(0x09BF)); // Bengali I (single)
        self::assertFalse(IndicShaper::isTwoPartMatra(0x41));   // ASCII
    }

    #[Test]
    public function decompose_matra_returns_components(): void
    {
        self::assertSame([0x09C7, 0x09BE], IndicShaper::decomposeMatra(0x09CB));
        self::assertSame([0x0CC6, 0x0CC2, 0x0CD5], IndicShaper::decomposeMatra(0x0CCB));
        self::assertSame([0x0915], IndicShaper::decomposeMatra(0x0915));  // non-matra: returns as-is
    }

    #[Test]
    public function decompose_idempotent_when_no_two_part_matras(): void
    {
        // All-Devanagari sequence без two-part matras — unchanged by decomp step.
        $cps = [0x0915, 0x094D, 0x0924, 0x093F];
        $out = IndicShaper::shape($cps);
        // Devanagari has no two-part matras; only pre-base matra reorder applies.
        self::assertSame([0x093F, 0x0915, 0x094D, 0x0924], $out);
    }
}
