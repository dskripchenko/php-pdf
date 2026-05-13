<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Build;

use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Fluent помощник для построения RunStyle.
 *
 * Используется через `ParagraphBuilder::styled()` где callback получает
 * RunStyleBuilder для конфигурации произвольного inline-стиля.
 *
 * Mirror'ит php-docx RunStyleBuilder для API-симметрии.
 */
final class RunStyleBuilder
{
    private RunStyle $style;

    public function __construct(?RunStyle $initial = null)
    {
        $this->style = $initial ?? new RunStyle;
    }

    public function size(float $pt): self
    {
        $this->style = $this->style->withSizePt($pt);

        return $this;
    }

    public function color(string $hex): self
    {
        $this->style = $this->style->withColor($hex);

        return $this;
    }

    public function background(string $hex): self
    {
        $this->style = new RunStyle(
            sizePt: $this->style->sizePt,
            color: $this->style->color,
            backgroundColor: strtolower(ltrim($hex, '#')),
            fontFamily: $this->style->fontFamily,
            bold: $this->style->bold,
            italic: $this->style->italic,
            underline: $this->style->underline,
            strikethrough: $this->style->strikethrough,
            superscript: $this->style->superscript,
            subscript: $this->style->subscript,
            highlight: $this->style->highlight,
        );

        return $this;
    }

    public function font(string $family): self
    {
        $this->style = $this->style->withFontFamily($family);

        return $this;
    }

    public function bold(bool $value = true): self
    {
        $this->style = $this->style->withBold($value);

        return $this;
    }

    public function italic(bool $value = true): self
    {
        $this->style = $this->style->withItalic($value);

        return $this;
    }

    public function underline(bool $value = true): self
    {
        $this->style = $this->style->withUnderline($value);

        return $this;
    }

    public function strikethrough(bool $value = true): self
    {
        $this->style = $this->style->withStrikethrough($value);

        return $this;
    }

    public function superscript(bool $value = true): self
    {
        $this->style = $this->style->withSuperscript($value);

        return $this;
    }

    public function subscript(bool $value = true): self
    {
        $this->style = $this->style->withSubscript($value);

        return $this;
    }

    public function highlight(string $name): self
    {
        $this->style = $this->style->withHighlight($name);

        return $this;
    }

    public function build(): RunStyle
    {
        return $this->style;
    }
}
