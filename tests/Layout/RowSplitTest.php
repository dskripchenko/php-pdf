<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
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
    public function a_row_that_fits_is_not_split(): void
    {
        $table = new Table([new Row([$this->tallCell(3)])]);

        self::assertSame(1, $this->pagesOf($table));
    }
}
