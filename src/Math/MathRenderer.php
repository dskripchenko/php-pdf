<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Math;

use Dskripchenko\PhpPdf\Pdf\Page;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;

/**
 * Phase 69: TeX-like math expression → PDF render.
 *
 * Two-pass:
 *  1. measureWidth() — compute total horizontal extent.
 *  2. render() — emit text + drawing operators at given (x, y) baseline.
 *
 * Symbol substitutions через self::GREEK + self::OPERATORS tables.
 */
final class MathRenderer
{
    /** Greek letter substitutions (LaTeX command → Unicode). */
    private const GREEK = [
        'alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ',
        'epsilon' => 'ε', 'zeta' => 'ζ', 'eta' => 'η', 'theta' => 'θ',
        'iota' => 'ι', 'kappa' => 'κ', 'lambda' => 'λ', 'mu' => 'μ',
        'nu' => 'ν', 'xi' => 'ξ', 'pi' => 'π', 'rho' => 'ρ',
        'sigma' => 'σ', 'tau' => 'τ', 'phi' => 'φ', 'chi' => 'χ',
        'psi' => 'ψ', 'omega' => 'ω',
        'Alpha' => 'Α', 'Beta' => 'Β', 'Gamma' => 'Γ', 'Delta' => 'Δ',
        'Epsilon' => 'Ε', 'Zeta' => 'Ζ', 'Eta' => 'Η', 'Theta' => 'Θ',
        'Iota' => 'Ι', 'Kappa' => 'Κ', 'Lambda' => 'Λ', 'Mu' => 'Μ',
        'Nu' => 'Ν', 'Xi' => 'Ξ', 'Pi' => 'Π', 'Rho' => 'Ρ',
        'Sigma' => 'Σ', 'Tau' => 'Τ', 'Phi' => 'Φ', 'Chi' => 'Χ',
        'Psi' => 'Ψ', 'Omega' => 'Ω',
    ];

    /** Operator substitutions. */
    private const OPERATORS = [
        'cdot' => '·', 'times' => '×', 'div' => '÷',
        'pm' => '±', 'mp' => '∓',
        'leq' => '≤', 'geq' => '≥', 'neq' => '≠', 'approx' => '≈',
        'equiv' => '≡', 'infty' => '∞',
        'sum' => '∑', 'int' => '∫', 'prod' => '∏',
        'forall' => '∀', 'exists' => '∃', 'in' => '∈', 'notin' => '∉',
        'subset' => '⊂', 'supset' => '⊃', 'cup' => '∪', 'cap' => '∩',
        'rightarrow' => '→', 'leftarrow' => '←', 'to' => '→',
    ];

    /**
     * Parse TeX string → token tree. Returns list of nodes:
     *  - ['type' => 'text', 'value' => string]
     *  - ['type' => 'sup', 'value' => list<node>]
     *  - ['type' => 'sub', 'value' => list<node>]
     *  - ['type' => 'frac', 'num' => list<node>, 'den' => list<node>]
     *  - ['type' => 'sqrt', 'value' => list<node>]
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $tex): array
    {
        $pos = 0;

        return self::parseGroup($tex, $pos, false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseGroup(string $tex, int &$pos, bool $insideBrace): array
    {
        $tokens = [];
        $textBuf = '';
        $flush = static function () use (&$tokens, &$textBuf): void {
            if ($textBuf !== '') {
                $tokens[] = ['type' => 'text', 'value' => $textBuf];
                $textBuf = '';
            }
        };

        $len = strlen($tex);
        while ($pos < $len) {
            $ch = $tex[$pos];
            if ($insideBrace && $ch === '}') {
                $pos++; // consume }
                $flush();

                return $tokens;
            }
            if ($ch === '^') {
                $flush();
                $pos++;
                $tokens[] = ['type' => 'sup', 'value' => self::parseArg($tex, $pos)];

                continue;
            }
            if ($ch === '_') {
                $flush();
                $pos++;
                $tokens[] = ['type' => 'sub', 'value' => self::parseArg($tex, $pos)];

                continue;
            }
            if ($ch === '\\') {
                $flush();
                $pos++;
                $cmdStart = $pos;
                while ($pos < $len && ctype_alpha($tex[$pos])) {
                    $pos++;
                }
                $cmd = substr($tex, $cmdStart, $pos - $cmdStart);
                $tokens = array_merge($tokens, self::expandCommand($cmd, $tex, $pos));

                continue;
            }
            if ($ch === '{') {
                $flush();
                $pos++; // consume {
                $sub = self::parseGroup($tex, $pos, true);
                $tokens = array_merge($tokens, $sub);

                continue;
            }
            $textBuf .= $ch;
            $pos++;
        }
        $flush();

        return $tokens;
    }

    /**
     * Parse single argument: either `{...}` group или single character.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseArg(string $tex, int &$pos): array
    {
        if ($pos < strlen($tex) && $tex[$pos] === '{') {
            $pos++;

            return self::parseGroup($tex, $pos, true);
        }
        if ($pos < strlen($tex)) {
            if ($tex[$pos] === '\\') {
                $pos++;
                $cmdStart = $pos;
                while ($pos < strlen($tex) && ctype_alpha($tex[$pos])) {
                    $pos++;
                }
                $cmd = substr($tex, $cmdStart, $pos - $cmdStart);

                return self::expandCommand($cmd, $tex, $pos);
            }
            $tok = [['type' => 'text', 'value' => $tex[$pos]]];
            $pos++;

            return $tok;
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function expandCommand(string $cmd, string $tex, int &$pos): array
    {
        if (isset(self::GREEK[$cmd])) {
            return [['type' => 'text', 'value' => self::GREEK[$cmd]]];
        }
        if (isset(self::OPERATORS[$cmd])) {
            return [['type' => 'text', 'value' => self::OPERATORS[$cmd]]];
        }
        if ($cmd === 'frac') {
            $num = self::parseArg($tex, $pos);
            $den = self::parseArg($tex, $pos);

            return [['type' => 'frac', 'num' => $num, 'den' => $den]];
        }
        if ($cmd === 'sqrt') {
            $val = self::parseArg($tex, $pos);

            return [['type' => 'sqrt', 'value' => $val]];
        }
        // Unknown command — fallback к literal text.
        return [['type' => 'text', 'value' => $cmd]];
    }

    /**
     * Measure total horizontal width.
     *
     * @param  list<array<string, mixed>>  $tokens
     */
    public static function measureWidth(array $tokens, float $fontSize, PdfFont|StandardFont $font): float
    {
        $w = 0.0;
        foreach ($tokens as $tok) {
            $w += self::measureToken($tok, $fontSize, $font);
        }

        return $w;
    }

    /**
     * @param  array<string, mixed>  $tok
     */
    private static function measureToken(array $tok, float $fontSize, PdfFont|StandardFont $font): float
    {
        $charWidth = $fontSize * 0.55;

        return match ($tok['type']) {
            'text' => mb_strlen($tok['value'], 'UTF-8') * $charWidth,
            'sup', 'sub' => self::measureWidth($tok['value'], $fontSize * 0.7, $font),
            'frac' => max(
                self::measureWidth($tok['num'], $fontSize * 0.85, $font),
                self::measureWidth($tok['den'], $fontSize * 0.85, $font),
            ) + 4.0,
            'sqrt' => self::measureWidth($tok['value'], $fontSize, $font) + $fontSize * 0.6,
            default => 0.0,
        };
    }

    /**
     * Render token list at (x, baselineY). Returns final x after rendering.
     *
     * @param  list<array<string, mixed>>  $tokens
     */
    public static function render(
        array $tokens,
        Page $page,
        float $x,
        float $baselineY,
        float $fontSize,
        PdfFont|StandardFont $font,
    ): float {
        foreach ($tokens as $tok) {
            $x = self::renderToken($tok, $page, $x, $baselineY, $fontSize, $font);
        }

        return $x;
    }

    /**
     * @param  array<string, mixed>  $tok
     */
    private static function renderToken(
        array $tok,
        Page $page,
        float $x,
        float $baselineY,
        float $fontSize,
        PdfFont|StandardFont $font,
    ): float {
        switch ($tok['type']) {
            case 'text':
                $text = (string) $tok['value'];
                self::drawText($page, $text, $x, $baselineY, $fontSize, $font);

                return $x + mb_strlen($text, 'UTF-8') * $fontSize * 0.55;

            case 'sup':
                $supSize = $fontSize * 0.7;
                $supY = $baselineY + $fontSize * 0.35;
                $endX = self::render($tok['value'], $page, $x, $supY, $supSize, $font);

                return $endX;

            case 'sub':
                $subSize = $fontSize * 0.7;
                $subY = $baselineY - $fontSize * 0.15;
                $endX = self::render($tok['value'], $page, $x, $subY, $subSize, $font);

                return $endX;

            case 'frac':
                $smaller = $fontSize * 0.85;
                $numW = self::measureWidth($tok['num'], $smaller, $font);
                $denW = self::measureWidth($tok['den'], $smaller, $font);
                $maxW = max($numW, $denW);
                $lineY = $baselineY + $fontSize * 0.25;
                // Numerator above line.
                self::render($tok['num'], $page, $x + ($maxW - $numW) / 2, $lineY + $smaller * 0.1, $smaller, $font);
                // Denominator below line.
                self::render($tok['den'], $page, $x + ($maxW - $denW) / 2, $lineY - $smaller, $smaller, $font);
                // Fraction line.
                $page->strokeLine($x, $lineY, $x + $maxW, $lineY, 0.5, 0, 0, 0);

                return $x + $maxW + 2.0;

            case 'sqrt':
                $valW = self::measureWidth($tok['value'], $fontSize, $font);
                $radWidth = $fontSize * 0.5;
                // Radical sign √ (Unicode U+221A) — show inline.
                self::drawText($page, "\u{221A}", $x, $baselineY, $fontSize, $font);
                // Overline above value.
                $page->strokeLine(
                    $x + $radWidth, $baselineY + $fontSize * 0.85,
                    $x + $radWidth + $valW + 2, $baselineY + $fontSize * 0.85,
                    0.5, 0, 0, 0,
                );
                self::render($tok['value'], $page, $x + $radWidth, $baselineY, $fontSize, $font);

                return $x + $radWidth + $valW + 2;
        }

        return $x;
    }

    private static function drawText(Page $page, string $text, float $x, float $y, float $size, PdfFont|StandardFont $font): void
    {
        if ($font instanceof PdfFont) {
            $page->showEmbeddedText($text, $x, $y, $font, $size);
        } else {
            $page->showText($text, $x, $y, $font, $size);
        }
    }
}
