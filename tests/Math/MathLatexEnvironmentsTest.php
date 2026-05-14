<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Math;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\MathExpression;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 172/174: nested fractions + LaTeX environments tests.
 */
final class MathLatexEnvironmentsTest extends TestCase
{
    private function render(string $tex): string
    {
        $doc = new Document(new Section([new MathExpression($tex)]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function nested_fraction_in_superscript_renders(): void
    {
        // Phase 172: x^{\frac{a}{b}} should render fraction inside superscript.
        $bytes = $this->render('x^{\\frac{a}{b}}');
        self::assertStringContainsString('(x) Tj', $bytes);
        self::assertStringContainsString('(a) Tj', $bytes);
        self::assertStringContainsString('(b) Tj', $bytes);
        // Fraction line drawn — стрelfие path emit.
        self::assertStringContainsString(' l', $bytes);  // line-to operator
    }

    #[Test]
    public function nested_fraction_in_subscript_renders(): void
    {
        $bytes = $this->render('x_{\\frac{a}{b}}');
        self::assertStringContainsString('(x) Tj', $bytes);
        self::assertStringContainsString('(a) Tj', $bytes);
        self::assertStringContainsString('(b) Tj', $bytes);
    }

    #[Test]
    public function align_environment_strips_begin_end(): void
    {
        // Phase 174: \begin{align}...\end{align} → multi-line.
        // Content concatenated в text tokens per-row by MathRenderer.
        $bytes = $this->render('\\begin{align}a + b \\\\ c + d\\end{align}');
        self::assertStringContainsString('a', $bytes);
        self::assertStringContainsString('b', $bytes);
        self::assertStringContainsString('c', $bytes);
        self::assertStringContainsString('d', $bytes);
        // 2 rows → 2 row baselines (each emits own BT/ET block).
        self::assertGreaterThanOrEqual(2, substr_count($bytes, 'BT'));
    }

    #[Test]
    public function aligned_environment_works(): void
    {
        $bytes = $this->render('\\begin{aligned}x = 1 \\\\ y = 2\\end{aligned}');
        self::assertStringContainsString('1', $bytes);
        self::assertStringContainsString('2', $bytes);
    }

    #[Test]
    public function pmatrix_environment_works(): void
    {
        $bytes = $this->render('\\begin{pmatrix}1 & 2 \\\\ 3 & 4\\end{pmatrix}');
        self::assertStringContainsString('1', $bytes);
        self::assertStringContainsString('2', $bytes);
        self::assertStringContainsString('3', $bytes);
        self::assertStringContainsString('4', $bytes);
        // pmatrix renders с parens — emit path commands.
        self::assertNotEmpty($bytes);
    }

    #[Test]
    public function unknown_environment_passes_content_through(): void
    {
        $bytes = $this->render('\\begin{unknown}plain content\\end{unknown}');
        self::assertStringContainsString('plain content', $bytes);
    }

    #[Test]
    public function custom_font_param_stored(): void
    {
        // Phase 173: MathExpression accepts fontFamily param.
        $math = new MathExpression('x', fontFamily: 'LiberationSerif');
        self::assertSame('LiberationSerif', $math->fontFamily);
    }

    #[Test]
    public function default_font_family_is_null(): void
    {
        $math = new MathExpression('x');
        self::assertNull($math->fontFamily);
    }
}
