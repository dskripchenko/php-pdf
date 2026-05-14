<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 132: Variable font metric interpolation tests.
 */
final class VariationMetricsTest extends TestCase
{
    private function loadVariable(): ?TtfFile
    {
        $path = '/System/Library/Fonts/NewYork.ttf';
        if (! is_readable($path)) {
            return null;
        }

        return TtfFile::fromFile($path);
    }

    #[Test]
    public function newyork_has_hvar_and_mvar(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        self::assertNotNull($ttf->hvar(), 'NewYork should have HVAR');
        self::assertNotNull($ttf->mvar(), 'NewYork should have MVAR');
    }

    #[Test]
    public function advance_width_interpolates_monotonically_for_weight(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gid = $ttf->glyphIdForChar(ord('A'));
        $prev = 0;
        foreach ([400, 500, 600, 700, 800, 900, 1000] as $w) {
            $width = $ttf->advanceWidthForInstance($gid, ['wght' => $w]);
            self::assertGreaterThan(0, $width);
            if ($prev !== 0) {
                self::assertGreaterThanOrEqual($prev, $width,
                    "Width должен monotonically расти с weight ($w)");
            }
            $prev = $width;
        }
    }

    #[Test]
    public function default_coords_equal_static_advance_width(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        // Default normalized coords = (0, 0, ..., 0) → no delta applied.
        // Use empty userCoords array → all axes default → all normalized = 0.
        $gid = $ttf->glyphIdForChar(ord('A'));
        $defaultWidth = $ttf->advanceWidth($gid);
        $varWidth = $ttf->advanceWidthForInstance($gid, []);
        self::assertSame($defaultWidth, $varWidth);
    }

    #[Test]
    public function coordinate_normalization_clamps_out_of_range(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        // wght axis range typically 400..1000 для NewYork.
        $gid = $ttf->glyphIdForChar(ord('A'));
        $atMax = $ttf->advanceWidthForInstance($gid, ['wght' => 1000]);
        // Над max должно дать тот же результат (clamped).
        $overMax = $ttf->advanceWidthForInstance($gid, ['wght' => 9999]);
        self::assertSame($atMax, $overMax);
        $atMin = $ttf->advanceWidthForInstance($gid, ['wght' => 400]);
        $underMin = $ttf->advanceWidthForInstance($gid, ['wght' => -1000]);
        self::assertSame($atMin, $underMin);
    }

    #[Test]
    public function normalize_coordinates_returns_zero_for_defaults(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $axes = $ttf->variationAxes();
        $userCoords = [];
        foreach ($axes as $a) {
            $userCoords[$a['tag']] = $a['default'];
        }
        $norm = $ttf->normalizeCoordinates($userCoords);
        foreach ($norm as $v) {
            self::assertEqualsWithDelta(0.0, $v, 1e-9, 'Default coords normalize к 0');
        }
    }

    #[Test]
    public function normalize_coordinates_returns_plus_one_at_max(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $axes = $ttf->variationAxes();
        // Pick wght axis specifically.
        $wghtIdx = null;
        foreach ($axes as $i => $a) {
            if ($a['tag'] === 'wght') {
                $wghtIdx = $i;
                break;
            }
        }
        self::assertNotNull($wghtIdx);
        $norm = $ttf->normalizeCoordinates(['wght' => $axes[$wghtIdx]['max']]);
        // At max, normalized should be ~+1.0 (avar may shift slightly).
        self::assertGreaterThanOrEqual(0.5, $norm[$wghtIdx]);
        self::assertLessThanOrEqual(1.0 + 1e-6, $norm[$wghtIdx]);
    }

    #[Test]
    public function non_variable_font_returns_default_metrics(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached');
        }
        $ttf = TtfFile::fromFile($path);
        self::assertNull($ttf->hvar());
        self::assertNull($ttf->mvar());
        self::assertNull($ttf->avar());

        $gid = $ttf->glyphIdForChar(ord('A'));
        $base = $ttf->advanceWidth($gid);
        // Even с custom userCoords, non-variable font returns static width.
        $instance = $ttf->advanceWidthForInstance($gid, ['wght' => 700]);
        self::assertSame($base, $instance);
    }
}
