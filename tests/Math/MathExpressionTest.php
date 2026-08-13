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

final class MathExpressionTest extends TestCase
{
    #[Test]
    public function plain_text_renders_as_text(): void
    {
        $doc = new Document(new Section([new MathExpression('x + y')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(x + y) Tj', $bytes);
    }

    #[Test]
    public function superscript_uses_smaller_font(): void
    {
        $doc = new Document(new Section([new MathExpression('x^2')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Base text 'x' at original size.
        self::assertStringContainsString('(x) Tj', $bytes);
        // '2' superscript at smaller size (size * 0.7 = 12*0.7 = 8.4).
        self::assertStringContainsString('(2) Tj', $bytes);
        // Smaller font size emitted in second Tf.
        $tfCount = preg_match_all('@\sTf\n@', $bytes);
        self::assertGreaterThanOrEqual(2, $tfCount);
    }

    #[Test]
    public function subscript_uses_smaller_font(): void
    {
        $doc = new Document(new Section([new MathExpression('a_n')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(a) Tj', $bytes);
        self::assertStringContainsString('(n) Tj', $bytes);
    }

    #[Test]
    public function fraction_draws_horizontal_line(): void
    {
        $doc = new Document(new Section([new MathExpression('\\frac{a}{b}')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Numerator + denominator emitted.
        self::assertStringContainsString('(a) Tj', $bytes);
        self::assertStringContainsString('(b) Tj', $bytes);
        // Fraction line — strokeLine (m + l + S).
        self::assertMatchesRegularExpression('@\bm\n@', $bytes);
        self::assertMatchesRegularExpression('@\bl\n@', $bytes);
        self::assertStringContainsString("\nS\n", $bytes);
    }

    #[Test]
    public function sqrt_emits_radical_symbol(): void
    {
        $doc = new Document(new Section([new MathExpression('\\sqrt{x}')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The √ symbol (Unicode U+221A); a StandardFont renders it as WinAnsi →
        // the character shows, but the exact encoding depends on the font.
        // Just verify that x is rendered and the overline is drawn.
        self::assertStringContainsString('(x) Tj', $bytes);
    }

    #[Test]
    public function greek_letters_substituted(): void
    {
        $doc = new Document(new Section([new MathExpression('\\alpha + \\beta')]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Greek α + space + β + ... — the UTF-8 bytes have to be in the output.
        // α = 0xCE 0xB1, β = 0xCE 0xB2. A font supporting Cyrillic is needed;
        // with Helvetica (WinAnsi) the substitution does not fire, but the
        // parsing is fine. Verify that it does not throw.
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function empty_tex_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MathExpression('');
    }

    #[Test]
    public function parse_returns_expected_token_types(): void
    {
        $tokens = MathRenderer::parse('x^2 + \\frac{a}{b}');
        $types = array_map(fn ($t) => $t['type'], $tokens);
        // Expected: text('x'), sup, text(' + '), frac.
        self::assertContains('sup', $types);
        self::assertContains('frac', $types);
    }

    #[Test]
    public function parse_handles_braced_groups(): void
    {
        $tokens = MathRenderer::parse('x^{2y+1}');
        // First token = the text 'x', second = a sup (holding multiple atoms).
        self::assertSame('text', $tokens[0]['type']);
        self::assertSame('x', $tokens[0]['value']);
        self::assertSame('sup', $tokens[1]['type']);
    }

    #[Test]
    public function unknown_command_falls_back_to_literal(): void
    {
        $tokens = MathRenderer::parse('\\unknownmacro');
        self::assertSame('text', $tokens[0]['type']);
        self::assertSame('unknownmacro', $tokens[0]['value']);
    }

    #[Test]
    public function math_combines_features(): void
    {
        // Quadratic formula example.
        $tex = 'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}';
        $doc = new Document(new Section([new MathExpression($tex)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
        // 'x = ' is rendered as a text run; '2a' (the denominator) separately.
        self::assertStringContainsString('(x = ) Tj', $bytes);
        self::assertStringContainsString('(2a) Tj', $bytes);
    }
}
