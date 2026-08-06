<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A footnote inside a table cell must reach the foot of the page.
 *
 * Form-like documents keep their whole body in a table, so a note that only
 * works outside tables does not work at all for them. A cell is laid out
 * more than once — measured, and re-laid when a row is split across pages —
 * and an earlier attempt collected notes as they were met: the list grew on
 * every pass, numbering drifted upward and a seven-page form ate half a
 * gigabyte. Numbering is now tied to the footnote element, so visiting a
 * cell again changes nothing.
 */
final class FootnoteInTableTest extends TestCase
{
    #[Test]
    public function aFootnoteInsideACellReachesThePageFoot(): void
    {
        $text = $this->render([
            new Table([
                new Row([
                    new Cell([new Paragraph([new Run('Term'), new Footnote('Note from a cell')])]),
                    new Cell([new Paragraph([new Run('Value')])]),
                ]),
            ]),
        ]);

        self::assertStringContainsString('Note from a cell', $text);
    }

    #[Test]
    public function numberingDoesNotDriftWhenACellIsVisitedRepeatedly(): void
    {
        // Двадцать строк заставляют таблицу разбиться между страницами, то
        // есть ячейки будут разложены не по одному разу.
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = new Row([
                new Cell([new Paragraph([new Run('row '.$i)])]),
                new Cell([new Paragraph([new Run('x')])]),
            ]);
        }
        $rows[] = new Row([
            new Cell([new Paragraph([new Run('Total'), new Footnote('The only note')])]),
            new Cell([new Paragraph([new Run('y')])]),
        ]);

        $text = $this->render([new Table($rows)]);

        // Ровно одна сноска — значит ни номер не уполз, ни текст не
        // продублировался при повторных проходах по ячейке.
        self::assertSame(1, substr_count($text, 'The only note'));
        self::assertStringNotContainsString('2. The only note', $text);
    }

    #[Test]
    public function notesFromTextAndFromCellsShareOneNumbering(): void
    {
        $text = $this->render([
            new Paragraph([new Run('Paragraph'), new Footnote('First')]),
            new Table([
                new Row([new Cell([new Paragraph([new Run('Cell'), new Footnote('Second')])])]),
            ]),
        ]);

        self::assertStringContainsString('1. First', $text);
        self::assertStringContainsString('2. Second', $text);
    }

    /** @param  list<mixed>  $body */
    private function render(array $body): string
    {
        $pdf = (new Document(new Section(body: $body, footnoteBottomReservedPt: 44.0)))->toBytes();

        $path = tempnam(sys_get_temp_dir(), 'fn-').'.pdf';
        file_put_contents($path, $pdf);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }
    }
}
