<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Стиль cell'а — padding, borders, background, vAlign + cell-level
 * width override.
 *
 * Mirror'ит php-docx CellStyle. Differences:
 *  - все размеры в pt (php-docx — twips)
 *  - widthPercent остаётся опциональным (0..100)
 *  - paddingTop/Right/Bottom/Left имеют default 2pt (visual breathing room)
 *
 * Все поля nullable / имеют defaults — позволяет inheritance через
 * TableStyle::defaultCellStyle.
 */
final readonly class CellStyle
{
    public function __construct(
        public ?float $widthPt = null,
        public ?float $widthPercent = null,
        public float $paddingTopPt = 2,
        public float $paddingRightPt = 4,
        public float $paddingBottomPt = 2,
        public float $paddingLeftPt = 4,
        public VerticalAlignment $verticalAlign = VerticalAlignment::Top,
        public ?string $backgroundColor = null,
        public ?BorderSet $borders = null,
    ) {}

    public function withPadding(float $pt): self
    {
        return $this->copy(
            paddingTopPt: $pt,
            paddingRightPt: $pt,
            paddingBottomPt: $pt,
            paddingLeftPt: $pt,
        );
    }

    public function withBackgroundColor(string $hex): self
    {
        return $this->copy(backgroundColor: strtolower(ltrim($hex, '#')));
    }

    public function withBorders(BorderSet $borders): self
    {
        return $this->copy(borders: $borders);
    }

    public function withVerticalAlign(VerticalAlignment $v): self
    {
        return $this->copy(verticalAlign: $v);
    }

    public function withWidthPt(float $pt): self
    {
        return $this->copy(widthPt: $pt);
    }

    public function copy(
        ?float $widthPt = null,
        ?float $widthPercent = null,
        ?float $paddingTopPt = null,
        ?float $paddingRightPt = null,
        ?float $paddingBottomPt = null,
        ?float $paddingLeftPt = null,
        ?VerticalAlignment $verticalAlign = null,
        ?string $backgroundColor = null,
        ?BorderSet $borders = null,
    ): self {
        return new self(
            widthPt: $widthPt ?? $this->widthPt,
            widthPercent: $widthPercent ?? $this->widthPercent,
            paddingTopPt: $paddingTopPt ?? $this->paddingTopPt,
            paddingRightPt: $paddingRightPt ?? $this->paddingRightPt,
            paddingBottomPt: $paddingBottomPt ?? $this->paddingBottomPt,
            paddingLeftPt: $paddingLeftPt ?? $this->paddingLeftPt,
            verticalAlign: $verticalAlign ?? $this->verticalAlign,
            backgroundColor: $backgroundColor ?? $this->backgroundColor,
            borders: $borders ?? $this->borders,
        );
    }
}
