<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 229: HTML5 semantic block groupings + definition lists.
 */
final class HtmlSemanticBlocksTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    // ---- Semantic containers (header/footer/nav/aside/main/figure) ----

    #[Test]
    public function header_flattens_children(): void
    {
        $blocks = $this->parse('<header><h1>Logo</h1><p>Tagline</p></header>');
        // Header transparent — both children promoted к top level.
        self::assertCount(2, $blocks);
        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertInstanceOf(Paragraph::class, $blocks[1]);
    }

    #[Test]
    public function footer_flattens_children(): void
    {
        $blocks = $this->parse('<footer><p>Copyright</p><p>Address</p></footer>');
        self::assertCount(2, $blocks);
    }

    #[Test]
    public function nav_with_list(): void
    {
        $blocks = $this->parse(
            '<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>'
        );
        // Nav transparent → ul direct child of root.
        self::assertCount(1, $blocks);
        self::assertInstanceOf(\Dskripchenko\PhpPdf\Element\ListNode::class, $blocks[0]);
    }

    #[Test]
    public function aside_treated_as_block(): void
    {
        $blocks = $this->parse('<aside><p>Sidebar text</p></aside>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
    }

    #[Test]
    public function main_transparent(): void
    {
        $blocks = $this->parse('<main><h1>Title</h1><p>Body</p></main>');
        self::assertCount(2, $blocks);
    }

    #[Test]
    public function figure_with_figcaption(): void
    {
        // figure + figcaption — both flattened.
        $blocks = $this->parse(
            '<figure><p>[image placeholder]</p><figcaption>Image caption</figcaption></figure>'
        );
        // Both flattened → 2 blocks.
        self::assertCount(2, $blocks);
    }

    // ---- Definition list ----

    #[Test]
    public function definition_list_dt_dd_pairs(): void
    {
        $blocks = $this->parse(
            '<dl>
                <dt>Term 1</dt>
                <dd>Definition 1</dd>
                <dt>Term 2</dt>
                <dd>Definition 2</dd>
            </dl>'
        );
        // 4 paragraphs (2 dt + 2 dd).
        self::assertCount(4, $blocks);
    }

    #[Test]
    public function dt_renders_bold(): void
    {
        $blocks = $this->parse('<dl><dt>Term</dt><dd>Def</dd></dl>');
        // First block = DT в bold.
        $dt = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $dt);
        $run = $dt->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function dd_renders_indented(): void
    {
        $blocks = $this->parse('<dl><dt>T</dt><dd>D</dd></dl>');
        $dd = $blocks[1];
        self::assertInstanceOf(Paragraph::class, $dd);
        self::assertGreaterThan(0, $dd->style->indentLeftPt);
    }

    #[Test]
    public function nested_semantic_containers(): void
    {
        $blocks = $this->parse(
            '<article>
                <header><h1>Title</h1></header>
                <main><p>Content</p></main>
                <footer><p>Footer</p></footer>
            </article>'
        );
        // article > header > h1 + main > p + footer > p — all flattened.
        // Total: H1 + 2 paragraphs.
        self::assertCount(3, $blocks);
        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertInstanceOf(Paragraph::class, $blocks[1]);
        self::assertInstanceOf(Paragraph::class, $blocks[2]);
    }
}
