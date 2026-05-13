<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Build;

use Closure;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\InlineElement;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Fluent builder для Paragraph'а.
 *
 * Mirror'ит php-docx ParagraphBuilder для API-симметрии. Используется:
 *  - напрямую: `ParagraphBuilder::new()->text('Hi')->bold('world')->build()`
 *  - через callback в DocumentBuilder: `->paragraph(fn($p) => $p->text(...))`
 *
 * Inline-методы (text/bold/etc.) добавляют InlineElement в children;
 * paragraph-level методы (align/spacing/indent/etc.) обновляют $style.
 */
final class ParagraphBuilder
{
    /** @var list<InlineElement> */
    private array $children = [];

    private ParagraphStyle $style;

    private ?int $headingLevel = null;

    private RunStyle $defaultRunStyle;

    public function __construct()
    {
        $this->style = new ParagraphStyle;
        $this->defaultRunStyle = new RunStyle;
    }

    public static function new(): self
    {
        return new self;
    }

    // ── Inline content ────────────────────────────────────────────

    public function text(string $text, ?RunStyle $style = null): self
    {
        $this->children[] = new Run($text, $style ?? new RunStyle);

        return $this;
    }

    public function bold(string $text): self
    {
        return $this->text($text, (new RunStyle)->withBold());
    }

    public function italic(string $text): self
    {
        return $this->text($text, (new RunStyle)->withItalic());
    }

    public function underline(string $text): self
    {
        return $this->text($text, (new RunStyle)->withUnderline());
    }

    public function strikethrough(string $text): self
    {
        return $this->text($text, (new RunStyle)->withStrikethrough());
    }

    public function superscript(string $text): self
    {
        return $this->text($text, (new RunStyle)->withSuperscript());
    }

    public function subscript(string $text): self
    {
        return $this->text($text, (new RunStyle)->withSubscript());
    }

    /**
     * Стилизованный run через callback с RunStyleBuilder.
     *
     * Example: ->styled('alert', fn($s) => $s->bold()->color('cc0000'))
     */
    public function styled(string $text, Closure $configure): self
    {
        $b = new RunStyleBuilder;
        $configure($b);

        return $this->text($text, $b->build());
    }

    public function lineBreak(): self
    {
        $this->children[] = new LineBreak;

        return $this;
    }

    // ── Field placeholders ────────────────────────────────────────

    public function pageNumber(?RunStyle $style = null): self
    {
        $this->children[] = Field::page($style ?? new RunStyle);

        return $this;
    }

    public function totalPages(?RunStyle $style = null): self
    {
        $this->children[] = Field::totalPages($style ?? new RunStyle);

        return $this;
    }

    public function currentDate(string $format = 'dd.MM.yyyy', ?RunStyle $style = null): self
    {
        $this->children[] = Field::date($format, $style ?? new RunStyle);

        return $this;
    }

    public function currentTime(string $format = 'HH:mm', ?RunStyle $style = null): self
    {
        $this->children[] = Field::time($format, $style ?? new RunStyle);

        return $this;
    }

    public function mergeField(string $name, ?RunStyle $style = null): self
    {
        $this->children[] = Field::mergeField($name, $style ?? new RunStyle);

        return $this;
    }

    // ── Direct AST add ────────────────────────────────────────────

    public function inline(InlineElement $element): self
    {
        $this->children[] = $element;

        return $this;
    }

    // ── Paragraph-level style ─────────────────────────────────────

    public function align(Alignment $alignment): self
    {
        $this->style = $this->style->copy(alignment: $alignment);

        return $this;
    }

    public function alignCenter(): self
    {
        return $this->align(Alignment::Center);
    }

    public function alignRight(): self
    {
        return $this->align(Alignment::End);
    }

    public function alignJustify(): self
    {
        return $this->align(Alignment::Both);
    }

    public function spaceBefore(float $pt): self
    {
        $this->style = $this->style->copy(spaceBeforePt: $pt);

        return $this;
    }

    public function spaceAfter(float $pt): self
    {
        $this->style = $this->style->copy(spaceAfterPt: $pt);

        return $this;
    }

    public function spacing(?float $before = null, ?float $after = null): self
    {
        $this->style = $this->style->copy(
            spaceBeforePt: $before,
            spaceAfterPt: $after,
        );

        return $this;
    }

    public function indent(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        $this->style = $this->style->copy(
            indentLeftPt: $left,
            indentRightPt: $right,
            indentFirstLinePt: $firstLine,
        );

        return $this;
    }

    public function lineHeight(float $mult): self
    {
        $this->style = $this->style->copy(lineHeightMult: $mult);

        return $this;
    }

    public function pageBreakBefore(bool $value = true): self
    {
        $this->style = $this->style->copy(pageBreakBefore: $value);

        return $this;
    }

    public function borders(BorderSet $borders): self
    {
        $this->style = $this->style->copy(borders: $borders);

        return $this;
    }

    public function style(ParagraphStyle $style): self
    {
        $this->style = $style;

        return $this;
    }

    public function heading(int $level): self
    {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException("Heading level must be 1..6, got $level.");
        }
        $this->headingLevel = $level;

        return $this;
    }

    public function defaultRunStyle(RunStyle $style): self
    {
        $this->defaultRunStyle = $style;

        return $this;
    }

    public function build(): Paragraph
    {
        return new Paragraph(
            children: $this->children,
            style: $this->style,
            headingLevel: $this->headingLevel,
            defaultRunStyle: $this->defaultRunStyle,
        );
    }
}
