<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderCollapseDoubleTest extends TestCase
{
    private function font(): ?PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        return is_readable($path) ? new PdfFont(TtfFile::fromFile($path)) : null;
    }

    private function cell(string $text): Cell
    {
        return new Cell([new Paragraph([new Run($text)])]);
    }

    private function table(bool $collapse): Table
    {
        $border = new Border(BorderStyle::Single, 4, '000000');

        return new Table(
            rows: [
                new Row([$this->cell('A'), $this->cell('B'), $this->cell('C')]),
                new Row([$this->cell('D'), $this->cell('E'), $this->cell('F')]),
                new Row([$this->cell('G'), $this->cell('H'), $this->cell('I')]),
            ],
            style: new TableStyle(
                defaultCellBorder: $border,
                borderCollapse: $collapse,
            ),
        );
    }

    #[Test]
    public function border_collapse_reduces_stroke_op_count(): void
    {
        $docSep = new Document(new Section([$this->table(false)]));
        $docCol = new Document(new Section([$this->table(true)]));

        $bytesSep = $docSep->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $bytesCol = $docCol->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $sCountSep = substr_count($bytesSep, "S\n");
        $sCountCol = substr_count($bytesCol, "S\n");

        // separate: 9 cells × 4 sides = 36 stroke ops.
        // collapse: 9 cells × top/left + 3 last-col right + 3 last-row bottom
        //   = 18 + 3 + 3 = 24.
        self::assertSame(36, $sCountSep);
        self::assertSame(24, $sCountCol);
        self::assertLessThan($sCountSep, $sCountCol);
    }

    #[Test]
    public function double_line_emits_two_parallel_strokes(): void
    {
        $doubleBorder = new Border(BorderStyle::Double, 24, '000000'); // 3pt total
        $doc = new Document(new Section([
            new Table([
                new Row([
                    new Cell(
                        [new Paragraph([new Run('double')])],
                        style: new CellStyle(borders: BorderSet::all($doubleBorder)),
                    ),
                ]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        // Single border: 1 cell × 4 sides = 4 strokes.
        // Double:        1 cell × 4 sides × 2 lines per side = 8 strokes.
        $sCount = substr_count($bytes, "S\n");
        self::assertSame(8, $sCount);

        // Каждый sub-stroke имеет width = totalWidth/3 = 3/3 = 1pt.
        self::assertStringContainsString("\n1 w", $bytes);
    }

    #[Test]
    public function double_line_width_third_of_total(): void
    {
        // total width 6pt → каждая sub-line = 2pt.
        $border = new Border(BorderStyle::Double, 48, '000000');  // 6pt
        $doc = new Document(new Section([
            new Table([
                new Row([new Cell(
                    [new Paragraph([new Run('x')])],
                    style: new CellStyle(borders: BorderSet::all($border)),
                )]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertStringContainsString("\n2 w", $bytes);
    }

    #[Test]
    public function single_line_unchanged_by_phase12(): void
    {
        $border = new Border(BorderStyle::Single, 8, '000000');  // 1pt
        $doc = new Document(new Section([
            new Table([
                new Row([new Cell(
                    [new Paragraph([new Run('x')])],
                    style: new CellStyle(borders: BorderSet::all($border)),
                )]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 1 cell × 4 sides × 1 line = 4 strokes.
        self::assertSame(4, substr_count($bytes, "S\n"));
        self::assertStringContainsString("\n1 w", $bytes);
    }

    #[Test]
    public function collapse_with_one_cell_table_renders_all_4_borders(): void
    {
        // Edge case: 1×1 table — single cell is both last row + last col,
        // so all 4 sides drawn.
        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$this->cell('only')])],
                style: new TableStyle(
                    defaultCellBorder: new Border(BorderStyle::Single, 4, '000000'),
                    borderCollapse: true,
                ),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertSame(4, substr_count($bytes, "S\n"));
    }
}
