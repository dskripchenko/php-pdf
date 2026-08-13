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
        // The original Liberation Sans Regular is ~400 KB. A subset with
        // GPOS/GSUB/cmap stripped and only 4 used glyphs is ≈ 20-30 KB.
        self::assertLessThan(strlen($this->ttf->rawBytes()), strlen($subset));
        // The reduction should be substantial — 50% at the very least.
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
        // .notdef (glyph 0) is mandatory and has to be in the subset always.
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
        // The original Liberation Sans has GPOS and GSUB tables. After
        // subsetting they have to be gone (we strip them for PDF embedding).
        $original = $this->ttf->rawBytes();
        $subset = (new TtfSubsetter)->subset($this->ttf, [43]);

        // The GPOS/GSUB strings in the original (in the table directory).
        self::assertStringContainsString('GPOS', $original);
        self::assertStringContainsString('GSUB', $original);
        // They are absent from the subset.
        self::assertStringNotContainsString('GPOS', $subset);
        self::assertStringNotContainsString('GSUB', $subset);
    }

    #[Test]
    public function cmap_and_name_kept_for_ttf_validity(): void
    {
        // cmap and name stay (they are needed for TTF parser validity; a PDF
        // reader ignores them, but other tools may require them).
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
        // End to end through PdfFont (subset=true by default).
        $font = new \Dskripchenko\PhpPdf\Pdf\PdfFont($this->ttf);
        $doc = \Dskripchenko\PhpPdf\Pdf\Document::new();
        $doc->addPage()->showEmbeddedText('Привет, мир!', 72, 720, $font, 14);

        $tmp = tempnam(sys_get_temp_dir(), 'subset-');
        $doc->toFile($tmp);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Привет, мир!', $text);
            // A sanity check on the subset size — Cyrillic-only-no-Latin is
            // expected to be < 100KB.
            $pdfSize = filesize($tmp);
            self::assertLessThan(100_000, $pdfSize);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function full_embed_mode_keeps_original_size(): void
    {
        // subset=false has to skip the subsetter and embed the whole TTF.
        // Phase 14: compressStreams=false so that the raw size can be measured.
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

    #[Test]
    public function post_table_carries_no_glyph_names(): void
    {
        // A `post` table of format 2.0 carries the names of ALL the glyphs of
        // the source font, and in a subset it was the largest table: 26 KB out
        // of 61 KB for Liberation Sans, while the outlines took 6 KB. PDF has no
        // use for the names — rendering goes by identifier and text extraction
        // by ToUnicode.
        $subset = (new TtfSubsetter)->subset($this->ttf, [43, 72, 79, 82]);
        $post = $this->table($subset, 'post');

        self::assertNotNull($post);
        self::assertSame(32, strlen($post), 'формат 3.0 — это ровно заголовок');
        self::assertSame(0x00030000, unpack('N', substr($post, 0, 4))[1]);
    }

    #[Test]
    public function post_header_fields_survive(): void
    {
        // The header is the same across the versions, and our own parser reads
        // the slant and the monospacing from it — those have to survive.
        $original = $this->table($this->ttf->rawBytes(), 'post');
        $subset = $this->table((new TtfSubsetter)->subset($this->ttf, [43, 72]), 'post');

        self::assertSame(substr($original, 4, 28), substr($subset, 4, 28));
    }

    /** The body of a table, by tag, out of the font binary. */
    private function table(string $font, string $tag): ?string
    {
        $count = unpack('n', substr($font, 4, 2))[1];

        for ($i = 0; $i < $count; $i++) {
            $record = substr($font, 12 + $i * 16, 16);
            if (substr($record, 0, 4) !== $tag) {
                continue;
            }
            $offset = unpack('N', substr($record, 8, 4))[1];
            $length = unpack('N', substr($record, 12, 4))[1];

            return substr($font, $offset, $length);
        }

        return null;
    }
}
