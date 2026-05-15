<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 230: HTML legacy tags (<center>, <font>) — common in legacy
 * HTML / mailers.
 */
final class HtmlLegacyTagsTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    // ---- <center> ----

    #[Test]
    public function center_tag_creates_centered_paragraph(): void
    {
        $blocks = $this->parse('<center>Centered text</center>');
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame(Alignment::Center, $blocks[0]->style->alignment);
    }

    #[Test]
    public function center_tag_preserves_inline_content(): void
    {
        $blocks = $this->parse('<center><b>Bold</b> and <i>italic</i></center>');
        $p = $blocks[0];
        // Find bold + italic runs.
        $bold = false;
        $italic = false;
        foreach ($p->children as $r) {
            if ($r instanceof Run) {
                if ($r->style->bold) {
                    $bold = true;
                }
                if ($r->style->italic) {
                    $italic = true;
                }
            }
        }
        self::assertTrue($bold);
        self::assertTrue($italic);
    }

    // ---- <font> ----

    #[Test]
    public function font_color(): void
    {
        $blocks = $this->parse('<p><font color="red">red text</font></p>');
        $run = $blocks[0]->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('ff0000', $run->style->color);
    }

    #[Test]
    public function font_face(): void
    {
        $blocks = $this->parse('<p><font face="Times">serif</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('Times', $run->style->fontFamily);
    }

    #[Test]
    public function font_size_attribute_mapping(): void
    {
        // Size 5 → 18pt
        $blocks = $this->parse('<p><font size="5">large</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(18.0, $run->style->sizePt);
    }

    #[Test]
    public function font_size_minimum(): void
    {
        $blocks = $this->parse('<p><font size="1">tiny</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(8.0, $run->style->sizePt);
    }

    #[Test]
    public function font_size_maximum(): void
    {
        $blocks = $this->parse('<p><font size="7">huge</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame(36.0, $run->style->sizePt);
    }

    #[Test]
    public function font_combined_attributes(): void
    {
        $blocks = $this->parse('<p><font color="#0000ff" face="Arial" size="4">styled</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('0000ff', $run->style->color);
        self::assertSame('Arial', $run->style->fontFamily);
        self::assertSame(14.0, $run->style->sizePt);
    }

    #[Test]
    public function font_face_first_choice_from_list(): void
    {
        $blocks = $this->parse('<p><font face="Arial, Helvetica, sans-serif">multi</font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('Arial', $run->style->fontFamily);
    }

    #[Test]
    public function font_nesting_inherits_outer(): void
    {
        // Outer red + inner bold — both apply.
        $blocks = $this->parse('<p><font color="red"><b>bold red</b></font></p>');
        $run = $blocks[0]->children[0];
        self::assertSame('ff0000', $run->style->color);
        self::assertTrue($run->style->bold);
    }
}
