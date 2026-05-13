<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Element;

use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function cell(string $text, int $colSpan = 1, int $rowSpan = 1): Cell
    {
        return new Cell([new Paragraph([new Run($text)])], columnSpan: $colSpan, rowSpan: $rowSpan);
    }

    #[Test]
    public function empty_table_is_empty(): void
    {
        $t = new Table;
        self::assertTrue($t->isEmpty());
        self::assertSame(0, $t->columnCount());
    }

    #[Test]
    public function column_count_uses_max_grid_span_sum(): void
    {
        $t = new Table([
            new Row([$this->cell('A'), $this->cell('B'), $this->cell('C')]),  // 3 cols
            new Row([$this->cell('span2', colSpan: 2), $this->cell('1')]),    // 3
            new Row([$this->cell('all', colSpan: 3)]),                          // 3
        ]);
        self::assertSame(3, $t->columnCount());
    }

    #[Test]
    public function cell_default_span_is_one(): void
    {
        $c = new Cell;
        self::assertSame(1, $c->columnSpan);
        self::assertSame(1, $c->rowSpan);
    }

    #[Test]
    public function cell_rejects_invalid_spans(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cell(columnSpan: 0);
    }

    #[Test]
    public function cell_rejects_invalid_row_span(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cell(rowSpan: 0);
    }

    #[Test]
    public function row_default_not_header(): void
    {
        $r = new Row([$this->cell('A')]);
        self::assertFalse($r->isHeader);
        self::assertNull($r->heightPt);
    }

    #[Test]
    public function row_header_flag(): void
    {
        $r = new Row([$this->cell('A')], isHeader: true, heightPt: 30);
        self::assertTrue($r->isHeader);
        self::assertSame(30.0, $r->heightPt);
    }

    #[Test]
    public function table_style_defaults(): void
    {
        $s = new TableStyle;
        self::assertNull($s->widthPt);
        self::assertNull($s->widthPercent);
        self::assertNull($s->borders);
    }

    #[Test]
    public function cell_style_default_padding(): void
    {
        $s = new CellStyle;
        self::assertSame(2.0, $s->paddingTopPt);
        self::assertSame(4.0, $s->paddingLeftPt);
        self::assertSame(VerticalAlignment::Top, $s->verticalAlign);
    }

    #[Test]
    public function cell_style_with_padding_sets_all_sides(): void
    {
        $s = (new CellStyle)->withPadding(8);
        self::assertSame(8.0, $s->paddingTopPt);
        self::assertSame(8.0, $s->paddingRightPt);
        self::assertSame(8.0, $s->paddingBottomPt);
        self::assertSame(8.0, $s->paddingLeftPt);
    }

    #[Test]
    public function cell_style_background_strips_hash_and_lowercases(): void
    {
        $s = (new CellStyle)->withBackgroundColor('#EEFFEE');
        self::assertSame('eeffee', $s->backgroundColor);
    }

    #[Test]
    public function vertical_alignment_enum_values(): void
    {
        self::assertSame('top', VerticalAlignment::Top->value);
        self::assertSame('center', VerticalAlignment::Center->value);
        self::assertSame('bottom', VerticalAlignment::Bottom->value);
    }

    #[Test]
    public function table_caption_optional(): void
    {
        $t = new Table([new Row([$this->cell('A')])], caption: 'Quarterly sales');
        self::assertSame('Quarterly sales', $t->caption);
    }

    #[Test]
    public function table_column_widths_explicit(): void
    {
        $t = new Table(
            rows: [new Row([$this->cell('A'), $this->cell('B')])],
            columnWidthsPt: [100, 200],
        );
        self::assertSame([100, 200], $t->columnWidthsPt);
    }
}
