<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 233: <table><caption> support — prepended as centered bold paragraph.
 */
final class HtmlTableCaptionTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    #[Test]
    public function table_without_caption(): void
    {
        $blocks = $this->parse('<table><tr><td>A</td></tr></table>');
        // Single block — the table itself.
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Table::class, $blocks[0]);
    }

    #[Test]
    public function table_with_caption(): void
    {
        $blocks = $this->parse(
            '<table>
                <caption>Sales Report</caption>
                <tr><td>Q1</td><td>$1000</td></tr>
            </table>'
        );
        // Caption first, then table.
        self::assertCount(2, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertInstanceOf(Table::class, $blocks[1]);
    }

    #[Test]
    public function caption_is_centered(): void
    {
        $blocks = $this->parse(
            '<table><caption>Title</caption><tr><td>x</td></tr></table>'
        );
        $caption = $blocks[0];
        self::assertSame(Alignment::Center, $caption->style->alignment);
    }

    #[Test]
    public function caption_is_bold(): void
    {
        $blocks = $this->parse(
            '<table><caption>Bold Caption</caption><tr><td>x</td></tr></table>'
        );
        $caption = $blocks[0];
        $run = $caption->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function caption_preserves_inner_styling(): void
    {
        $blocks = $this->parse(
            '<table><caption>Title with <i>emphasis</i></caption><tr><td>x</td></tr></table>'
        );
        $caption = $blocks[0];
        $runs = array_filter($caption->children, fn ($c) => $c instanceof Run);
        // Should have at least one italic run.
        $hasItalic = false;
        foreach ($runs as $r) {
            if ($r->style->italic) {
                $hasItalic = true;
            }
        }
        self::assertTrue($hasItalic);
    }

    #[Test]
    public function table_rows_preserved_alongside_caption(): void
    {
        $blocks = $this->parse(
            '<table>
                <caption>Caption</caption>
                <thead><tr><th>H1</th></tr></thead>
                <tbody><tr><td>D1</td></tr></tbody>
            </table>'
        );
        // Caption + table.
        self::assertCount(2, $blocks);
        $table = $blocks[1];
        self::assertInstanceOf(Table::class, $table);
        // 2 rows from thead + tbody.
        self::assertCount(2, $table->rows);
    }
}
