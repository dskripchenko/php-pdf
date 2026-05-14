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
}
