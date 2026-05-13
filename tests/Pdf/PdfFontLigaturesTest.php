<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\LigatureSubstitutions;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests для GSUB ligature substitution.
 *
 * Liberation Sans не имеет 'liga' feature (design choice — metric-compat
 * с MS Arial). Так что для real-world проверки используем synthetic
 * LigatureSubstitutions через ReflectionClass.
 */
final class PdfFontLigaturesTest extends TestCase
{
    private TtfFile $ttf;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->ttf = TtfFile::fromFile($path);
    }

    #[Test]
    public function liberation_text_renders_unchanged_without_liga(): void
    {
        // Без 'liga' feature никакие ligatures не substituting.
        $font = new PdfFont($this->ttf);
        $hex = $font->encodeText('fi');
        // f=73 (0x49), i=76 (0x4C). Без ligature substitution оба остаются.
        $fGid = $this->ttf->glyphIdForChar(0x66);
        $iGid = $this->ttf->glyphIdForChar(0x69);
        $expected = sprintf('<%04X%04X>', $fGid, $iGid);
        self::assertSame($expected, $hex,
            'Liberation \'fi\' должны остаться двумя separate glyph IDs',
        );
    }

    #[Test]
    public function used_glyphs_format_is_multi_codepoint(): void
    {
        // Backward-compat tests: usedGlyphs map gid → list<int>.
        $font = new PdfFont($this->ttf);
        $font->encodeText('Hi');

        $refl = new \ReflectionClass($font);
        $prop = $refl->getProperty('usedGlyphs');
        $usedGlyphs = $prop->getValue($font);

        self::assertNotEmpty($usedGlyphs);
        foreach ($usedGlyphs as $gid => $cps) {
            self::assertIsArray($cps);
            self::assertNotEmpty($cps);
        }
    }

    #[Test]
    public function tounicode_cmap_multi_codepoint_format(): void
    {
        // Synthetic ligature: f+i → existing glyph (используем какой-то
        // реальный glyph в font'е чтобы subsetter не пожаловался).
        // Glyph 100 — какая-то Latin буква, неважно какая для теста CMap.
        $ligGid = 100;

        $sub = new LigatureSubstitutions;
        $fGid = $this->ttf->glyphIdForChar(0x66);
        $iGid = $this->ttf->glyphIdForChar(0x69);
        $sub->add($fGid, [$iGid], $ligGid);

        $reflTtf = new \ReflectionClass($this->ttf);
        $prop1 = $reflTtf->getProperty('ligatures');
        $prop1->setValue($this->ttf, $sub);
        $prop2 = $reflTtf->getProperty('ligaturesParsed');
        $prop2->setValue($this->ttf, true);

        try {
            $font = new PdfFont($this->ttf, subset: false);
            $shaped = $font->shapedGlyphs('fi');
            // После substitution — один glyph $ligGid с sources=[f,i]
            self::assertCount(1, $shaped);
            self::assertSame($ligGid, $shaped[0]['gid']);
            self::assertSame([0x66, 0x69], $shaped[0]['sourceCps']);

            // ToUnicode CMap должен содержать <0064> <00660069>.
            // 100 = 0x64; 'f'=0x66, 'i'=0x69 → UTF-16BE <0066 0069>.
            $doc = Document::new();
            $doc->addPage()->showEmbeddedText('fi', 72, 720, $font, 12);
            $pdf = $doc->toBytes();
            self::assertStringContainsString('<0064> <00660069>', $pdf);
        } finally {
            $prop1->setValue($this->ttf, null);
            $prop2->setValue($this->ttf, false);
        }
    }

    #[Test]
    public function ligatures_can_be_disabled(): void
    {
        $sub = new LigatureSubstitutions;
        $fGid = $this->ttf->glyphIdForChar(0x66);
        $iGid = $this->ttf->glyphIdForChar(0x69);
        $sub->add($fGid, [$iGid], 100);

        $reflTtf = new \ReflectionClass($this->ttf);
        $prop1 = $reflTtf->getProperty('ligatures');
        $prop1->setValue($this->ttf, $sub);
        $prop2 = $reflTtf->getProperty('ligaturesParsed');
        $prop2->setValue($this->ttf, true);

        try {
            $font = new PdfFont($this->ttf);
            $font->disableLigatures();

            $shaped = $font->shapedGlyphs('fi');
            // Without ligatures — два separate glyph'а.
            self::assertCount(2, $shaped);
            self::assertSame($fGid, $shaped[0]['gid']);
            self::assertSame($iGid, $shaped[1]['gid']);
        } finally {
            $prop1->setValue($this->ttf, null);
            $prop2->setValue($this->ttf, false);
        }
    }
}
