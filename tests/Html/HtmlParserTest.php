<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Style\ListFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 219: HTML/CSS parser tests.
 */
final class HtmlParserTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    // ----- Block-level -----

    #[Test]
    public function paragraph_with_text(): void
    {
        $blocks = $this->parse('<p>Hello world</p>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertCount(1, $blocks[0]->children);
        self::assertInstanceOf(Run::class, $blocks[0]->children[0]);
        self::assertSame('Hello world', $blocks[0]->children[0]->text);
    }

    #[Test]
    public function multiple_paragraphs(): void
    {
        $blocks = $this->parse('<p>First</p><p>Second</p>');
        self::assertCount(2, $blocks);
    }

    #[Test]
    public function heading_levels_1_to_6(): void
    {
        foreach (range(1, 6) as $level) {
            $blocks = $this->parse("<h$level>Title</h$level>");
            self::assertCount(1, $blocks);
            self::assertInstanceOf(Heading::class, $blocks[0]);
            self::assertSame($level, $blocks[0]->level);
        }
    }

    #[Test]
    public function horizontal_rule(): void
    {
        $blocks = $this->parse('<hr>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(HorizontalRule::class, $blocks[0]);
    }

    #[Test]
    public function unordered_list(): void
    {
        $blocks = $this->parse('<ul><li>A</li><li>B</li><li>C</li></ul>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(ListNode::class, $blocks[0]);
        self::assertSame(ListFormat::Bullet, $blocks[0]->format);
        self::assertCount(3, $blocks[0]->items);
        self::assertInstanceOf(ListItem::class, $blocks[0]->items[0]);
    }

    #[Test]
    public function ordered_list(): void
    {
        $blocks = $this->parse('<ol><li>One</li><li>Two</li></ol>');
        self::assertInstanceOf(ListNode::class, $blocks[0]);
        self::assertSame(ListFormat::Decimal, $blocks[0]->format);
    }

    #[Test]
    public function nested_list(): void
    {
        $blocks = $this->parse('<ul><li>Outer<ul><li>Inner</li></ul></li></ul>');
        $outer = $blocks[0];
        self::assertInstanceOf(ListNode::class, $outer);
        $item = $outer->items[0];
        self::assertNotNull($item->nestedList);
        self::assertCount(1, $item->nestedList->items);
    }

    #[Test]
    public function simple_table(): void
    {
        $blocks = $this->parse(
            '<table><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>'
        );
        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertCount(2, $blocks[0]->rows);
        self::assertCount(2, $blocks[0]->rows[0]->cells);
    }

    #[Test]
    public function table_with_thead_tbody(): void
    {
        $blocks = $this->parse(
            '<table>
                <thead><tr><th>Header</th></tr></thead>
                <tbody><tr><td>Data</td></tr></tbody>
            </table>'
        );
        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertCount(2, $blocks[0]->rows);
    }

    #[Test]
    public function table_colspan_rowspan(): void
    {
        $blocks = $this->parse(
            '<table><tr><td colspan="2" rowspan="3">Big</td></tr></table>'
        );
        $cell = $blocks[0]->rows[0]->cells[0];
        self::assertSame(2, $cell->columnSpan);
        self::assertSame(3, $cell->rowSpan);
    }

    // ----- Inline-level -----

    #[Test]
    public function bold_via_b_and_strong(): void
    {
        $blocks = $this->parse('<p><b>Bold</b> and <strong>strong</strong></p>');
        $inlines = $blocks[0]->children;
        // Find bold runs
        $boldRuns = array_filter(
            $inlines,
            fn ($i) => $i instanceof Run && $i->style->bold
        );
        self::assertGreaterThanOrEqual(2, count($boldRuns));
    }

    #[Test]
    public function italic_via_i_and_em(): void
    {
        $blocks = $this->parse('<p><i>I</i> and <em>EM</em></p>');
        $inlines = $blocks[0]->children;
        $italicRuns = array_filter(
            $inlines,
            fn ($i) => $i instanceof Run && $i->style->italic
        );
        self::assertGreaterThanOrEqual(2, count($italicRuns));
    }

    #[Test]
    public function underline_strikethrough_sup_sub(): void
    {
        $blocks = $this->parse(
            '<p><u>U</u><s>S</s><sup>up</sup><sub>down</sub></p>'
        );
        $inlines = $blocks[0]->children;

        $hasU = false;
        $hasS = false;
        $hasSup = false;
        $hasSub = false;
        foreach ($inlines as $inline) {
            if ($inline instanceof Run) {
                if ($inline->style->underline) {
                    $hasU = true;
                }
                if ($inline->style->strikethrough) {
                    $hasS = true;
                }
                if ($inline->style->superscript) {
                    $hasSup = true;
                }
                if ($inline->style->subscript) {
                    $hasSub = true;
                }
            }
        }
        self::assertTrue($hasU);
        self::assertTrue($hasS);
        self::assertTrue($hasSup);
        self::assertTrue($hasSub);
    }

    #[Test]
    public function line_break(): void
    {
        $blocks = $this->parse('<p>Line 1<br>Line 2</p>');
        $inlines = $blocks[0]->children;
        $hasBreak = false;
        foreach ($inlines as $inline) {
            if ($inline instanceof LineBreak) {
                $hasBreak = true;
                break;
            }
        }
        self::assertTrue($hasBreak);
    }

    #[Test]
    public function external_hyperlink(): void
    {
        $blocks = $this->parse('<p><a href="https://example.com">Click</a></p>');
        $inlines = $blocks[0]->children;
        $links = array_filter($inlines, fn ($i) => $i instanceof Hyperlink);
        self::assertCount(1, $links);
        $link = array_values($links)[0];
        self::assertSame('https://example.com', $link->href);
        self::assertFalse($link->isInternal());
    }

    #[Test]
    public function internal_hyperlink(): void
    {
        $blocks = $this->parse('<p><a href="#chapter1">Chapter</a></p>');
        $link = $blocks[0]->children[0];
        self::assertInstanceOf(Hyperlink::class, $link);
        self::assertTrue($link->isInternal());
        self::assertSame('chapter1', $link->anchor);
    }

    #[Test]
    public function nested_styling(): void
    {
        // Bold + italic in nested tags.
        $blocks = $this->parse('<p><b>Bold <i>and italic</i></b></p>');
        $inlines = $blocks[0]->children;

        $hasBoldItalic = false;
        foreach ($inlines as $inline) {
            if ($inline instanceof Run && $inline->style->bold && $inline->style->italic) {
                $hasBoldItalic = true;
                break;
            }
        }
        self::assertTrue($hasBoldItalic);
    }

    // ----- Inline CSS -----

    #[Test]
    public function inline_color_hex_6(): void
    {
        $blocks = $this->parse('<p><span style="color: #ff0000">Red</span></p>');
        $run = $blocks[0]->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('ff0000', $run->style->color);
    }

    #[Test]
    public function inline_color_hex_3(): void
    {
        $blocks = $this->parse('<p><span style="color: #f00">Red</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('ff0000', $run->style->color);
    }

    #[Test]
    public function inline_color_rgb(): void
    {
        $blocks = $this->parse('<p><span style="color: rgb(0, 128, 255)">Blue</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('0080ff', $run->style->color);
    }

    #[Test]
    public function inline_color_named(): void
    {
        $blocks = $this->parse('<p><span style="color: green">Green</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('008000', $run->style->color);
    }

    #[Test]
    public function inline_font_size_pt(): void
    {
        $blocks = $this->parse('<p><span style="font-size: 18pt">Big</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(18.0, $run->style->sizePt);
    }

    #[Test]
    public function inline_font_size_px(): void
    {
        // 16px → 12pt
        $blocks = $this->parse('<p><span style="font-size: 16px">Body</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(12.0, $run->style->sizePt);
    }

    #[Test]
    public function inline_font_weight_bold(): void
    {
        $blocks = $this->parse('<p><span style="font-weight: bold">B</span></p>');
        $run = $blocks[0]->children[0];
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function inline_font_weight_700(): void
    {
        $blocks = $this->parse('<p><span style="font-weight: 700">B</span></p>');
        $run = $blocks[0]->children[0];
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function inline_font_style_italic(): void
    {
        $blocks = $this->parse('<p><span style="font-style: italic">I</span></p>');
        $run = $blocks[0]->children[0];
        self::assertTrue($run->style->italic);
    }

    #[Test]
    public function inline_text_decoration_underline(): void
    {
        $blocks = $this->parse('<p><span style="text-decoration: underline">U</span></p>');
        $run = $blocks[0]->children[0];
        self::assertTrue($run->style->underline);
    }

    #[Test]
    public function inline_text_decoration_line_through(): void
    {
        $blocks = $this->parse('<p><span style="text-decoration: line-through">S</span></p>');
        $run = $blocks[0]->children[0];
        self::assertTrue($run->style->strikethrough);
    }

    #[Test]
    public function inline_background_color(): void
    {
        $blocks = $this->parse('<p><span style="background-color: yellow">Y</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('ffff00', $run->style->backgroundColor);
    }

    #[Test]
    public function inline_font_family_first_choice(): void
    {
        $blocks = $this->parse('<p><span style="font-family: Arial, sans-serif">A</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('Arial', $run->style->fontFamily);
    }

    #[Test]
    public function inline_letter_spacing(): void
    {
        $blocks = $this->parse('<p><span style="letter-spacing: 2pt">S</span></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(2.0, $run->style->letterSpacingPt);
    }

    // ----- Whitespace handling -----

    #[Test]
    public function whitespace_collapsed(): void
    {
        $blocks = $this->parse('<p>Hello    world</p>');
        $run = $blocks[0]->children[0];
        self::assertSame('Hello world', $run->text);
    }

    #[Test]
    public function newlines_in_text_treated_as_whitespace(): void
    {
        $blocks = $this->parse("<p>Line 1\nLine 2</p>");
        $run = $blocks[0]->children[0];
        self::assertSame('Line 1 Line 2', $run->text);
    }

    // ----- Document::fromHtml() integration -----

    #[Test]
    public function fromHtml_creates_document(): void
    {
        $doc = Document::fromHtml('<p>Hello world</p>');
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('(Hello world) Tj', $bytes);
    }

    #[Test]
    public function fromHtml_with_metadata(): void
    {
        $doc = Document::fromHtml(
            '<p>Test</p>',
            metadata: ['Title' => 'HTML Test'],
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('/Title (HTML Test)', $bytes);
    }

    #[Test]
    public function fromHtml_complex_document(): void
    {
        $html = <<<'HTML'
        <h1>Title</h1>
        <p>Intro <b>bold</b> and <i>italic</i>.</p>
        <ul><li>One</li><li>Two</li></ul>
        <table><tr><td>A</td><td>B</td></tr></table>
        HTML;
        $doc = Document::fromHtml($html);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('Title', $bytes);
        // Tagged content includes our text strings (perhaps в TJ arrays).
        self::assertGreaterThan(1000, strlen($bytes));
    }

    #[Test]
    public function rejects_no_html_returns_empty(): void
    {
        $blocks = $this->parse('');
        self::assertSame([], $blocks);
    }

    #[Test]
    public function text_outside_block_wrapped_into_paragraph(): void
    {
        $blocks = $this->parse('Bare text without wrapping');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
    }

    #[Test]
    public function div_treated_as_block(): void
    {
        $blocks = $this->parse('<div>Content</div>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
    }

    #[Test]
    public function unicode_text(): void
    {
        $blocks = $this->parse('<p>Привет мир 🌍</p>');
        $run = $blocks[0]->children[0];
        self::assertStringContainsString('Привет мир', $run->text);
    }
}
