<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GsubReaderTest extends TestCase
{
    private TtfFile $sansTtf;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->sansTtf = TtfFile::fromFile($path);
    }

    #[Test]
    public function liberation_has_no_liga_feature(): void
    {
        // Liberation fonts metric-compatible с MS Arial и не имеют 'liga'
        // (MS Arial тоже без него). Это design decision Liberation team.
        // GsubReader должен вернуть null/empty.
        $ligs = $this->sansTtf->ligatures();
        // Может быть null (если GSUB отсутствует) ИЛИ empty (если есть
        // GSUB но нет 'liga' feature).
        $isEmpty = $ligs === null || $ligs->isEmpty();
        self::assertTrue($isEmpty, 'Liberation должна не иметь \'liga\' feature substitutions');
    }

    #[Test]
    public function cached_ligatures_returns_same_instance(): void
    {
        $l1 = $this->sansTtf->ligatures();
        $l2 = $this->sansTtf->ligatures();
        self::assertSame($l1, $l2, 'Ligatures должны caching работать idempotent');
    }
}
