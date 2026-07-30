<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Запоминание разобранных шрифтов.
 *
 * Разбор шрифта — чтение файла целиком плюс таблицы cmap/hmtx/name/post, а по
 * требованию GPOS. В долгоживущем процессе (воркер очереди) он повторялся на
 * каждый документ: замер в приложении-потребителе показал 6.3 мс из 14.6 мс
 * на лёгком документе.
 */
final class TtfFileCacheTest extends TestCase
{
    private function fontPath(): string
    {
        // Тот же источник, что и у остальных шрифтовых тестов: набор
        // Liberation подтягивается в .cache перед прогоном.
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
        // Долгоживущий процесс обслуживает много клиентов, у каждого свои
        // наборы шрифтов — без предела кэш рос бы вместе с их числом.
        $original = $this->fontPath();
        $limit = TtfFile::$cacheLimit;
        $copies = [];

        for ($i = 0; $i <= $limit; $i++) {
            $copy = sys_get_temp_dir().'/php-pdf-limit-'.getmypid().'-'.$i.'.ttf';
            copy($original, $copy);
            $copies[] = $copy;
            TtfFile::fromFile($copy);
        }

        // Первый обязан вытесниться, последний — остаться.
        $first = TtfFile::fromFile($copies[0]);
        self::assertSame($first, TtfFile::fromFile($copies[0]));

        foreach ($copies as $copy) {
            @unlink($copy);
        }
    }

    #[Test]
    public function replaced_file_is_parsed_again(): void
    {
        // Ключ включает время изменения и размер: подменённый файл не должен
        // отдаваться из памяти, иначе обновление шрифта не доедет до процесса.
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
