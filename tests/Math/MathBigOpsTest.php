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

final class MathBigOpsTest extends TestCase
{
    #[Test]
    public function sum_with_limits_creates_bigop(): void
    {
        $tokens = MathRenderer::parse('\\sum_{i=1}^{n} i');
        // First token = bigop с symbol ∑ + sub + sup.
        self::assertSame('bigop', $tokens[0]['type']);
        self::assertSame('∑', $tokens[0]['symbol']);
        self::assertNotNull($tokens[0]['sub']);
        self::assertNotNull($tokens[0]['sup']);
    }

    #[Test]
    public function int_with_limits(): void
    {
        $tokens = MathRenderer::parse('\\int_0^1 x dx');
        self::assertSame('bigop', $tokens[0]['type']);
        self::assertSame('∫', $tokens[0]['symbol']);
    }

    #[Test]
    public function prod_with_only_sub(): void
    {
        $tokens = MathRenderer::parse('\\prod_{k=1} k');
        self::assertSame('bigop', $tokens[0]['type']);
        self::assertNotNull($tokens[0]['sub']);
        self::assertNull($tokens[0]['sup']);
    }

    #[Test]
    public function bigop_without_limits_stays_text(): void
    {
        $tokens = MathRenderer::parse('\\sum');
        // No following sub/sup → stays text (not combined).
        self::assertSame('text', $tokens[0]['type']);
        self::assertSame('∑', $tokens[0]['value']);
    }

    #[Test]
    public function bigop_renders_symbol_and_limits(): void
    {
        $tex = '\\sum_{i=1}^{n} i';
        $bytes = (new Document(new Section([new MathExpression($tex)])))
            ->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
        // sub "i=1" and sup "n" rendered как text.
        self::assertStringContainsString('(i=1) Tj', $bytes);
        self::assertStringContainsString('(n) Tj', $bytes);
    }

    #[Test]
    public function sum_with_only_super(): void
    {
        $tokens = MathRenderer::parse('\\sum^{N}');
        self::assertSame('bigop', $tokens[0]['type']);
        self::assertNull($tokens[0]['sub']);
        self::assertNotNull($tokens[0]['sup']);
    }

    #[Test]
    public function multiple_bigops_in_one_expression(): void
    {
        $tex = '\\sum_{i=1}^{n} \\int_0^1 f(x) dx';
        $tokens = MathRenderer::parse($tex);
        $bigOpCount = 0;
        foreach ($tokens as $t) {
            if ($t['type'] === 'bigop') {
                $bigOpCount++;
            }
        }
        self::assertSame(2, $bigOpCount);
    }
}
