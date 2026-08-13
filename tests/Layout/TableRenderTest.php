<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableRenderTest extends TestCase
{
    private function font(): ?PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        return is_readable($path) ? new PdfFont(TtfFile::fromFile($path)) : null;
    }

    private function p(string $text): Paragraph
    {
        return new Paragraph([new Run($text)]);
    }

    private function cell(string $text, ?CellStyle $style = null): Cell
    {
        return new Cell([$this->p($text)], style: $style ?? new CellStyle);
    }

    #[Test]
    public function single_cell_table_renders(): void
    {
        $doc = new Document(new Section([
            new Table([
                new Row([$this->cell('Hello')]),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertStringStartsWith('%PDF', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Hello', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function three_by_three_table_renders_all_cells(): void
    {
        $rows = [];
        for ($r = 0; $r < 3; $r++) {
            $cells = [];
            for ($c = 0; $c < 3; $c++) {
                $cells[] = $this->cell("r{$r}c{$c}");
            }
            $rows[] = new Row($cells);
        }
        $doc = new Document(new Section([new Table($rows)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            foreach (['r0c0', 'r1c1', 'r2c2'] as $needle) {
                self::assertStringContainsString($needle, $text);
            }
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function cell_background_emits_fill_operator(): void
    {
        $bg = new CellStyle(backgroundColor: 'ccffcc');
        $doc = new Document(new Section([
            new Table([new Row([new Cell([$this->p('bg')], style: $bg)])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 'f\n' — fill operator. RG-then-rg-then-re-then-f.
        self::assertStringContainsString(" rg\n", $bytes);
        self::assertStringContainsString(" re\n", $bytes);
        self::assertStringContainsString("f\n", $bytes);
    }

    #[Test]
    public function cell_borders_emit_stroke_operator(): void
    {
        $borderedStyle = new CellStyle(
            borders: BorderSet::all(new Border(BorderStyle::Single, sizeEighthsOfPoint: 8, color: '000000')),
        );
        $doc = new Document(new Section([
            new Table([new Row([new Cell([$this->p('B')], style: $borderedStyle)])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // strokeRect → ' RG' + 'S\n'.
        self::assertStringContainsString(' RG', $bytes);
        self::assertStringContainsString("S\n", $bytes);
    }

    #[Test]
    public function table_with_default_cell_border_applies_to_all_cells(): void
    {
        $ts = new TableStyle(defaultCellBorder: new Border(BorderStyle::Single));
        $doc = new Document(new Section([
            new Table(
                rows: [
                    new Row([$this->cell('A'), $this->cell('B')]),
                    new Row([$this->cell('C'), $this->cell('D')]),
                ],
                style: $ts,
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        // 4 cells × 4 borders = 16 stroke ops minimum.
        $sCount = substr_count($bytes, "S\n");
        self::assertGreaterThanOrEqual(16, $sCount);
    }

    #[Test]
    public function table_alignment_center_shifts_table_x(): void
    {
        $ts = new TableStyle(widthPt: 200, alignment: Alignment::Center);
        $doc = new Document(new Section([
            new Table(rows: [new Row([$this->cell('Centered')])], style: $ts),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Centered', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function explicit_column_widths_respected(): void
    {
        $t = new Table(
            rows: [new Row([$this->cell('A'), $this->cell('B'), $this->cell('C')])],
            columnWidthsPt: [60, 120, 180],
        );
        $bytes = (new Document(new Section([$t])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('A', $text);
            self::assertStringContainsString('B', $text);
            self::assertStringContainsString('C', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function many_rows_overflow_to_next_page(): void
    {
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = new Row([$this->cell("Row $i col 1"), $this->cell("Row $i col 2")]);
        }
        $doc = new Document(new Section([new Table($rows)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $pageCount = substr_count($bytes, '/Type /Page ');
        self::assertGreaterThan(1, $pageCount, 'Long table should overflow');
    }

    #[Test]
    public function vertical_alignment_center_positions_content(): void
    {
        $vcStyle = new CellStyle(verticalAlign: VerticalAlignment::Center);
        $doc = new Document(new Section([
            new Table(
                rows: [
                    new Row(
                        cells: [new Cell([$this->p('Centered V')], style: $vcStyle)],
                        heightPt: 100,
                    ),
                ],
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Centered V', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function explicit_row_height_used_directly(): void
    {
        $doc = new Document(new Section([
            new Table([new Row(cells: [$this->cell('X')], heightPt: 50)]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertStringStartsWith('%PDF', $bytes);
    }

    #[Test]
    public function column_span_widens_cell(): void
    {
        $t = new Table(
            rows: [
                new Row([$this->cell('A'), $this->cell('B'), $this->cell('C')]),
                new Row([new Cell([$this->p('span all')], columnSpan: 3)]),
            ],
            columnWidthsPt: [60, 60, 60],
        );
        $bytes = (new Document(new Section([$t])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('span all', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function header_row_repeats_on_second_page(): void
    {
        $headerStyle = new CellStyle(backgroundColor: '4477aa');
        $rows = [new Row(
            cells: [new Cell([$this->p('HEADER')], style: $headerStyle)],
            isHeader: true,
        )];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = new Row([$this->cell("Row $i")]);
        }
        $bytes = (new Document(new Section([new Table($rows)])))
            ->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            // pdftotext per-page
            $page1 = (string) shell_exec('pdftotext -f 1 -l 1 '.escapeshellarg($tmp).' - 2>&1');
            $page2 = (string) shell_exec('pdftotext -f 2 -l 2 '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('HEADER', $page1);
            self::assertStringContainsString('HEADER', $page2);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function multi_paragraph_cell_works(): void
    {
        $cell = new Cell([
            $this->p('First paragraph'),
            $this->p('Second paragraph'),
        ]);
        $doc = new Document(new Section([new Table([new Row([$cell])])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $tmp = tempnam(sys_get_temp_dir(), 'tbl-');
        file_put_contents($tmp, $bytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('First paragraph', $text);
            self::assertStringContainsString('Second paragraph', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function forced_page_break_inside_a_cell_is_ignored(): void
    {
        // Word cannot start a page in the middle of a cell, so such a break
        // stays in the file as a leftover of editing and renders as nothing.
        // Acting on it tore a real application form apart: the row carrying
        // the break went to a page of its own and everything after it began
        // on the following page.
        $doc = new Document(new Section([
            new Table([
                new Row([new Cell([new Paragraph([new Run('first row')])])]),
                new Row([new Cell([
                    new PageBreak,
                    new Paragraph([new Run('row with a break inside')]),
                ])]),
                new Row([new Cell([new Paragraph([new Run('third row')])])]),
            ]),
        ]));

        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertSame(1, substr_count($bytes, '/Type /Page '), 'таблица обязана остаться на одной странице');
    }

    #[Test]
    public function forced_page_break_outside_a_table_still_works(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('before')]),
            new PageBreak,
            new Paragraph([new Run('after')]),
        ]));

        $bytes = $doc->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertSame(2, substr_count($bytes, '/Type /Page '));
    }

    #[Test]
    public function keep_with_next_moves_a_heading_to_its_content(): void
    {
        // A section heading must not be left as the last line of a page: Word
        // carries it over to the next one together with the content. Without
        // this the job description started diverging on the very first page.
        $withoutKeep = $this->headingBeforeTable(keepWithNext: false);
        $withKeep = $this->headingBeforeTable(keepWithNext: true);

        // Without the flag the heading is squeezed to the bottom of the page...
        self::assertLessThan(200.0, $withoutKeep);
        // ...and with it the heading moves to the top of the next one, to its table.
        self::assertGreaterThan(700.0, $withKeep);
    }

    /** The vertical position of a heading sitting right at the bottom edge. */
    private function headingBeforeTable(bool $keepWithNext): float
    {
        $blocks = [];
        for ($i = 0; $i < 53; $i++) {
            $blocks[] = new Paragraph([new Run('Filler line number '.$i.'.')]);
        }
        $blocks[] = new Paragraph(
            [new Run('Qualification requirements')],
            new ParagraphStyle(keepWithNext: $keepWithNext),
        );
        $blocks[] = new Table([
            new Row([new Cell([new Paragraph([new Run('WORK EXPERIENCE')])])]),
        ]);

        $bytes = (new Document(new Section($blocks)))->toBytes(new Engine(compressStreams: false));

        self::assertSame(
            1,
            preg_match('/([\d.]+) ([\d.]+) Td\s*\n\(Qualification requirements\)/', $bytes, $m),
            'заголовок не нашёлся в потоке',
        );

        return (float) $m[2];
    }

}
