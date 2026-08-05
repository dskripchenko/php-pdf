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
use Dskripchenko\PhpPdf\Style\CellStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Слово, которое не помещается на строке целиком, делится по символам.
 *
 * Иначе оно печатается как есть и вылезает за колонку: длинный URL в узкой
 * ячейке заезжал на соседнюю. Так поступает и Word, и браузер — разорвать
 * длинную строку лучше, чем потерять границу колонки.
 */
final class OverlongWordTest extends TestCase
{
    private const URL = 'https://printable.dev-cloud.space/ru/docs/security';

    /** @return list<array{text: string, xMin: float, xMax: float}> */
    private function words(Document $doc): array
    {
        $path = tempnam(sys_get_temp_dir(), 'long-').'.pdf';
        file_put_contents($path, $doc->toBytes(new Engine(compressStreams: false)));

        try {
            $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        preg_match_all('/<word xMin="([\d.]+)"[^>]*xMax="([\d.]+)"[^>]*>(.*?)<\/word>/', $xml, $m, PREG_SET_ORDER);

        return array_map(
            static fn (array $w): array => ['text' => $w[3], 'xMin' => (float) $w[1], 'xMax' => (float) $w[2]],
            $m,
        );
    }

    #[Test]
    public function a_long_url_stays_inside_its_cell(): void
    {
        $padding = new CellStyle(paddingLeftPt: 9, paddingRightPt: 9);
        $table = new Table(
            [new Row([
                new Cell([new Paragraph([new Run(self::URL)])], $padding),
                new Cell([new Paragraph([new Run('RIGHT')])], $padding),   // латиницей: сверяемся с выводом pdftotext
            ])],
            columnWidthsPt: [252.0, 252.0],
        );

        $words = $this->words(new Document(new Section([$table])));
        // Граница колонки: левое поле страницы плюс её ширина.
        $columnEdge = 56.7 + 252.0;

        $pieces = array_values(array_filter(
            $words,
            static fn (array $w): bool => str_contains($w['text'], 'printable') || str_contains($w['text'], 'security'),
        ));

        self::assertGreaterThan(1, count($pieces), 'длинный URL не разделён — он не помещается в колонку целиком');
        foreach ($pieces as $piece) {
            self::assertLessThan($columnEdge, $piece['xMax'], "«{$piece['text']}» вылезло за колонку");
        }
    }

    #[Test]
    public function the_url_survives_the_break(): void
    {
        // Делим, но не теряем: собранный обратно текст равен исходному.
        $table = new Table(
            [new Row([new Cell([new Paragraph([new Run(self::URL)])])])],
            columnWidthsPt: [160.0],
        );

        $joined = implode('', array_column($this->words(new Document(new Section([$table]))), 'text'));

        self::assertSame(self::URL, $joined);
    }
}
