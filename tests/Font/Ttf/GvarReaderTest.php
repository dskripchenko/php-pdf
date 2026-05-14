<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 133: gvar glyph variation parser tests.
 */
final class GvarReaderTest extends TestCase
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
    public function newyork_has_gvar_with_axis_count_matching_fvar(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gvar = $ttf->gvar();
        self::assertNotNull($gvar);
        self::assertSame(count($ttf->variationAxes()), $gvar->axisCount);
    }

    #[Test]
    public function gvar_offsets_have_glyph_count_plus_one(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gvar = $ttf->gvar();
        self::assertNotNull($gvar);
        // Offsets count = glyphCount + 1 (last is end sentinel).
        self::assertSame($ttf->numGlyphs() + 1, count($gvar->glyphDataOffsets));
    }

    #[Test]
    public function default_coords_produce_zero_deltas(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gvar = $ttf->gvar();
        self::assertNotNull($gvar);

        $gid = $ttf->glyphIdForChar(ord('A'));
        // Default normalized coords = all zeros → no variation applied.
        $deltas = $gvar->glyphDeltas($gid, [0 => 0.0, 1 => 0.0, 2 => 0.0], 100);

        // Sum of all deltas должен быть 0 (regions с peak != 0 give 0 scalar at coord 0).
        $sumX = 0.0;
        $sumY = 0.0;
        foreach ($deltas as $d) {
            $sumX += $d['x'];
            $sumY += $d['y'];
        }
        self::assertEqualsWithDelta(0.0, $sumX, 1e-6);
        self::assertEqualsWithDelta(0.0, $sumY, 1e-6);
    }

    #[Test]
    public function heavier_weight_increases_outline_displacement(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gvar = $ttf->gvar();
        self::assertNotNull($gvar);

        $gid = $ttf->glyphIdForChar(ord('A'));
        $norm700 = $ttf->normalizeCoordinates(['wght' => 700]);
        $norm1000 = $ttf->normalizeCoordinates(['wght' => 1000]);

        $deltas700 = $gvar->glyphDeltas($gid, $norm700, 100);
        $deltas1000 = $gvar->glyphDeltas($gid, $norm1000, 100);

        // Heavier weight → larger absolute deltas (more displacement).
        $magnitude700 = 0.0;
        $magnitude1000 = 0.0;
        foreach ($deltas700 as $d) {
            $magnitude700 += abs($d['x']) + abs($d['y']);
        }
        foreach ($deltas1000 as $d) {
            $magnitude1000 += abs($d['x']) + abs($d['y']);
        }
        self::assertGreaterThan($magnitude700, $magnitude1000);
    }

    #[Test]
    public function non_variable_font_has_no_gvar(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached');
        }
        $ttf = TtfFile::fromFile($path);
        self::assertNull($ttf->gvar());
    }

    #[Test]
    public function deltas_are_floating_point(): void
    {
        $ttf = $this->loadVariable();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $gvar = $ttf->gvar();
        self::assertNotNull($gvar);

        $gid = $ttf->glyphIdForChar(ord('A'));
        $norm = $ttf->normalizeCoordinates(['wght' => 700]);
        $deltas = $gvar->glyphDeltas($gid, $norm, 100);

        self::assertNotEmpty($deltas);
        foreach ($deltas as $d) {
            self::assertIsFloat($d['x']);
            self::assertIsFloat($d['y']);
        }
    }
}
