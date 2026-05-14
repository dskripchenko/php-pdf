<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 144: detect reph positions after Indic shaping.
 *
 * Reph detection rule: RA + virama где previous cp is NOT virama.
 * Excludes subscript-RA case (H + R) where R follows virama.
 */
final class RphDetectionTest extends TestCase
{
    #[Test]
    public function detects_reph_in_post_reorder_cluster(): void
    {
        // After reph reorder: "र्क" = к + р + ् → [0x0915, 0x0930, 0x094D]
        $positions = PdfFont::detectRphPositionsForTest([0x0915, 0x0930, 0x094D]);
        // Position 1 (RA) is followed by virama, preceded by K (non-virama) → reph.
        self::assertArrayHasKey(1, $positions);
        self::assertCount(1, $positions);
    }

    #[Test]
    public function ignores_subscript_ra(): void
    {
        // "क्र" subscript-RA: K + virama + RA = [0x0915, 0x094D, 0x0930]
        // Position 2 (RA) NOT followed by virama → no reph candidate.
        $positions = PdfFont::detectRphPositionsForTest([0x0915, 0x094D, 0x0930]);
        self::assertCount(0, $positions);
    }

    #[Test]
    public function ignores_ra_at_start(): void
    {
        // RA + virama at start (i=0): не is reph (no preceding consonant).
        $positions = PdfFont::detectRphPositionsForTest([0x0930, 0x094D, 0x0915]);
        self::assertArrayNotHasKey(0, $positions);
    }

    #[Test]
    public function ignores_ra_without_following_virama(): void
    {
        // RA followed by non-virama.
        $positions = PdfFont::detectRphPositionsForTest([0x0915, 0x0930, 0x093E]);
        self::assertCount(0, $positions);
    }

    #[Test]
    public function multiple_reph_clusters(): void
    {
        // "र्कर्त" reordered + space: [к, р, ्, space, т, р, ्]
        $positions = PdfFont::detectRphPositionsForTest([
            0x0915, 0x0930, 0x094D, 0x20, 0x0924, 0x0930, 0x094D,
        ]);
        // Positions 1 и 5 are reph candidates.
        self::assertArrayHasKey(1, $positions);
        self::assertArrayHasKey(5, $positions);
        self::assertCount(2, $positions);
    }

    #[Test]
    public function bengali_reph(): void
    {
        // Bengali "র্ক" reordered → [к, р, ্] = [0x0995, 0x09B0, 0x09CD]
        $positions = PdfFont::detectRphPositionsForTest([0x0995, 0x09B0, 0x09CD]);
        self::assertArrayHasKey(1, $positions);
    }

    #[Test]
    public function non_indic_text(): void
    {
        // ASCII text — no reph.
        $positions = PdfFont::detectRphPositionsForTest([0x41, 0x42, 0x43]);
        self::assertCount(0, $positions);
    }
}
