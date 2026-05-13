<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaggedTableTest extends TestCase
{
    private function makeCell(string $text): Cell
    {
        return new Cell([new Paragraph([new Run($text)])]);
    }

    #[Test]
    public function tagged_table_emits_table_tr_td_structs(): void
    {
        $table = new Table([
            new Row([$this->makeCell('A'), $this->makeCell('B')]),
            new Row([$this->makeCell('C'), $this->makeCell('D')]),
        ]);
        $ast = new AstDocument(new Section([$table]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /S /Table struct.
        self::assertStringContainsString('/S /Table', $bytes);
        // /S /TR (2 rows).
        self::assertSame(2, substr_count($bytes, '/S /TR'));
        // /S /TD (4 cells).
        self::assertSame(4, substr_count($bytes, '/S /TD'));
    }

    #[Test]
    public function tagged_table_content_streams_have_bdc(): void
    {
        $table = new Table([new Row([$this->makeCell('X')])]);
        $ast = new AstDocument(new Section([$table]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // BDC for Table + TR + TD.
        self::assertStringContainsString('/Table << /MCID', $bytes);
        self::assertStringContainsString('/TR << /MCID', $bytes);
        self::assertStringContainsString('/TD << /MCID', $bytes);
    }

    #[Test]
    public function untagged_table_no_struct(): void
    {
        $table = new Table([new Row([$this->makeCell('X')])]);
        $doc = new AstDocument(new Section([$table]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/S /Table', $bytes);
        self::assertStringNotContainsString('BDC', $bytes);
    }

    #[Test]
    public function cell_paragraph_does_not_emit_separate_p_tag(): void
    {
        // Внутри tagged cell, paragraph не должен emit нестoднем /P.
        $table = new Table([new Row([$this->makeCell('X')])]);
        $ast = new AstDocument(new Section([$table]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // BDC count: Table + TR + TD = 3. No /P внутри.
        $bdcCount = substr_count($bytes, 'BDC');
        self::assertSame(3, $bdcCount);
    }

    #[Test]
    public function multiple_tables_separate_structs(): void
    {
        $t1 = new Table([new Row([$this->makeCell('T1')])]);
        $t2 = new Table([new Row([$this->makeCell('T2')])]);
        $ast = new AstDocument(new Section([$t1, $t2]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertSame(2, substr_count($bytes, '/S /Table'));
    }

    #[Test]
    public function struct_tree_root_includes_table_kids(): void
    {
        $table = new Table([
            new Row([$this->makeCell('A'), $this->makeCell('B')]),
        ]);
        $ast = new AstDocument(new Section([$table]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // StructTreeRoot /K array contains 4 references: Table + TR + 2× TD.
        preg_match('@/StructTreeRoot /K \[([^\]]+)\]@', $bytes, $m);
        self::assertNotEmpty($m);
        $refs = preg_match_all('@\d+\s+0\s+R@', $m[1]);
        self::assertSame(4, $refs);
    }
}
