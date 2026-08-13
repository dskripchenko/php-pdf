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
        // The Liberation fonts are metric-compatible with MS Arial and have no
        // 'liga' (MS Arial has none either). That is a design decision of the
        // Liberation team. GsubReader has to return null or empty.
        $ligs = $this->sansTtf->ligatures();
        // It can be null (when GSUB is absent) OR empty (when GSUB is there but
        // the 'liga' feature is not).
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
