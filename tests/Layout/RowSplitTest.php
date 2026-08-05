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
 * Высокая строка таблицы делится между страницами.
 *
 * Без этого одна строка выше оставшегося места уносилась на следующую
 * страницу целиком — и оставляла за собой полупустой лист. В разобранном
 * страховом полисе так получалась страница с 40 словами вместо семисот:
 * длинный блок условий лежал внутри таблицы. Word делит такие строки по
 * умолчанию.
 */
final class RowSplitTest extends TestCase
{
    /** Абзацы, которые заведомо не поместятся на одну страницу. */
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

    /** Сырой текст постранично — когда важно, что и где напечаталось.
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

        // Одна строка на 80 абзацев — это заведомо больше страницы.
        self::assertGreaterThan(1, $this->pagesOf($table));
    }

    #[Test]
    public function the_first_page_is_filled_before_the_break(): void
    {
        // Строка предваряется абзацем: без деления она ушла бы на вторую
        // страницу целиком, оставив первую почти пустой.
        $table = new Table([new Row([$this->tallCell(60)])]);
        $counts = $this->textPerPage($table);

        self::assertGreaterThan(1, count($counts));
        // Первая страница должна нести соизмеримое со второй количество слов,
        // а не десяток: именно это отличает деление от переноса целиком.
        self::assertGreaterThan($counts[1] / 2, $counts[0]);
    }

    #[Test]
    public function a_row_nobody_continues_moves_whole(): void
    {
        // Строка «слева директива, справа её результат»: текст в остаток
        // страницы помещается, картинка — нет. Разделить такую строку значит
        // развести по разным страницам то, что читается только вместе:
        // страница закончится строкой с пустой правой ячейкой, а следующая
        // начнётся строкой с пустой левой. Переносим целиком.
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

        // Директива не должна остаться на странице, где нет её результата.
        self::assertCount(2, $pages, 'ожидались ровно две страницы');
        self::assertStringNotContainsString('@qr', $pages[0], 'строку разорвало: текст остался без картинки');
        self::assertStringContainsString('@qr', $pages[1]);
    }

    #[Test]
    public function a_header_row_does_not_end_a_page_alone(): void
    {
        // Шапка объясняет колонки: оставшись последней строкой страницы, она
        // объясняет то, чего на этой странице уже нет, — а на следующей
        // повторяется заново. Уходит вместе с первой строкой данных.
        //
        // Маркеры латиницей: сверяемся с выводом pdftotext, и кириллица здесь
        // только добавила бы кодировочного шума к проверке раскладки.
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
