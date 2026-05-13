<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Build;

use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphBuilderTest extends TestCase
{
    #[Test]
    public function empty_builder_produces_empty_paragraph(): void
    {
        $p = ParagraphBuilder::new()->build();
        self::assertSame([], $p->children);
        self::assertNull($p->headingLevel);
    }

    #[Test]
    public function text_adds_run_with_default_style(): void
    {
        $p = ParagraphBuilder::new()
            ->text('hello')
            ->build();

        self::assertCount(1, $p->children);
        $run = $p->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('hello', $run->text);
        self::assertTrue($run->style->isEmpty());
    }

    #[Test]
    public function text_accepts_explicit_run_style(): void
    {
        $p = ParagraphBuilder::new()
            ->text('coloured', (new RunStyle)->withColor('cc0000'))
            ->build();

        self::assertSame('cc0000', $p->children[0]->style->color);
    }

    #[Test]
    public function inline_style_helpers_set_flags(): void
    {
        $p = ParagraphBuilder::new()
            ->bold('B')
            ->italic('I')
            ->underline('U')
            ->strikethrough('S')
            ->superscript('Sup')
            ->subscript('Sub')
            ->build();

        self::assertTrue($p->children[0]->style->bold);
        self::assertTrue($p->children[1]->style->italic);
        self::assertTrue($p->children[2]->style->underline);
        self::assertTrue($p->children[3]->style->strikethrough);
        self::assertTrue($p->children[4]->style->superscript);
        self::assertTrue($p->children[5]->style->subscript);
    }

    #[Test]
    public function styled_run_uses_run_style_builder(): void
    {
        $p = ParagraphBuilder::new()
            ->styled('alert', fn($s) => $s
                ->bold()
                ->italic()
                ->color('cc0000')
                ->size(14)
                ->highlight('yellow')
            )
            ->build();

        $style = $p->children[0]->style;
        self::assertTrue($style->bold);
        self::assertTrue($style->italic);
        self::assertSame('cc0000', $style->color);
        self::assertSame(14.0, $style->sizePt);
        self::assertSame('yellow', $style->highlight);
    }

    #[Test]
    public function line_break_adds_linebreak_node(): void
    {
        $p = ParagraphBuilder::new()
            ->text('line 1')
            ->lineBreak()
            ->text('line 2')
            ->build();

        self::assertCount(3, $p->children);
        self::assertInstanceOf(LineBreak::class, $p->children[1]);
    }

    #[Test]
    public function field_methods_add_field_nodes(): void
    {
        $p = ParagraphBuilder::new()
            ->pageNumber()
            ->totalPages()
            ->currentDate('yyyy-MM-dd')
            ->currentTime()
            ->mergeField('CustomerID')
            ->build();

        $kinds = array_map(fn($c) => $c instanceof Field ? $c->kind() : null, $p->children);
        self::assertSame([
            Field::PAGE,
            Field::NUMPAGES,
            Field::DATE,
            Field::TIME,
            Field::MERGEFIELD,
        ], $kinds);

        self::assertSame('yyyy-MM-dd', $p->children[2]->format());
        self::assertSame('CustomerID', $p->children[4]->format());
    }

    #[Test]
    public function alignment_helpers(): void
    {
        self::assertSame(Alignment::Center, ParagraphBuilder::new()->alignCenter()->build()->style->alignment);
        self::assertSame(Alignment::End, ParagraphBuilder::new()->alignRight()->build()->style->alignment);
        self::assertSame(Alignment::Both, ParagraphBuilder::new()->alignJustify()->build()->style->alignment);
        self::assertSame(Alignment::Distribute,
            ParagraphBuilder::new()->align(Alignment::Distribute)->build()->style->alignment);
    }

    #[Test]
    public function spacing_helpers(): void
    {
        $p = ParagraphBuilder::new()
            ->spaceBefore(12)
            ->spaceAfter(6)
            ->build();
        self::assertSame(12.0, $p->style->spaceBeforePt);
        self::assertSame(6.0, $p->style->spaceAfterPt);

        $q = ParagraphBuilder::new()->spacing(10, 20)->build();
        self::assertSame(10.0, $q->style->spaceBeforePt);
        self::assertSame(20.0, $q->style->spaceAfterPt);
    }

    #[Test]
    public function indent_helper(): void
    {
        $p = ParagraphBuilder::new()
            ->indent(left: 36, right: 18, firstLine: 24)
            ->build();
        self::assertSame(36.0, $p->style->indentLeftPt);
        self::assertSame(18.0, $p->style->indentRightPt);
        self::assertSame(24.0, $p->style->indentFirstLinePt);
    }

    #[Test]
    public function line_height_helper(): void
    {
        $p = ParagraphBuilder::new()->lineHeight(1.5)->build();
        self::assertSame(1.5, $p->style->lineHeightMult);
    }

    #[Test]
    public function page_break_before_flag(): void
    {
        $p = ParagraphBuilder::new()->pageBreakBefore()->build();
        self::assertTrue($p->style->pageBreakBefore);
    }

    #[Test]
    public function borders_helper(): void
    {
        $borders = BorderSet::all(new Border(BorderStyle::Single));
        $p = ParagraphBuilder::new()->borders($borders)->build();
        self::assertSame($borders, $p->style->borders);
    }

    #[Test]
    public function heading_level_set(): void
    {
        $p = ParagraphBuilder::new()->heading(3)->text('h3')->build();
        self::assertSame(3, $p->headingLevel);
    }

    #[Test]
    public function invalid_heading_level_in_builder_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ParagraphBuilder::new()->heading(0);
    }

    #[Test]
    public function default_run_style_propagates_to_paragraph(): void
    {
        $default = (new RunStyle)->withSizePt(13)->withColor('333333');
        $p = ParagraphBuilder::new()
            ->defaultRunStyle($default)
            ->text('inherit me')
            ->build();
        self::assertSame($default, $p->defaultRunStyle);
    }
}
