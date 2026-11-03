<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * decodeUtf8 caches glyphs by text: short strings in a large cache, longer
 * ones in a small cache of recent strings.
 *
 * PHP stores a key like "1" as the integer 1, and evicting with array_shift
 * renumbered every integer key: once the cache was full, "1" could come
 * back as the glyphs of "15". A long invoice printed its quantities as 42,
 * 51, 21… from page 43 on.
 */
final class PdfFontDecodeCacheTest extends TestCase
{
    private const CACHE_MAX = 2048;

    private PdfFont $font;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->font = new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function numeric_words_keep_their_glyphs_after_the_cache_evicts(): void
    {
        $expected = [];
        foreach (['1', '15', '21', '42'] as $word) {
            $expected[$word] = $this->font->decodeUtf8($word);
        }
        // Fill the cache past its cap so the oldest entries are evicted.
        for ($i = 0; $i < self::CACHE_MAX + 10; $i++) {
            $this->font->decodeUtf8("w$i");
            $this->font->decodeUtf8((string) (1000 + $i));
        }

        foreach ($expected as $word => $glyphs) {
            // Array keys come back as ints too: "1" is 1.
            self::assertSame($glyphs, $this->font->decodeUtf8((string) $word), "glyphs of \"$word\"");
        }
    }

    #[Test]
    public function a_numeric_word_still_cached_is_not_confused_with_another(): void
    {
        // "1" and "15" stay in the cache while a string key ahead of them
        // is evicted — the case that renumbered "15" to key 1.
        $this->font->decodeUtf8('first');
        $one = $this->font->decodeUtf8('1');
        $this->font->decodeUtf8('15');
        for ($i = 0; $i < self::CACHE_MAX - 2; $i++) {
            $this->font->decodeUtf8("w$i");
        }

        self::assertCount(1, $one);
        self::assertSame($one, $this->font->decodeUtf8('1'));
        self::assertCount(2, $this->font->decodeUtf8('15'));
    }

    #[Test]
    public function long_lines_stay_out_of_the_word_cache(): void
    {
        $word = $this->font->decodeUtf8('invoice');
        for ($i = 0; $i < self::CACHE_MAX + 10; $i++) {
            $this->font->decodeUtf8("Line $i of a paragraph drawn as one run of text.");
        }

        self::assertSame(['invoice'], array_keys($this->cache('decodeCache')));
        self::assertCount(16, $this->cache('recentDecodes'));
        self::assertSame($word, $this->font->decodeUtf8('invoice'));
    }

    #[Test]
    public function a_line_decoded_again_soon_is_served_from_the_cache(): void
    {
        $line = 'A line of text drawn once to shape it, then again to encode it.';
        $glyphs = $this->font->decodeUtf8($line);
        for ($i = 0; $i < 15; $i++) {
            $this->font->decodeUtf8("Another line of text, number $i of fifteen.");
        }

        self::assertArrayHasKey($line, $this->cache('recentDecodes'));
        self::assertSame($glyphs, $this->font->decodeUtf8($line));
    }

    #[Test]
    public function cached_glyphs_match_a_fresh_decode_on_either_side_of_the_cutoff(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        $strings = [str_repeat('a', 32), str_repeat('b', 33), str_repeat('é', 16), str_repeat('é', 17)];
        foreach ($strings as $s) {
            $this->font->decodeUtf8($s);
        }

        foreach ($strings as $s) {
            $fresh = new PdfFont(TtfFile::fromFile($path));
            self::assertSame($fresh->decodeUtf8($s), $this->font->decodeUtf8($s), $s);
        }
        self::assertCount(2, $this->cache('decodeCache'));
        self::assertCount(2, $this->cache('recentDecodes'));
    }

    #[Test]
    public function reset_empties_both_caches(): void
    {
        $this->font->decodeUtf8('word');
        $this->font->decodeUtf8('A line long enough to skip the word cache entirely.');
        $this->font->reset();

        self::assertSame([], $this->cache('decodeCache'));
        self::assertSame([], $this->cache('recentDecodes'));
    }

    /** @return array<array-key, mixed> */
    private function cache(string $name): array
    {
        return (new \ReflectionProperty(PdfFont::class, $name))->getValue($this->font);
    }
}
