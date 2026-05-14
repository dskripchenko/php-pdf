<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Text;

/**
 * Phase 137: Indic script basic shaping — pre-base matra reordering.
 *
 * In Indic scripts (Devanagari, Bengali, Tamil, Telugu, Kannada,
 * Malayalam, Gujarati, Gurmukhi, Oriya, Sinhala), некоторые vowel signs
 * (matras) appear visually BEFORE the base consonant despite being
 * stored AFTER the consonant в logical order.
 *
 * Example (Devanagari):
 *   "कि" = क (U+0915) + ि (U+093F SHORT_I matra)
 *   Logical order: क, ि
 *   Visual order:  ि, क  (matra displayed на left of consonant)
 *
 * Без reordering, "किताब" (kitab, book) renders as "क ि त ा ब" —
 * matra после base instead of before.
 *
 * Per OpenType USE (Universal Shaping Engine), reordering rules:
 *  1. Identify syllable boundary
 *  2. Find pre-base matra in syllable
 *  3. Move it к position immediately before the syllable's base consonant
 *     (skipping any preceding RA+Halant or virama-stacked components)
 *
 * Scope первой итерации:
 *  - Single-character pre-base matras (most common)
 *  - Per-character reordering (no multi-step syllable detection)
 *  - Conjunct substitution и reph rendering left к font's GSUB
 *
 * Не реализовано:
 *  - Two-part matras (Bengali ো U+09CB = U+09C7 + U+09BE,
 *    Tamil ொ U+0BCA, etc.) — would require decomposition
 *  - Reph rendering (need GSUB rphf feature application)
 *  - Conjunct ligatures (need GSUB ccmp / akhn / blwf features)
 *  - Above/below-base matra positioning (mostly handled by font itself)
 */
final class IndicShaper
{
    /**
     * Pre-base matras для каждого Indic script.
     * Single-codepoint matras only (no two-part decomposition).
     */
    private const PRE_BASE_MATRAS = [
        // Devanagari
        0x093F => true,  // ि DEVANAGARI VOWEL SIGN I
        // Bengali
        0x09BF => true,  // ি BENGALI VOWEL SIGN I
        0x09C7 => true,  // ে BENGALI VOWEL SIGN E
        0x09C8 => true,  // ৈ BENGALI VOWEL SIGN AI
        // Gurmukhi
        0x0A3F => true,  // ਿ GURMUKHI VOWEL SIGN I
        // Gujarati
        0x0ABF => true,  // િ GUJARATI VOWEL SIGN I
        // Oriya
        0x0B47 => true,  // େ ORIYA VOWEL SIGN E
        // Tamil
        0x0BC6 => true,  // ெ TAMIL VOWEL SIGN E
        0x0BC7 => true,  // ே TAMIL VOWEL SIGN EE
        0x0BC8 => true,  // ை TAMIL VOWEL SIGN AI
        // Telugu
        0x0C46 => true,  // ె TELUGU VOWEL SIGN E
        0x0C47 => true,  // ే TELUGU VOWEL SIGN EE
        0x0C48 => true,  // ై TELUGU VOWEL SIGN AI
        // Kannada
        0x0CC6 => true,  // ೆ KANNADA VOWEL SIGN E
        // Malayalam
        0x0D46 => true,  // െ MALAYALAM VOWEL SIGN E
        0x0D47 => true,  // േ MALAYALAM VOWEL SIGN EE
        0x0D48 => true,  // ൈ MALAYALAM VOWEL SIGN AI
        // Sinhala
        0x0DD9 => true,  // ෙ SINHALA VOWEL SIGN KOMBUVA
        0x0DDA => true,  // ේ SINHALA VOWEL SIGN DIGA KOMBUVA
        0x0DDB => true,  // ෛ SINHALA VOWEL SIGN KOMBU DEKA
    ];

    /**
     * Consonant ranges per script — used to find base consonant в syllable
     * walk-back from pre-base matra.
     * Per Unicode Indic blocks (rough approximation).
     */
    private const CONSONANT_RANGES = [
        [0x0915, 0x0939],  // Devanagari consonants क..ह
        [0x0958, 0x095F],  // Devanagari additional consonants
        [0x0978, 0x097F],
        [0x0995, 0x09B9],  // Bengali
        [0x09DC, 0x09DF],
        [0x0A15, 0x0A39],  // Gurmukhi
        [0x0A59, 0x0A5E],
        [0x0A95, 0x0AB9],  // Gujarati
        [0x0B15, 0x0B39],  // Oriya
        [0x0B5C, 0x0B5F],
        [0x0B95, 0x0BB9],  // Tamil
        [0x0C15, 0x0C39],  // Telugu
        [0x0C58, 0x0C5A],
        [0x0C95, 0x0CB9],  // Kannada
        [0x0CDE, 0x0CDE],
        [0x0D15, 0x0D3A],  // Malayalam
        [0x0D54, 0x0D56],
        [0x0D5F, 0x0D61],
        [0x0DA0, 0x0DC6],  // Sinhala
    ];

    /**
     * Virama (halant) codepoints per script.
     */
    private const VIRAMAS = [
        0x094D => true,  // Devanagari
        0x09CD => true,  // Bengali
        0x0A4D => true,  // Gurmukhi
        0x0ACD => true,  // Gujarati
        0x0B4D => true,  // Oriya
        0x0BCD => true,  // Tamil
        0x0C4D => true,  // Telugu
        0x0CCD => true,  // Kannada
        0x0D4D => true,  // Malayalam
        0x0DCA => true,  // Sinhala
    ];

    /**
     * Apply Indic shaping to codepoint list. Returns new list с pre-base
     * matras moved to before their base consonants.
     *
     * Algorithm:
     *   For each pre-base matra at position i:
     *     Walk backward through halant-consonant pairs to find syllable start.
     *     Insert matra immediately before syllable start.
     *
     * @param  list<int>  $cps
     * @return list<int>
     */
    public static function shape(array $cps): array
    {
        $out = $cps;
        $n = count($out);
        $i = 0;
        while ($i < $n) {
            $cp = $out[$i];
            if (! isset(self::PRE_BASE_MATRAS[$cp])) {
                $i++;

                continue;
            }
            // Walk backward to find syllable start (base consonant).
            $start = $i - 1;
            if ($start < 0 || ! self::isConsonant($out[$start])) {
                $i++;

                continue;
            }
            // Step further back through halant + consonant pairs.
            while ($start - 2 >= 0
                && isset(self::VIRAMAS[$out[$start - 1]])
                && self::isConsonant($out[$start - 2])) {
                $start -= 2;
            }
            // Move matra from $i к position $start.
            if ($start === $i - 1) {
                // Simple case: swap matra с immediately preceding consonant.
                [$out[$start], $out[$i]] = [$out[$i], $out[$start]];
            } else {
                // Multi-step: remove matra from $i, insert at $start.
                $matra = $out[$i];
                array_splice($out, $i, 1);
                array_splice($out, $start, 0, [$matra]);
            }
            $i++; // matra now at $start; advance past где it was
        }

        return $out;
    }

    /**
     * UTF-8 → reordered codepoints.
     *
     * @return list<int>
     */
    public static function shapeUtf8(string $utf8): array
    {
        return self::shape(self::utf8ToCps($utf8));
    }

    public static function isPreBaseMatra(int $cp): bool
    {
        return isset(self::PRE_BASE_MATRAS[$cp]);
    }

    public static function isVirama(int $cp): bool
    {
        return isset(self::VIRAMAS[$cp]);
    }

    public static function isConsonant(int $cp): bool
    {
        foreach (self::CONSONANT_RANGES as [$lo, $hi]) {
            if ($cp >= $lo && $cp <= $hi) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public static function utf8ToCps(string $utf8): array
    {
        $cps = [];
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $b = ord($utf8[$i]);
            if ($b < 0x80) {
                $cps[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0) {
                $cps[] = (($b & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0) {
                $cps[] = (($b & 0x0F) << 12) | ((ord($utf8[$i + 1]) & 0x3F) << 6) | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cps[] = (($b & 0x07) << 18) | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6) | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            }
        }

        return $cps;
    }
}
