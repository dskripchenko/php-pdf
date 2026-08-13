<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tall table row is split between pages.
 *
 * Without that, a row taller than the space left was carried over to the next
 * page whole — leaving a half-empty sheet behind. That is how the insurance
 * policy we took apart ended up with a page of 40 words instead of seven
 * hundred: a long block of terms sat inside a table. Word splits such rows by
 * default.
 */
final class RowSplitTest extends TestCase
{
    /** Paragraphs that are certain not to fit on a single page. */
    private function tallCell(int $paragraphs): Cell
    {
        $blocks = [];
        for ($i = 0; $i < $paragraphs; $i++) {
            $blocks[] = new Paragraph([new Run('Строка условий номер '.$i.', достаточно длинная чтобы занять место.')]);
        }

        return new Cell($blocks);
    }

    private function pagesOf(Table $table): int
    {
        $pdf = (new Document(new Section([$table])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'split-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            return (int) trim((string) shell_exec('pdfinfo '.escapeshellarg($path).' 2>/dev/null | awk \'/^Pages/{print $2}\''));
        } finally {
            @unlink($path);
        }
    }

    private function textPerPage(Table $table): array
    {
        $pdf = (new Document(new Section([$table])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'split-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $pages = [];
            for ($p = 1; $p <= 4; $p++) {
                $text = trim((string) shell_exec('pdftotext -f '.$p.' -l '.$p.' '.escapeshellarg($path).' - 2>/dev/null'));
                if ($text === '') {
                    break;
                }
                $pages[] = str_word_count($text, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя0123456789');
            }

            return $pages;
        } finally {
            @unlink($path);
        }
    }

    /** The raw text page by page — for when what printed where matters.
     *
     * @return list<string>
     */
    private function pageTexts(Section $section): array
    {
        $pdf = (new Document($section))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'split-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $pages = [];
            for ($p = 1; $p <= 6; $p++) {
                $text = trim((string) shell_exec('pdftotext -f '.$p.' -l '.$p.' '.escapeshellarg($path).' - 2>/dev/null'));
                if ($text === '') {
                    break;
                }
                $pages[] = $text;
            }

            return $pages;
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function tall_row_is_split_across_pages(): void
    {
        $table = new Table([new Row([$this->tallCell(80)])]);

        // One row of 80 paragraphs is certain to be more than a page.
        self::assertGreaterThan(1, $this->pagesOf($table));
    }

    #[Test]
    public function the_first_page_is_filled_before_the_break(): void
    {
        // The row is preceded by a paragraph: without splitting it would go to
        // the second page whole, leaving the first one almost empty.
        $table = new Table([new Row([$this->tallCell(60)])]);
        $counts = $this->textPerPage($table);

        self::assertGreaterThan(1, count($counts));
        // The first page has to carry a word count comparable to the second,
        // not a dozen: that is what tells a split from a whole carry-over.
        self::assertGreaterThan($counts[1] / 2, $counts[0]);
    }

    #[Test]
    public function a_row_nobody_continues_moves_whole(): void
    {
        // A "directive on the left, its result on the right" row: the text fits
        // into what is left of the page, the picture does not. Splitting such a
        // row means putting on different pages what only reads together: the
        // page would end with a row whose right cell is empty, and the next one
        // would start with a row whose left cell is. Carry it over whole.
        $filler = [];
        for ($i = 0; $i < 44; $i++) {
            $filler[] = new Paragraph([new Run('Заполнение страницы, абзац номер '.$i.'.')]);
        }

        $fixture = __DIR__.'/../fixtures/sample.jpg';
        if (! is_readable($fixture)) {
            self::markTestSkipped('Sample JPEG fixture missing.');
        }
        $tall = new Image(PdfImage::fromPath($fixture), widthPt: 160, heightPt: 160);

        $table = new Table([new Row([
            new Cell([new Paragraph([new Run('@qr($verify_url, 96)')])]),
            new Cell([$tall]),
        ])]);

        $pages = $this->pageTexts(new Section([...$filler, $table]));

        // The directive must not stay on a page that does not hold its result.
        self::assertCount(2, $pages, 'ожидались ровно две страницы');
        self::assertStringNotContainsString('@qr', $pages[0], 'строку разорвало: текст остался без картинки');
        self::assertStringContainsString('@qr', $pages[1]);
    }

    #[Test]
    public function a_header_row_does_not_end_a_page_alone(): void
    {
        // A header explains the columns: left as the last line of a page it
        // explains what that page no longer holds — and it repeats on the next
        // one anyway. It moves together with the first row of data.
        //
        // The markers are Latin: the check goes against the output of
        // pdftotext, and Cyrillic here would only add encoding noise to a test
        // about layout.
        $filler = [];
        for ($i = 0; $i < 47; $i++) {
            $filler[] = new Paragraph([new Run('Filler paragraph number '.$i.'.')]);
        }

        $fixture = __DIR__.'/../fixtures/sample.jpg';
        if (! is_readable($fixture)) {
            self::markTestSkipped('Sample JPEG fixture missing.');
        }

        $table = new Table([
            new Row([new Cell([new Paragraph([new Run('COLUMN-HEADER')])])], isHeader: true),
            new Row([new Cell([new Image(PdfImage::fromPath($fixture), widthPt: 160, heightPt: 160)])]),
        ]);

        $pages = $this->pageTexts(new Section([...$filler, $table]));

        self::assertGreaterThan(1, count($pages), 'ожидалось больше одной страницы');
        self::assertStringNotContainsString('COLUMN-HEADER', $pages[0], 'шапка осталась одна в конце страницы');
        self::assertStringContainsString('COLUMN-HEADER', $pages[1]);
    }

    #[Test]
    public function a_row_that_fits_is_not_split(): void
    {
        $table = new Table([new Row([$this->tallCell(3)])]);

        self::assertSame(1, $this->pagesOf($table));
    }
}
