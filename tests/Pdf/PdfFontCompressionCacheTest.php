<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Запоминание сжатых тел шрифтов.
 *
 * `gzcompress` уровня 6 на теле шрифта — самая дорогая часть встраивания:
 * замер в приложении-потребителе показал 2.2 мс на документ при двух шрифтах
 * по 62 КБ, а весь рендер занимал 12.6 мс. Тело от документа к документу одно
 * и то же, поэтому результат запоминается.
 *
 * Тест держит два свойства: результат из кэша совпадает с честно сжатым (иначе
 * документы разъехались бы молча) и кэш не растёт без предела.
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

    /** Доступ к приватному сжатию — проверяем именно его, а не весь рендер. */
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
        // Ключ — хэш содержимого: сабсет у каждого документа свой, и подмена
        // тела чужим сжатым потоком испортила бы документ.
        $a = str_repeat('a', 5000);
        $b = str_repeat('b', 5000);

        self::assertNotSame($this->compress($a), $this->compress($b));
        self::assertSame($a, gzuncompress($this->compress($a)));
        self::assertSame($b, gzuncompress($this->compress($b)));
    }

    #[Test]
    public function cache_does_not_grow_without_bound(): void
    {
        // Долгоживущий процесс обслуживает документы с разными шрифтами —
        // без предела кэш рос бы вместе с их числом.
        $limit = PdfFont::$compressedCacheLimit;

        for ($i = 0; $i <= $limit + 5; $i++) {
            $this->compress(str_repeat("шрифт-{$i}", 500));
        }

        $prop = new \ReflectionProperty(PdfFont::class, 'compressedCache');

        self::assertLessThanOrEqual($limit, count($prop->getValue()));
    }
}
