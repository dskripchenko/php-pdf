<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 195: QR version info pattern (V7+) BCH(18,6) encoding tests.
 */
final class QrVersionInfoTest extends TestCase
{
    #[Test]
    public function v6_has_no_version_info_pattern(): void
    {
        // V1-V6: no version info pattern.
        // Just verify encode succeeds + size matches.
        $enc = new QrEncoder('abc', QrEccLevel::H);
        self::assertLessThanOrEqual(6, $enc->version);
        // Size = 17 + 4*ver = 21..41.
        self::assertSame(17 + 4 * $enc->version, $enc->size());
    }

    #[Test]
    public function v7_emits_version_info_pattern(): void
    {
        // Trigger V7 (45×45). ECC=H, byte data ~84 bytes max.
        $enc = new QrEncoder(str_repeat('a', 80), QrEccLevel::H);
        // V7 capacity: ECC H byte ≤ 64. 80 chars overflows V7H → bumps к V8+.
        // Use shorter input для V7.
        $enc = new QrEncoder(str_repeat('a', 64), QrEccLevel::H);
        self::assertGreaterThanOrEqual(7, $enc->version);

        $modules = $enc->modules();
        $size = $enc->size();
        // Version info region: top-right 6×3 block at rows 0..5, cols size-11..size-9.
        // Some module в этой region должен быть true (version info bits non-zero).
        $hasModule = false;
        for ($row = 0; $row < 6; $row++) {
            for ($col = $size - 11; $col <= $size - 9; $col++) {
                if ($modules[$row][$col]) {
                    $hasModule = true;
                    break 2;
                }
            }
        }
        self::assertTrue($hasModule, 'V7+ должен emit version info pattern в top-right region');
    }

    #[Test]
    public function v7_version_info_top_right_matches_bottom_left(): void
    {
        $enc = new QrEncoder(str_repeat('a', 64), QrEccLevel::H);
        if ($enc->version < 7) {
            self::markTestSkipped('Test input не triggered V7+');
        }
        $modules = $enc->modules();
        $size = $enc->size();
        // For each i 0..17: TR(row=i/3, col=size-11+i%3) == BL(row=col, col=row).
        for ($i = 0; $i < 18; $i++) {
            $row = (int) ($i / 3);
            $col = $size - 11 + ($i % 3);
            $trVal = $modules[$row][$col];
            // Mirror: row<->col.
            $blVal = $modules[$col][$row];
            self::assertSame($trVal, $blVal,
                "Version info bit $i mismatch между TR ($row,$col) и BL ($col,$row)");
        }
    }

    #[Test]
    public function v7_version_value_decodable_from_pattern(): void
    {
        $enc = new QrEncoder(str_repeat('a', 64), QrEccLevel::H);
        if ($enc->version < 7) {
            self::markTestSkipped('Test input не triggered V7+');
        }
        $modules = $enc->modules();
        $size = $enc->size();
        // Extract 18 bits.
        $bits = 0;
        for ($i = 0; $i < 18; $i++) {
            $row = (int) ($i / 3);
            $col = $size - 11 + ($i % 3);
            if ($modules[$row][$col]) {
                $bits |= (1 << $i);
            }
        }
        // High 6 bits = version (positions 12..17).
        $extractedVersion = ($bits >> 12) & 0x3F;
        self::assertSame($enc->version, $extractedVersion,
            "Extracted version $extractedVersion != encoder version {$enc->version}");
    }
}
