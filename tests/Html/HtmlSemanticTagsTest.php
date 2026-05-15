<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 228: HTML extended semantic inline tags (code, kbd, mark, small,
 * big, ins, cite, dfn, q, abbr).
 */
final class HtmlSemanticTagsTest extends TestCase
{
    private function firstRun(string $html): Run
    {
        $blocks = (new HtmlParser)->parse($html);
        $first = $blocks[0]->children[0] ?? null;
        self::assertInstanceOf(Run::class, $first);
        return $first;
    }

    #[Test]
    public function code_tag_uses_courier(): void
    {
        $run = $this->firstRun('<p><code>foo()</code></p>');
        self::assertSame('Courier', $run->style->fontFamily);
    }

    #[Test]
    public function kbd_tag_uses_courier(): void
    {
        $run = $this->firstRun('<p><kbd>Ctrl+C</kbd></p>');
        self::assertSame('Courier', $run->style->fontFamily);
    }

    #[Test]
    public function tt_tag_uses_courier(): void
    {
        $run = $this->firstRun('<p><tt>typewriter</tt></p>');
        self::assertSame('Courier', $run->style->fontFamily);
    }

    #[Test]
    public function var_tag_uses_courier_and_italic(): void
    {
        $run = $this->firstRun('<p><var>x</var></p>');
        self::assertSame('Courier', $run->style->fontFamily);
        self::assertTrue($run->style->italic);
    }

    #[Test]
    public function mark_tag_yellow_background(): void
    {
        $run = $this->firstRun('<p><mark>highlighted</mark></p>');
        self::assertSame('ffff00', $run->style->backgroundColor);
    }

    #[Test]
    public function small_tag_reduces_size(): void
    {
        $run = $this->firstRun('<p style="font-size: 12pt"><small>tiny</small></p>');
        // 12pt * 0.83 ≈ 9.96pt
        self::assertNotNull($run->style->sizePt);
        self::assertLessThan(11.0, $run->style->sizePt);
        self::assertGreaterThan(9.0, $run->style->sizePt);
    }

    #[Test]
    public function big_tag_increases_size(): void
    {
        $run = $this->firstRun('<p style="font-size: 12pt"><big>large</big></p>');
        // 12pt * 1.2 = 14.4pt
        self::assertNotNull($run->style->sizePt);
        self::assertGreaterThan(13.5, $run->style->sizePt);
    }

    #[Test]
    public function ins_tag_underlines(): void
    {
        $run = $this->firstRun('<p><ins>added</ins></p>');
        self::assertTrue($run->style->underline);
    }

    #[Test]
    public function cite_tag_italic(): void
    {
        $run = $this->firstRun('<p><cite>Book Title</cite></p>');
        self::assertTrue($run->style->italic);
    }

    #[Test]
    public function dfn_tag_italic(): void
    {
        $run = $this->firstRun('<p><dfn>term</dfn></p>');
        self::assertTrue($run->style->italic);
    }

    #[Test]
    public function q_tag_italic(): void
    {
        $run = $this->firstRun('<p><q>quoted</q></p>');
        self::assertTrue($run->style->italic);
    }

    #[Test]
    public function abbr_tag_underlines(): void
    {
        $run = $this->firstRun('<p><abbr title="World Wide Web">WWW</abbr></p>');
        self::assertTrue($run->style->underline);
    }

    #[Test]
    public function nested_semantic_tags_combine(): void
    {
        // <code><b>bold code</b></code> — Courier + bold
        $run = $this->firstRun('<p><code><b>bold code</b></code></p>');
        self::assertSame('Courier', $run->style->fontFamily);
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function samp_tag_courier(): void
    {
        $run = $this->firstRun('<p><samp>sample output</samp></p>');
        self::assertSame('Courier', $run->style->fontFamily);
    }
}
