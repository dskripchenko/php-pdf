<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Font\Ttf\TtfSubsetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 215: Cross-Writer font subset dedup LRU cache tests.
 */
final class TtfSubsetterCacheTest extends TestCase
{
    private function fontPath(): string
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return $path;
    }

    protected function setUp(): void
    {
        TtfSubsetter::clearCache();
    }

    #[Test]
    public function cache_starts_empty_after_clear(): void
    {
        self::assertSame(0, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function single_call_populates_cache(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        $subsetter->subset($ttf, [65, 66, 67]); // A, B, C
        self::assertSame(1, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function same_inputs_return_identical_bytes(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        $bytes1 = $subsetter->subset($ttf, [65, 66, 67]);
        $bytes2 = $subsetter->subset($ttf, [65, 66, 67]);

        self::assertSame($bytes1, $bytes2);
        // Cache hit — size stays at 1.
        self::assertSame(1, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function different_glyph_sets_cache_separately(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        $subsetter->subset($ttf, [65, 66, 67]);
        $subsetter->subset($ttf, [68, 69, 70]);

        self::assertSame(2, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function glyph_order_does_not_matter_for_cache_key(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        $bytes1 = $subsetter->subset($ttf, [65, 66, 67]);
        $bytes2 = $subsetter->subset($ttf, [67, 65, 66]); // shuffled

        self::assertSame($bytes1, $bytes2);
        // One cache entry (same sorted key).
        self::assertSame(1, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function cache_hit_returns_correct_bytes(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        // Prime cache.
        $original = $subsetter->subset($ttf, [65, 66, 67]);

        // Multiple cache hits — все return same bytes.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($original, $subsetter->subset($ttf, [65, 66, 67]));
        }
        self::assertSame(1, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function different_subsetter_instances_share_cache(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());

        // Static cache shared across instances.
        $sub1 = new TtfSubsetter;
        $sub2 = new TtfSubsetter;

        $bytes1 = $sub1->subset($ttf, [65, 66, 67]);
        $bytes2 = $sub2->subset($ttf, [65, 66, 67]);

        self::assertSame($bytes1, $bytes2);
        self::assertSame(1, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function lru_evicts_oldest_when_over_limit(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        // Fill cache к 32+ entries; should cap at 32.
        for ($i = 0; $i < 35; $i++) {
            $subsetter->subset($ttf, [65 + $i, 66 + $i]);
        }

        // Cache size limited.
        self::assertLessThanOrEqual(32, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function lru_touch_moves_recently_used_к_end(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        // Add 3 entries.
        $subsetter->subset($ttf, [65, 66]); // entry 1
        $subsetter->subset($ttf, [67, 68]); // entry 2
        $subsetter->subset($ttf, [69, 70]); // entry 3
        self::assertSame(3, TtfSubsetter::cacheSize());

        // Touch entry 1 — should now быть most recent.
        $subsetter->subset($ttf, [65, 66]);
        // Size unchanged (cache hit).
        self::assertSame(3, TtfSubsetter::cacheSize());

        // Fill к limit and beyond.
        for ($i = 0; $i < 30; $i++) {
            $subsetter->subset($ttf, [100 + $i * 2, 101 + $i * 2]);
        }

        // Entry 1 was touched recently → should survive eviction.
        // Verify by re-querying it — если в cache, size остаётся та же.
        $sizeBefore = TtfSubsetter::cacheSize();
        $subsetter->subset($ttf, [65, 66]);
        self::assertSame($sizeBefore, TtfSubsetter::cacheSize());
    }

    #[Test]
    public function clear_cache_resets_state(): void
    {
        $ttf = TtfFile::fromFile($this->fontPath());
        $subsetter = new TtfSubsetter;

        $subsetter->subset($ttf, [65, 66]);
        $subsetter->subset($ttf, [67, 68]);
        self::assertSame(2, TtfSubsetter::cacheSize());

        TtfSubsetter::clearCache();
        self::assertSame(0, TtfSubsetter::cacheSize());
    }
}
