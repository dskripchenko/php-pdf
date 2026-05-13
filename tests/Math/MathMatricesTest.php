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

final class MathMatricesTest extends TestCase
{
    #[Test]
    public function matrix_2x2_renders_cells(): void
    {
        $tex = '\\matrix{1 & 2 \\\\ 3 & 4}';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        // Все 4 cells rendered.
        self::assertStringContainsString('(1) Tj', $bytes);
        self::assertStringContainsString('(2) Tj', $bytes);
        self::assertStringContainsString('(3) Tj', $bytes);
        self::assertStringContainsString('(4) Tj', $bytes);
    }

    #[Test]
    public function pmatrix_renders_parens(): void
    {
        $tex = '\\pmatrix{a & b \\\\ c & d}';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        // PDF escapes `(` и `)` literal с backslash.
        self::assertStringContainsString('(\\() Tj', $bytes);
        self::assertStringContainsString('(\\)) Tj', $bytes);
    }

    #[Test]
    public function bmatrix_renders_brackets(): void
    {
        $tex = '\\bmatrix{x \\\\ y}';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('([) Tj', $bytes);
        self::assertStringContainsString('(]) Tj', $bytes);
    }

    #[Test]
    public function vmatrix_renders_vertical_bars(): void
    {
        $tex = '\\vmatrix{1 & 0 \\\\ 0 & 1}';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(|) Tj', $bytes);
    }

    #[Test]
    public function nested_fraction_in_matrix_cell(): void
    {
        $tex = '\\matrix{\\frac{1}{2} & x \\\\ y & \\frac{3}{4}}';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        // Both fraction lines (2 strokeLine для fracs) + cell text.
        self::assertStringContainsString('(1) Tj', $bytes);
        self::assertStringContainsString('(2) Tj', $bytes);
        self::assertStringContainsString('(x) Tj', $bytes);
    }

    #[Test]
    public function parse_matrix_token_structure(): void
    {
        $tokens = MathRenderer::parse('\\matrix{a & b \\\\ c & d}');
        self::assertCount(1, $tokens);
        self::assertSame('matrix', $tokens[0]['type']);
        self::assertSame('matrix', $tokens[0]['variant']);
        $rows = $tokens[0]['rows'];
        self::assertCount(2, $rows);
        // Each row 2 cells.
        self::assertCount(2, $rows[0]);
        self::assertCount(2, $rows[1]);
    }
}
