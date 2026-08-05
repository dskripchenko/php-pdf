<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Векторный блок занимает столько места, сколько объявил.
 *
 * Пока измерение его не знало, строка таблицы со знаком мерилась нулевой
 * высотой: рисунок вылезал за строку и наезжал на её границу.
 */
final class SvgHeightTest extends TestCase
{
    private const MARK = '<svg width="24" height="24" viewBox="0 0 24 24">'
        .'<rect x="2" y="2" width="20" height="20" fill="#14b8a6"/></svg>';

    private function pdfOf(Section $section): string
    {
        return (new Document($section))->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function a_row_is_as_tall_as_the_mark_inside_it(): void
    {
        // Сравниваем два знака разной высоты: так минимальная высота строки
        // из сравнения уходит, и остаётся ровно вклад рисунка.
        $row = static fn (float $h): Table => new Table([new Row([
            new Cell([new SvgElement(self::MARK, widthPt: 40, heightPt: $h)]),
        ])]);

        // Положение абзаца под таблицей и есть высота строки: измерение
        // проверяем тем же способом, каким его видит читатель.
        $textY = function (Table $t): float {
            $path = tempnam(sys_get_temp_dir(), 'svgh-').'.pdf';
            file_put_contents($path, $this->pdfOf(new Section([$t, new Paragraph([new Run('после')])])));
            try {
                $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
            } finally {
                @unlink($path);
            }
            preg_match('/<word[^>]*yMin="([\d.]+)"/', $xml, $m);

            return (float) ($m[1] ?? 0);
        };

        // Координаты извлечения растут вниз: знак вдвое выше опускает абзац
        // ровно на разницу высот.
        self::assertEqualsWithDelta(60.0, $textY($row(100)) - $textY($row(40)), 1.0);
    }
}
