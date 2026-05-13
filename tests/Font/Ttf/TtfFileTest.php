<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests против real Liberation Sans Regular TTF в .cache/fonts/.
 *
 * Файл скачивается отдельно (composer'ом не управляется), поэтому
 * тесты skip'аются если его нет.
 */
final class TtfFileTest extends TestCase
{
    private string $liberationSansPath;

    protected function setUp(): void
    {
        $this->liberationSansPath = __DIR__
            .'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        if (! is_readable($this->liberationSansPath)) {
            self::markTestSkipped(
                'Liberation Sans Regular not cached at '.$this->liberationSansPath
                .'. Run scripts/fetch-fonts.sh (or curl manually from upstream).',
            );
        }
    }

    #[Test]
    public function parses_postscript_name(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        self::assertSame('LiberationSans', $ttf->postScriptName());
    }

    #[Test]
    public function parses_units_per_em(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        // Стандарт для TTF — 2048.
        self::assertSame(2048, $ttf->unitsPerEm());
    }

    #[Test]
    public function parses_glyph_count(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        // Liberation 2.1.5 имеет ровно 2620 glyph'ов.
        self::assertSame(2620, $ttf->numGlyphs());
    }

    #[Test]
    public function parses_font_bbox(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $bbox = $ttf->bbox();
        self::assertCount(4, $bbox);
        [$xMin, $yMin, $xMax, $yMax] = $bbox;
        self::assertLessThan(0, $xMin); // Liberation Sans имеет negative xMin
        self::assertLessThan(0, $yMin);
        self::assertGreaterThan(2000, $xMax);
        self::assertGreaterThan(1500, $yMax);
    }

    #[Test]
    public function cmap_resolves_latin_chars(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        // ASCII U+0048 (H), U+0065 (e), U+006C (l), U+006F (o).
        self::assertSame(43, $ttf->glyphIdForChar(0x48));
        self::assertSame(72, $ttf->glyphIdForChar(0x65));
        self::assertSame(79, $ttf->glyphIdForChar(0x6C));
        self::assertSame(82, $ttf->glyphIdForChar(0x6F));
    }

    #[Test]
    public function cmap_resolves_cyrillic_chars(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        // U+0410 (А), U+042F (Я), U+0440 (р), U+0438 (и)
        self::assertGreaterThan(0, $ttf->glyphIdForChar(0x0410));
        self::assertGreaterThan(0, $ttf->glyphIdForChar(0x042F));
        self::assertGreaterThan(0, $ttf->glyphIdForChar(0x0440));
        self::assertGreaterThan(0, $ttf->glyphIdForChar(0x0438));
    }

    #[Test]
    public function cmap_returns_zero_notdef_for_unsupported_chars(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        // U+1F600 (😀 emoji) — Liberation Sans не покрывает, должно быть 0.
        self::assertSame(0, $ttf->glyphIdForChar(0x1F600));
    }

    #[Test]
    public function advance_widths_are_in_font_units(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        $gidH = $ttf->glyphIdForChar(0x48);
        $w = $ttf->advanceWidth($gidH);
        // H в Liberation Sans 2048-em — около 1479 FU.
        self::assertSame(1479, $w);
    }

    #[Test]
    public function ascent_descent_are_signed(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        self::assertGreaterThan(0, $ttf->ascent());
        self::assertLessThan(0, $ttf->descent());
    }

    #[Test]
    public function is_not_fixed_pitch_for_sans(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        self::assertFalse($ttf->isFixedPitch());
    }

    #[Test]
    public function italic_angle_zero_for_regular(): void
    {
        $ttf = TtfFile::fromFile($this->liberationSansPath);
        self::assertSame(0, $ttf->italicAngle());
    }

    #[Test]
    public function rejects_otf_cff_files(): void
    {
        // OTF файлы с CFF имеют magic 'OTTO' (0x4F54544F).
        $fakeOtf = "OTTO\x00\x01\x00\x00";
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CFF-based OTF');
        new TtfFile($fakeOtf);
    }

    #[Test]
    public function liberation_mono_is_fixed_pitch(): void
    {
        $monoPath = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationMono-Regular.ttf';
        if (! is_readable($monoPath)) {
            self::markTestSkipped('LiberationMono-Regular.ttf not in cache.');
        }
        $ttf = TtfFile::fromFile($monoPath);
        self::assertTrue($ttf->isFixedPitch());
    }

    #[Test]
    public function liberation_serif_italic_has_negative_angle(): void
    {
        $italicPath = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSerif-Italic.ttf';
        if (! is_readable($italicPath)) {
            self::markTestSkipped('LiberationSerif-Italic.ttf not in cache.');
        }
        $ttf = TtfFile::fromFile($italicPath);
        // Italic — typically negative italicAngle (например, -12).
        self::assertLessThan(0, $ttf->italicAngle());
    }
}
