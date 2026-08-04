<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Соседние руны без пробела не разъезжаются.
 *
 * Слова внутри руна режутся на токены, а строка собирается обратно через
 * пробел — на стыке двух рунов это давало пробел, которого в тексте нет.
 * Word режет строку по любой смене начертания, поэтому «(» и «залогодатель»
 * приезжают отдельными рунами, и импортированный документ печатался как
 * «СТРАХОВАТЕЛЬ ( залогодатель )». Найдено сравнением с эталоном в printable.
 */
final class AdjacentRunGlueTest extends TestCase
{
    /**
     * Правый край последнего слова абзаца.
     *
     * Сравниваем геометрию, а не извлечённый текст: `pdftotext` сам решает,
     * ставить ли пробел между глифами по величине зазора, и на встроенном
     * шрифте эта эвристика срабатывает там, где пробела нет. Ширина строки
     * такой свободы не имеет.
     */
    private function rightEdgeOf(Paragraph $paragraph): float
    {
        $pdf = (new Document(new Section([$paragraph])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'glue-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $bbox = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
            preg_match_all('/xMax="([0-9.]+)"/', $bbox, $m);

            return $m[1] === [] ? 0.0 : max(array_map('floatval', $m[1]));
        } finally {
            @unlink($path);
        }
    }

    private function textOf(Paragraph $paragraph): string
    {
        $pdf = (new Document(new Section([$paragraph])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'glue-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            // Позиционирование слов pdftotext раскладывает по строкам как ему
            // удобно — сравниваем поток без переносов.
            $text = (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>/dev/null');

            return trim((string) preg_replace('/\n+/', ' ', $text));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function adjacent_runs_without_whitespace_are_not_separated(): void
    {
        // Латиница нарочно: встроенный шрифт документа без провайдера —
        // WinAnsi, и кириллица в извлечении превращается в мусор. Логика
        // склейки от языка не зависит.
        $paragraph = new Paragraph([
            // Начертание у всех рунов одно: сравниваем влияние ИМЕННО
            // разбиения на руны, а не разницу шрифтов.
            new Run('POLICYHOLDER '),
            new Run('('),
            new Run('pledgor'),
            new Run(')'),
        ]);

        // Строка из четырёх рунов обязана занять ровно столько же, сколько тот
        // же текст одним руном: лишний пробел на стыке сразу удлиняет её.
        $split = $this->rightEdgeOf($paragraph);
        $whole = $this->rightEdgeOf(new Paragraph([new Run('POLICYHOLDER (pledgor)')]));

        self::assertEqualsWithDelta($whole, $split, 0.5);
    }

    #[Test]
    public function whitespace_at_a_run_boundary_still_separates(): void
    {
        // Пробел в конце руна — единственный разделитель, который есть в
        // тексте: склеивать через него нельзя, иначе слова слипнутся.
        $paragraph = new Paragraph([
            new Run('first '),
            new Run('second'),
        ]);

        $split = $this->rightEdgeOf($paragraph);
        $whole = $this->rightEdgeOf(new Paragraph([new Run('first second')]));

        self::assertEqualsWithDelta($whole, $split, 0.5);
    }
}
