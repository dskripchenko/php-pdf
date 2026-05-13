<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Стиль таблицы — общая ширина, alignment, default cell padding/borders.
 *
 * Mirror'ит php-docx TableStyle. Differences:
 *  - все размеры в pt
 *  - defaultBorder применяется ко всем cell'ам если у них нет своего
 *  - alignment — table-level (Start/Center/End сдвигает всю таблицу
 *    внутри content area)
 *
 * widthPercent (0..100) — alternative для widthPt: relative к content
 * area. Если оба null — таблица full-width content area.
 */
final readonly class TableStyle
{
    public function __construct(
        public ?float $widthPt = null,
        public ?float $widthPercent = null,
        public Alignment $alignment = Alignment::Start,
        public ?BorderSet $borders = null,
        public ?Border $defaultCellBorder = null,
        public CellStyle $defaultCellStyle = new CellStyle,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 6,
    ) {}

    public function copy(
        ?float $widthPt = null,
        ?float $widthPercent = null,
        ?Alignment $alignment = null,
        ?BorderSet $borders = null,
        ?Border $defaultCellBorder = null,
        ?CellStyle $defaultCellStyle = null,
        ?float $spaceBeforePt = null,
        ?float $spaceAfterPt = null,
    ): self {
        return new self(
            widthPt: $widthPt ?? $this->widthPt,
            widthPercent: $widthPercent ?? $this->widthPercent,
            alignment: $alignment ?? $this->alignment,
            borders: $borders ?? $this->borders,
            defaultCellBorder: $defaultCellBorder ?? $this->defaultCellBorder,
            defaultCellStyle: $defaultCellStyle ?? $this->defaultCellStyle,
            spaceBeforePt: $spaceBeforePt ?? $this->spaceBeforePt,
            spaceAfterPt: $spaceAfterPt ?? $this->spaceAfterPt,
        );
    }
}
