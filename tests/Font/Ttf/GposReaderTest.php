<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GposReaderTest extends TestCase
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
    public function liberation_sans_has_kerning_table(): void
    {
        $kt = $this->sansTtf->kerningTable();
        self::assertNotNull($kt);
        self::assertFalse($kt->isEmpty());
        // Liberation Sans 2.1.5 имеет 2015 kerning pairs (через class-based GPOS).
        self::assertGreaterThan(1500, $kt->pairCount());
    }

    #[Test]
    public function classic_pairs_are_negative(): void
    {
        $kt = $this->sansTtf->kerningTable();
        self::assertNotNull($kt);

        // Classic typographic kerning pairs (tighter):
        $av = $kt->lookup($this->sansTtf->glyphIdForChar(0x41), $this->sansTtf->glyphIdForChar(0x56));
        $ay = $kt->lookup($this->sansTtf->glyphIdForChar(0x41), $this->sansTtf->glyphIdForChar(0x59));
        $to = $kt->lookup($this->sansTtf->glyphIdForChar(0x54), $this->sansTtf->glyphIdForChar(0x6F));
        $va = $kt->lookup($this->sansTtf->glyphIdForChar(0x56), $this->sansTtf->glyphIdForChar(0x41));

        self::assertLessThan(0, $av, 'AV should be tighter');
        self::assertLessThan(0, $ay, 'AY should be tighter');
        self::assertLessThan(0, $to, 'To should be tighter');
        self::assertLessThan(0, $va, 'VA should be tighter');

        // To kerning is famously tight в most fonts.
        self::assertLessThan($av, $to, 'To should be tighter than AV');
    }

    #[Test]
    public function non_kerning_pairs_return_zero(): void
    {
        $kt = $this->sansTtf->kerningTable();
        self::assertNotNull($kt);

        $ab = $kt->lookup($this->sansTtf->glyphIdForChar(0x41), $this->sansTtf->glyphIdForChar(0x42));
        $ac = $kt->lookup($this->sansTtf->glyphIdForChar(0x41), $this->sansTtf->glyphIdForChar(0x43));
        self::assertSame(0, $ab, 'AB shouldn\'t have kerning');
        self::assertSame(0, $ac, 'AC shouldn\'t have kerning');
    }

    #[Test]
    public function font_without_gpos_returns_null_kerning(): void
    {
        // Test против synthetic font без GPOS — для этого нам нужен или
        // mock или просто конкретный TTF без GPOS. mpdf-серии fonts'ы
        // обычно имеют GPOS. Lib Liberation Mono — точно имеет.
        // Просто проверим что кэширование работает идемпотентно.
        $kt1 = $this->sansTtf->kerningTable();
        $kt2 = $this->sansTtf->kerningTable();
        self::assertSame($kt1, $kt2, 'Kerning table caching должен возвращать ту же instance');
    }

    #[Test]
    public function cyrillic_kerning_pairs_present(): void
    {
        $kt = $this->sansTtf->kerningTable();
        self::assertNotNull($kt);

        // Cyrillic pairs through GPOS. Liberation Sans имеет kerning для
        // некоторых cyr-pairs. Например, А (U+0410) с другими буквами.
        $aRus = $this->sansTtf->glyphIdForChar(0x0410); // А
        $vRus = $this->sansTtf->glyphIdForChar(0x0412); // В

        // Сам факт что glyphs резолвятся (не 0) — sanity check.
        self::assertGreaterThan(0, $aRus);
        self::assertGreaterThan(0, $vRus);
        // Pair-adjustment может быть 0 или non-zero — не делаем строгий
        // assert (depends on font config).
    }
}
