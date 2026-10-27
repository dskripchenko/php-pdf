<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\KerningTable;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Font\Ttf\TtfSubsetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parsed font saved as plain data and restored without parsing again — what a
 * cache across requests stores. The state carries a format version, so a cache
 * never restores a state written by a php-pdf that laid it out differently.
 */
final class TtfFileStateTest extends TestCase
{
    private const FONTS = __DIR__.'/../../../.cache/fonts/';

    private function font(string $file): string
    {
        $path = self::FONTS.$file;
        if (! is_readable($path)) {
            self::markTestSkipped("$file not cached.");
        }

        return (string) file_get_contents($path);
    }

    #[Test]
    public function a_restored_font_answers_like_the_parsed_one(): void
    {
        $bytes = $this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf');
        $parsed = new TtfFile($bytes);

        $restored = TtfFile::fromState(unserialize(serialize($parsed->exportState())), $bytes);

        self::assertSame($parsed->unitsPerEm(), $restored->unitsPerEm());
        self::assertSame($parsed->numGlyphs(), $restored->numGlyphs());
        self::assertSame($parsed->bbox(), $restored->bbox());
        self::assertSame($parsed->ascent(), $restored->ascent());
        self::assertSame($parsed->descent(), $restored->descent());
        self::assertSame($parsed->postScriptName(), $restored->postScriptName());
        self::assertSame($parsed->italicAngle(), $restored->italicAngle());
        self::assertSame($parsed->isFixedPitch(), $restored->isFixedPitch());
        self::assertSame($parsed->nameById(1), $restored->nameById(1));
        self::assertSame($bytes, $restored->rawBytes());
        foreach ([0x41, 0x56, 0xE9, 0x416, 0x20AC] as $cp) {
            $gid = $parsed->glyphIdForChar($cp);
            self::assertSame($gid, $restored->glyphIdForChar($cp));
            self::assertSame($parsed->advanceWidth($gid), $restored->advanceWidth($gid));
        }
        $a = $parsed->glyphIdForChar(0x41);
        $v = $parsed->glyphIdForChar(0x56);
        self::assertNotSame(0, $restored->kerningTable()?->lookup($a, $v));
        self::assertSame($parsed->kerningTable()?->lookup($a, $v), $restored->kerningTable()?->lookup($a, $v));
        self::assertSame($parsed->kerningTable()?->pairCount(), $restored->kerningTable()?->pairCount());
        self::assertSame($parsed->ligatures()?->isEmpty(), $restored->ligatures()?->isEmpty(), 'lazy tables still parse');
    }

    #[Test]
    public function a_restored_font_subsets_to_the_same_bytes(): void
    {
        $bytes = $this->font('liberation-fonts-ttf-2.1.5/LiberationSerif-Bold.ttf');
        $glyphs = [3, 36, 37, 68, 69, 70, 500];

        $fromParse = (new TtfSubsetter)->subset(new TtfFile($bytes), $glyphs);
        TtfSubsetter::clearCache();
        $fromState = (new TtfSubsetter)->subset(TtfFile::fromState((new TtfFile($bytes))->exportState(), $bytes), $glyphs);

        self::assertSame($fromParse, $fromState);
    }

    #[Test]
    public function a_cff_font_round_trips(): void
    {
        $bytes = $this->font('SourceSans3-Regular.otf');
        $parsed = new TtfFile($bytes);

        $restored = TtfFile::fromState($parsed->exportState(), $bytes);

        self::assertTrue($restored->isCff());
        self::assertSame($parsed->cffTableBytes(), $restored->cffTableBytes());
        self::assertSame($parsed->cidForGlyph(40), $restored->cidForGlyph(40));
    }

    #[Test]
    public function the_state_is_plain_data_that_var_export_round_trips(): void
    {
        $state = (new TtfFile($this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')))->exportState();

        array_walk_recursive($state, static function (mixed $value): void {
            self::assertTrue(is_scalar($value) || $value === null, 'only scalars, strings, null and arrays');
        });
        self::assertSame(TtfFile::STATE_FORMAT, $state['format']);
        self::assertSame($state, eval('return '.var_export($state, true).';'));
    }

    #[Test]
    public function a_state_of_another_format_is_refused(): void
    {
        $bytes = $this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf');
        $state = (new TtfFile($bytes))->exportState();
        $state['format'] = TtfFile::STATE_FORMAT + 1;

        $this->expectException(\InvalidArgumentException::class);
        TtfFile::fromState($state, $bytes);
    }

    #[Test]
    public function a_state_is_refused_for_another_font(): void
    {
        $state = (new TtfFile($this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')))->exportState();

        $this->expectException(\InvalidArgumentException::class);
        TtfFile::fromState($state, $this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Bold.ttf'));
    }

    #[Test]
    public function a_damaged_state_is_refused(): void
    {
        $bytes = $this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf');
        $good = (new TtfFile($bytes))->exportState();

        $damaged = [
            'missing key' => array_diff_key($good, ['cmap' => true]),
            'wrong type' => ['unitsPerEm' => 'many'] + $good,
            'bad kerning' => ['kerningTable' => [['pairs', 'not an array']]] + $good,
            'not an array' => ['cmap' => 'x'] + $good,
            'cmap value' => ['cmap' => [65 => 'A']] + $good,
            'cmap key' => ['cmap' => ['A' => 36]] + $good,
            'advance widths not a list' => ['advanceWidths' => [5 => 500]] + $good,
            'advance width type' => ['advanceWidths' => [500, '500']] + $good,
            'bbox size' => ['bbox' => [0, 0, 100]] + $good,
            'zero units per em' => ['unitsPerEm' => 0] + $good,
            'table past the end' => ['tables' => ['head' => ['offset' => strlen($bytes), 'length' => 54]]] + $good,
            'table entry' => ['tables' => ['head' => ['offset' => '0', 'length' => 54]]] + $good,
            'name value' => ['namesById' => [1 => 42]] + $good,
        ];
        foreach ($damaged as $case => $state) {
            try {
                TtfFile::fromState($state, $bytes);
                self::fail("$case: accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function every_property_is_saved_rebuilt_or_left_lazy(): void
    {
        // When this fails, TtfFile gained a property: add it to exportState()/fromState()
        // or to one of the lists below, and bump STATE_FORMAT.
        $state = (new TtfFile($this->font('liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')))->exportState();
        // fromState() rebuilds these from the font bytes rather than reading them from a state.
        $rebuilt = ['reader', 'bytes', 'cff', 'kerningParsed'];
        // A state leaves these unparsed: they parse on first use, as after `new TtfFile()`.
        $lazy = [
            'ligatures', 'ligaturesParsed', 'singleSubstByFeature', 'fvarParsed',
            'avar', 'avarParsed', 'hvar', 'hvarParsed', 'mvar', 'mvarParsed',
            'gvar', 'gvarParsed', 'advanceHeights',
        ];
        $covered = [...array_keys($state), ...$rebuilt, ...$lazy];

        $properties = [];
        foreach ((new \ReflectionClass(TtfFile::class))->getProperties() as $p) {
            if (! $p->isStatic()) {
                $properties[] = $p->getName();
            }
        }
        $missing = array_diff($properties, $covered);

        self::assertSame([], array_values($missing), 'properties no state decision covers');
    }

    #[Test]
    public function kerning_tables_round_trip_their_packed_layers(): void
    {
        $kt = new KerningTable;
        $kt->add(1, 2, -50);
        $kt->add(1, 3, -20);
        // glyph 1 covered in class 0; glyph 2 in second class 1; matrix 1 x 2
        $kt->addClasses("\0\1", pack('n*', 0, 0), pack('n*', 0, 0, 1), 2, pack('n*', 0, (-30) & 0xFFFF));

        $restored = KerningTable::fromState(unserialize(serialize($kt->exportState())));

        self::assertSame(-30, $restored->lookup(1, 2), 'the newer class layer still answers first');
        self::assertSame(-20, $restored->lookup(1, 3));
        self::assertSame(0, $restored->lookup(2, 3));
        self::assertSame($kt->pairCount(), $restored->pairCount());
    }
}
