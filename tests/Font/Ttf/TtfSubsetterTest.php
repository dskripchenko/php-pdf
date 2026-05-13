<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Font\Ttf\TtfSubsetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TtfSubsetterTest extends TestCase
{
    private TtfFile $ttf;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        $this->ttf = TtfFile::fromFile($path);
    }

    #[Test]
    public function subset_is_smaller_than_original(): void
    {
        $subset = (new TtfSubsetter)->subset($this->ttf, [43, 72, 79, 82]);
        // Original Liberation Sans Regular ~400 KB. Subset с GPOS/GSUB/cmap
        // stripped + только 4 used glyphs ≈ 20-30 KB.
        self::assertLessThan(strlen($this->ttf->rawBytes()), strlen($subset));
        // Reduction should быть substantial — minimum 50%.
        $reduction = 1 - strlen($subset) / strlen($this->ttf->rawBytes());
        self::assertGreaterThan(0.5, $reduction);
    }

    #[Test]
    public function subset_re_parses_as_valid_ttf(): void
    {
        $subset = (new TtfSubsetter)->subset($this->ttf, [43, 72, 79, 82]);
        $reparsed = new TtfFile($subset);
        self::assertSame('LiberationSans', $reparsed->postScriptName());
        self::assertSame(2048, $reparsed->unitsPerEm());
        self::assertSame(2620, $reparsed->numGlyphs());
    }

    #[Test]
    public function used_glyph_advance_width_preserved(): void
    {
        $subset = (new TtfSubsetter)->subset($this->ttf, [43]); // glyph H
        $reparsed = new TtfFile($subset);
        self::assertSame(
            $this->ttf->advanceWidth(43),
            $reparsed->advanceWidth(43),
            'Subset должен сохранять advance widths',
        );
    }

    #[Test]
    public function empty_used_glyphs_keeps_at_least_notdef(): void
    {
        // .notdef (glyph 0) — обязательный glyph, всегда должен быть в subset'е.
        $subset = (new TtfSubsetter)->subset($this->ttf, []);
        self::assertGreaterThan(1000, strlen($subset)); // header + tables + minimal glyf
        $reparsed = new TtfFile($subset);
        self::assertSame(2620, $reparsed->numGlyphs());
    }

    #[Test]
    public function list_form_input_works_same_as_map_form(): void
    {
        $listForm = (new TtfSubsetter)->subset($this->ttf, [43, 72, 79]);
        $mapForm = (new TtfSubsetter)->subset($this->ttf, [43 => true, 72 => true, 79 => true]);
        // Same input → identical output.
        self::assertSame($listForm, $mapForm);
    }

    #[Test]
    public function gpos_and_gsub_tables_stripped(): void
    {
        // Original Liberation Sans имеет GPOS+GSUB tables. После subset
        // они должны исчезнуть (мы их выпиливаем для PDF embedding).
        $original = $this->ttf->rawBytes();
        $subset = (new TtfSubsetter)->subset($this->ttf, [43]);

        // GPOS/GSUB strings в original (в table directory).
        self::assertStringContainsString('GPOS', $original);
        self::assertStringContainsString('GSUB', $original);
        // В subset они отсутствуют.
        self::assertStringNotContainsString('GPOS', $subset);
        self::assertStringNotContainsString('GSUB', $subset);
    }

    #[Test]
    public function cmap_and_name_kept_for_ttf_validity(): void
    {
        // cmap и name остаются (нужны для TTF parser-validity; PDF reader
        // их игнорирует, но другие tools могут требовать).
        $subset = (new TtfSubsetter)->subset($this->ttf, [43]);
        $header = substr($subset, 0, 300);
        self::assertStringContainsString('cmap', $header);
        self::assertStringContainsString('name', $header);
    }

    #[Test]
    public function pdftotext_still_extracts_subsetted_text(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }
        // End-to-end через PdfFont (default subset=true).
        $font = new \Dskripchenko\PhpPdf\Pdf\PdfFont($this->ttf);
        $doc = \Dskripchenko\PhpPdf\Pdf\Document::new();
        $doc->addPage()->showEmbeddedText('Привет, мир!', 72, 720, $font, 14);

        $tmp = tempnam(sys_get_temp_dir(), 'subset-');
        $doc->toFile($tmp);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Привет, мир!', $text);
            // Subset size sanity — для Cyrillic-only-no-Latin ожидаем < 100KB.
            $pdfSize = filesize($tmp);
            self::assertLessThan(100_000, $pdfSize);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function full_embed_mode_keeps_original_size(): void
    {
        // subset=false должен пропустить subsetter и embed весь TTF.
        // Phase 14: используем compressStreams=false чтобы measure raw size.
        $font = new \Dskripchenko\PhpPdf\Pdf\PdfFont($this->ttf, subset: false);
        $doc = \Dskripchenko\PhpPdf\Pdf\Document::new(compressStreams: false);
        $doc->addPage()->showEmbeddedText('Hi', 72, 720, $font, 14);

        $bytes = $doc->toBytes();
        // Full embed ≈ 412 KB (TTF as-is + small overhead).
        self::assertGreaterThan(380_000, strlen($bytes));
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
