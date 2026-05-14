<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Math;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\MathExpression;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Math\MathRenderer;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MathMultiLineTest extends TestCase
{
    #[Test]
    public function parse_lines_splits_top_level_double_backslash(): void
    {
        $rows = MathRenderer::parseLines('a + b \\\\ c + d');
        self::assertCount(2, $rows);
    }

    #[Test]
    public function single_line_returns_one_row(): void
    {
        $rows = MathRenderer::parseLines('x + y');
        self::assertCount(1, $rows);
    }

    #[Test]
    public function nested_matrix_double_backslash_preserved(): void
    {
        // \\\\ inside \matrix{} — должен НЕ split outer.
        $rows = MathRenderer::parseLines('\\matrix{1 & 2 \\\\ 3 & 4}');
        self::assertCount(1, $rows);
        self::assertSame('matrix', $rows[0][0]['type']);
    }

    #[Test]
    public function multiline_renders_all_rows(): void
    {
        $tex = 'x = 1 \\\\ y = 2';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(x = 1) Tj', $bytes);
        self::assertStringContainsString('(y = 2) Tj', $bytes);
    }

    #[Test]
    public function three_line_equation(): void
    {
        $tex = 'a \\\\ b \\\\ c';
        $rows = MathRenderer::parseLines($tex);
        self::assertCount(3, $rows);
    }
}
