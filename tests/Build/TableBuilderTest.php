<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\CellBuilder;
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableBuilderTest extends TestCase
{
    #[Test]
    public function empty_builder_produces_empty_table(): void
    {
        $t = TableBuilder::new()->build();
        self::assertTrue($t->isEmpty());
    }

    #[Test]
    public function simple_table_with_rows_and_cells(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r->cells(['A', 'B', 'C']))
            ->row(fn(RowBuilder $r) => $r->cells(['1', '2', '3']))
            ->build();

        self::assertCount(2, $t->rows);
        self::assertCount(3, $t->rows[0]->cells);
        self::assertCount(3, $t->rows[1]->cells);
    }

    #[Test]
    public function header_row(): void
    {
        $t = TableBuilder::new()
            ->headerRow(fn(RowBuilder $r) => $r->cells(['H1', 'H2']))
            ->row(fn(RowBuilder $r) => $r->cells(['a', 'b']))
            ->build();

        self::assertTrue($t->rows[0]->isHeader);
        self::assertFalse($t->rows[1]->isHeader);
    }

    #[Test]
    public function cell_via_closure_with_style(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r
                ->cell(fn(CellBuilder $c) => $c
                    ->text('styled')
                    ->background('#eeffee')
                    ->padding(8)
                    ->vAlignMiddle()
                )
            )
            ->build();

        $cell = $t->rows[0]->cells[0];
        self::assertSame('eeffee', $cell->style->backgroundColor);
        self::assertSame(8.0, $cell->style->paddingTopPt);
        self::assertSame(VerticalAlignment::Center, $cell->style->verticalAlign);
    }

    #[Test]
    public function cell_with_span(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r
                ->cell(fn(CellBuilder $c) => $c->text('span2')->span(2))
                ->cell('rest')
            )
            ->build();

        self::assertSame(2, $t->rows[0]->cells[0]->columnSpan);
        self::assertSame(3, $t->columnCount());
    }

    #[Test]
    public function table_style_methods(): void
    {
        $t = TableBuilder::new()
            ->widthPercent(75)
            ->alignCenter()
            ->defaultCellBorder(new Border(BorderStyle::Single))
            ->columnWidths([100, 200, 300])
            ->caption('Caption')
            ->spaceBefore(12)
            ->spaceAfter(18)
            ->row(fn(RowBuilder $r) => $r->cells(['A', 'B', 'C']))
            ->build();

        self::assertSame(75.0, $t->style->widthPercent);
        self::assertSame(Alignment::Center, $t->style->alignment);
        self::assertNotNull($t->style->defaultCellBorder);
        self::assertSame([100, 200, 300], $t->columnWidthsPt);
        self::assertSame('Caption', $t->caption);
        self::assertSame(12.0, $t->style->spaceBeforePt);
        self::assertSame(18.0, $t->style->spaceAfterPt);
    }

    #[Test]
    public function row_height_override(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r->height(40)->cells(['A']))
            ->build();
        self::assertSame(40.0, $t->rows[0]->heightPt);
    }

    #[Test]
    public function cell_complex_paragraph(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r
                ->cell(fn(CellBuilder $c) => $c
                    ->paragraph(fn($p) => $p
                        ->text('Mixed ')
                        ->bold('bold ')
                        ->italic('italic')
                    )
                )
            )
            ->build();

        $cell = $t->rows[0]->cells[0];
        self::assertCount(1, $cell->children);
        $p = $cell->children[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(3, $p->children);
        self::assertTrue($p->children[1]->style->bold);
    }

    #[Test]
    public function document_builder_table_method(): void
    {
        $doc = DocumentBuilder::new()
            ->heading(1, 'Report')
            ->table(fn(TableBuilder $tb) => $tb
                ->headerRow(fn(RowBuilder $r) => $r->cells(['Name', 'Value']))
                ->row(fn(RowBuilder $r) => $r->cells(['alpha', '42']))
            )
            ->build();

        self::assertCount(2, $doc->section->body);
        self::assertInstanceOf(Table::class, $doc->section->body[1]);
    }

    #[Test]
    public function document_builder_accepts_table_directly(): void
    {
        $t = TableBuilder::new()
            ->row(fn(RowBuilder $r) => $r->cells(['X']))
            ->build();
        $doc = DocumentBuilder::new()->table($t)->build();
        self::assertSame($t, $doc->section->body[0]);
    }

    #[Test]
    public function full_builder_smoke_renders_pdf(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Phase 5c — Builders')
            ->table(fn(TableBuilder $tb) => $tb
                ->widthPercent(80)
                ->alignCenter()
                ->defaultCellBorder(new Border(BorderStyle::Single, 4, '888888'))
                ->headerRow(fn(RowBuilder $r) => $r
                    ->cell(fn(CellBuilder $c) => $c
                        ->text('SKU')->background('#4477aa')
                    )
                    ->cell(fn(CellBuilder $c) => $c->text('Name')->background('#4477aa'))
                    ->cell(fn(CellBuilder $c) => $c->text('Qty')->background('#4477aa'))
                )
                ->row(fn(RowBuilder $r) => $r->cells(['A-100', 'Widget', '42']))
                ->row(fn(RowBuilder $r) => $r->cells(['B-200', 'Gadget', '5']))
            )
            ->toBytes();

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString(' re', $bytes);   // rect path
        self::assertStringContainsString("S\n", $bytes);   // stroke for borders
    }
}
