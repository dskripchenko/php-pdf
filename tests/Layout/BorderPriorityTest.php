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
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderPriorityTest extends TestCase
{
    private function cell(string $text, ?BorderSet $borders = null): Cell
    {
        return new Cell(
            children: [new Paragraph([new Run($text)])],
            style: $borders !== null
                ? new CellStyle(borders: $borders)
                : new CellStyle,
        );
    }

    #[Test]
    public function thicker_left_border_wins_over_thin_right_in_collapse(): void
    {
        // Cell A: right border thin (1pt)
        // Cell B: left border thick (3pt)
        // Expected: thick wins на shared edge.
        $thin = new Border(BorderStyle::Single, 8, '000000');     // 1pt
        $thick = new Border(BorderStyle::Single, 24, 'cc0000');   // 3pt red

        $cellA = $this->cell('A', new BorderSet(top: $thin, bottom: $thin, left: $thin, right: $thin));
        $cellB = $this->cell('B', new BorderSet(top: $thin, bottom: $thin, left: $thick, right: $thin));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$cellA, $cellB])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Should contain thick red stroke ' 3 w' и '0.8 0 0 RG'.
        self::assertStringContainsString("\n3 w", $bytes);
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+RG@', $bytes);
    }

    #[Test]
    public function none_style_loses_to_solid(): void
    {
        $solid = new Border(BorderStyle::Single, 8, '000000');
        $none = new Border(BorderStyle::None, 8, '000000');

        $cellA = $this->cell('A', new BorderSet(right: $solid));
        $cellB = $this->cell('B', new BorderSet(left: $none));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$cellA, $cellB])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Solid wins → stroke for cellB's left side.
        self::assertStringContainsString("S\n", $bytes);
    }

    #[Test]
    public function equal_width_double_beats_solid(): void
    {
        $solid = new Border(BorderStyle::Single, 16, '000000');   // 2pt
        $double = new Border(BorderStyle::Double, 16, 'cc0000');  // 2pt red double

        $cellA = $this->cell('A', new BorderSet(right: $solid));
        $cellB = $this->cell('B', new BorderSet(left: $double));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$cellA, $cellB])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Double rendering: sub-lines width = 2pt / 3 ≈ 0.67pt.
        // Red color confirms double won.
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+RG@', $bytes);
    }

    // -------- Phase 153: cross-row "thicker wins" --------

    #[Test]
    public function thick_bottom_of_row_one_wins_over_thin_top_of_row_two(): void
    {
        // Row 1 cell A: bottom thick (3pt red).
        // Row 2 cell B (same column): top thin (1pt black).
        // Expected: thick red wins на shared edge.
        $thin = new Border(BorderStyle::Single, 8, '000000');
        $thick = new Border(BorderStyle::Single, 24, 'cc0000');

        $cellA = $this->cell('A', new BorderSet(top: $thin, bottom: $thick, left: $thin, right: $thin));
        $cellB = $this->cell('B', new BorderSet(top: $thin, bottom: $thin, left: $thin, right: $thin));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$cellA]), new Row([$cellB])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // 3pt stroke и red color мapping на shared edge.
        self::assertStringContainsString("\n3 w", $bytes);
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+RG@', $bytes);
    }

    #[Test]
    public function thick_top_of_row_two_wins_over_thin_bottom_of_row_one(): void
    {
        // Inverse: row 2 top is thicker.
        $thin = new Border(BorderStyle::Single, 8, '000000');
        $thick = new Border(BorderStyle::Single, 24, 'cc0000');

        $cellA = $this->cell('A', new BorderSet(top: $thin, bottom: $thin, left: $thin, right: $thin));
        $cellB = $this->cell('B', new BorderSet(top: $thick, bottom: $thin, left: $thin, right: $thin));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$cellA]), new Row([$cellB])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString("\n3 w", $bytes);
        self::assertMatchesRegularExpression('@0\.8\s+0\s+0\s+RG@', $bytes);
    }

    #[Test]
    public function cross_row_span_propagates_bottom_to_two_columns(): void
    {
        // Row 1: один колспан=2 cell с thick bottom.
        // Row 2: две cells с thin tops.
        // Expected: каждая cell в row 2 inherit thick top (по своему col index).
        $thin = new Border(BorderStyle::Single, 8, '000000');
        $thick = new Border(BorderStyle::Single, 24, 'cc0000');

        $spanCell = new Cell(
            children: [new Paragraph([new Run('Span')])],
            columnSpan: 2,
            style: new CellStyle(borders: new BorderSet(top: $thin, bottom: $thick, left: $thin, right: $thin)),
        );
        $cellB = $this->cell('B', new BorderSet(top: $thin, bottom: $thin, left: $thin, right: $thin));
        $cellC = $this->cell('C', new BorderSet(top: $thin, bottom: $thin, left: $thin, right: $thin));

        $doc = new Document(new Section([
            new Table(
                rows: [new Row([$spanCell]), new Row([$cellB, $cellC])],
                style: new TableStyle(borderCollapse: true),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Thick red должен render-иться (минимум один раз).
        self::assertStringContainsString("\n3 w", $bytes);
    }
}
