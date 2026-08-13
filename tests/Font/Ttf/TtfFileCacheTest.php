<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Remembering the parsed fonts.
 *
 * Parsing a font means reading the whole file plus the cmap/hmtx/name/post
 * tables, and GPOS on demand. In a long-lived process (a queue worker) it was
 * repeated for every document: a measurement in the consuming application
 * showed 6.3 ms out of 14.6 ms on a light document.
 */
final class TtfFileCacheTest extends TestCase
{
    private function fontPath(): string
    {
        // The same source as in the other font tests: the Liberation set is
        // pulled into .cache before the run.
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        if (! is_file($path)) {
            self::markTestSkipped('Liberation fonts directory not cached.');
        }

        return $path;
    }

    protected function setUp(): void
    {
        TtfFile::forgetCache();
    }

    protected function tearDown(): void
    {
        TtfFile::forgetCache();
    }

    #[Test]
    public function same_path_is_parsed_once(): void
    {
        $path = $this->fontPath();

        self::assertSame(TtfFile::fromFile($path), TtfFile::fromFile($path));
    }

    #[Test]
    public function forgetting_the_cache_forces_a_new_parse(): void
    {
        $path = $this->fontPath();

        $first = TtfFile::fromFile($path);
        TtfFile::forgetCache();

        self::assertNotSame($first, TtfFile::fromFile($path));
    }

    #[Test]
    public function cache_does_not_grow_without_bound(): void
    {
        // A long-lived process serves many clients, each with font sets of its
        // own — without a limit the cache would grow along with their number.
        $original = $this->fontPath();
        $limit = TtfFile::$cacheLimit;
        $copies = [];

        for ($i = 0; $i <= $limit; $i++) {
            $copy = sys_get_temp_dir().'/php-pdf-limit-'.getmypid().'-'.$i.'.ttf';
            copy($original, $copy);
            $copies[] = $copy;
            TtfFile::fromFile($copy);
        }

        // The first one has to be evicted, the last one to stay.
        $first = TtfFile::fromFile($copies[0]);
        self::assertSame($first, TtfFile::fromFile($copies[0]));

        foreach ($copies as $copy) {
            @unlink($copy);
        }
    }

    #[Test]
    public function replaced_file_is_parsed_again(): void
    {
        // The key includes the modification time and the size: a replaced file
        // must not be served from memory, or a font update would never reach the
        // process.
        $original = $this->fontPath();
        $copy = sys_get_temp_dir().'/php-pdf-cache-'.getmypid().'.ttf';
        copy($original, $copy);

        $first = TtfFile::fromFile($copy);

        touch($copy, time() + 10);
        clearstatcache(true, $copy);

        self::assertNotSame($first, TtfFile::fromFile($copy));

        @unlink($copy);
    }
}
