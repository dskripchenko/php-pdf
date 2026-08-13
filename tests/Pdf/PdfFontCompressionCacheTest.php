<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Remembering the compressed font bodies.
 *
 * Running `gzcompress` at level 6 over a font body is the most expensive part
 * of embedding: a measurement in the consuming application showed 2.2 ms per
 * document with two fonts of 62 KB, while the whole render took 12.6 ms. The
 * body is one and the same from document to document, so the result is
 * remembered.
 *
 * The test holds two properties: what comes out of the cache matches what was
 * compressed honestly (otherwise documents would drift apart silently), and the
 * cache does not grow without a limit.
 */
final class PdfFontCompressionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        PdfFont::forgetCompressedCache();
    }

    protected function tearDown(): void
    {
        PdfFont::forgetCompressedCache();
    }

    /** Access to the private compression — it is what is under test, not the whole render. */
    private function compress(string $bytes): string
    {
        $m = new \ReflectionMethod(PdfFont::class, 'compress');

        return $m->invoke(null, $bytes);
    }

    #[Test]
    public function cached_result_matches_a_fresh_compression(): void
    {
        $bytes = random_bytes(4096).str_repeat('шрифт', 2000);

        $first = $this->compress($bytes);
        $second = $this->compress($bytes);          // уже из кэша

        self::assertSame($first, $second);
        self::assertSame(gzcompress($bytes, 6), $first, 'сжатие должно остаться тем же');
        self::assertSame($bytes, gzuncompress($first));
    }

    #[Test]
    public function different_bodies_do_not_share_a_cache_entry(): void
    {
        // The key is the hash of the content: every document has a subset of its
        // own, and swapping in someone else's compressed stream would ruin the
        // document.
        $a = str_repeat('a', 5000);
        $b = str_repeat('b', 5000);

        self::assertNotSame($this->compress($a), $this->compress($b));
        self::assertSame($a, gzuncompress($this->compress($a)));
        self::assertSame($b, gzuncompress($this->compress($b)));
    }

    #[Test]
    public function cache_does_not_grow_without_bound(): void
    {
        // A long-lived process serves documents with different fonts — without a
        // limit the cache would grow along with their number.
        $limit = PdfFont::$compressedCacheLimit;

        for ($i = 0; $i <= $limit + 5; $i++) {
            $this->compress(str_repeat("шрифт-{$i}", 500));
        }

        $prop = new \ReflectionProperty(PdfFont::class, 'compressedCache');

        self::assertLessThanOrEqual($limit, count($prop->getValue()));
    }
}
