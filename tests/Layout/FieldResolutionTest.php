<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldResolutionTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function pdftotext(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fld-');
        file_put_contents($tmp, $bytes);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function page_field_resolves_to_current_page_number(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Page: '), Field::page()]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('Page: 1', $text);
    }

    #[Test]
    public function numpages_field_resolves_to_total_pages(): void
    {
        // Single-page doc → NUMPAGES = 1.
        $doc = new Document(new Section([
            new Paragraph([new Run('Total: '), Field::totalPages()]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('Total: 1', $text);
    }

    #[Test]
    public function date_field_resolves_to_current_date(): void
    {
        $doc = new Document(new Section([
            new Paragraph([Field::date('yyyy-MM-dd')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString(date('Y-m-d'), $text);
    }

    #[Test]
    public function mergefield_renders_field_name(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Hello, '), Field::mergeField('CustomerName')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        self::assertStringContainsString('CustomerName', $text);
    }

    #[Test]
    public function numpages_reflects_multi_page_document(): void
    {
        // Stuff in 80 paragraphs so that the document spans several pages.
        $blocks = [
            new Paragraph([new Run('Total pages: '), Field::totalPages()]),
        ];
        for ($i = 0; $i < 100; $i++) {
            $blocks[] = new Paragraph([new Run("Paragraph $i for filler text content")]);
        }
        $blocks[] = new Paragraph([new Run('END total: '), Field::totalPages()]);

        $doc = new Document(new Section($blocks));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);
        // NUMPAGES has to match the actual number of pages.
        preg_match('/^\/Type \/Page /m', $bytes);
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount);
        self::assertStringContainsString("Total pages: $pageCount", $text);
        self::assertStringContainsString("END total: $pageCount", $text);
    }

    #[Test]
    public function numpages_met_only_on_the_last_page_still_gets_the_real_count(): void
    {
        // A document without NUMPAGES is finished after one pass; the second
        // pass must still run when the only total sits deep in the last page.
        $blocks = [];
        for ($i = 0; $i < 100; $i++) {
            $blocks[] = new Paragraph([new Run("Paragraph $i for filler text content")]);
        }
        $blocks[] = new Table([new Row([new Cell([new Paragraph([new Run('Cell total: '), Field::totalPages()])])])]);

        $bytes = (new Document(new Section($blocks)))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount);
        self::assertStringContainsString("Cell total: $pageCount", $this->pdftotext($bytes));
    }

    /** @return list<Paragraph> */
    private function filler(int $count): array
    {
        return array_map(
            fn (int $i) => new Paragraph([new Run("Paragraph $i with words enough to wrap now and then across the width of the page.")]),
            range(1, $count),
        );
    }

    #[Test]
    public function footer_total_is_right_on_every_page(): void
    {
        $footer = new Paragraph([new Run('Page '), Field::page(), new Run(' of '), Field::totalPages()]);
        $bytes = (new Document(new Section($this->filler(200), footerBlocks: [$footer])))
            ->toBytes(new Engine(compressStreams: false));
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(3, $pageCount);
        $text = $this->pdftotext($bytes);
        for ($page = 1; $page <= $pageCount; $page++) {
            self::assertStringContainsString("Page $page of $pageCount", $text);
        }
        self::assertStringNotContainsString(' of 1'."\n", $text);
    }

    #[Test]
    public function footer_total_lays_out_as_if_the_number_were_typed(): void
    {
        // A large footer that takes one more line with the stand-in 99 than
        // with the real total: the room it leaves the body must be the room
        // the real total needs.
        $big = new RunStyle(sizePt: 30);
        $footer = fn (Run|Field $total) => new Paragraph([new Run(str_repeat('x', 30).' ', $big), $total]);
        $render = fn (Run|Field $total) => (new Document(new Section($this->filler(300), footerBlocks: [$footer($total)])))
            ->toBytes(new Engine(compressStreams: false));

        $withField = $render(Field::totalPages($big));
        $pageCount = substr_count($withField, '/Type /Page ');
        $typed = $render(new Run((string) $pageCount, $big));

        self::assertSame($this->pdftotext($typed), $this->pdftotext($withField));
    }

    #[Test]
    public function footer_link_beside_the_total_is_kept_once_per_page(): void
    {
        $footer = new Paragraph([Hyperlink::external('https://example.com', [new Run('Site')]), new Run(' · '), Field::totalPages()]);
        $bytes = (new Document(new Section($this->filler(200), footerBlocks: [$footer])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertSame(substr_count($bytes, '/Type /Page '), substr_count($bytes, '/Subtype /Link'));
    }
}
