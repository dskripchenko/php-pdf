<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 45: Pie chart primitive.
 *
 * Sectors rendered как polygon approximation (N segments per slice).
 * Default 60 chord segments per slice — visually смотрится gladkim.
 *
 * Не реализовано:
 *  - True Bezier arc rendering — v1.3 backlog.
 *  - Exploded slices — v1.3 backlog.
 *  - Slice labels по периметру — v1.3 backlog.
 *  - 3D effects — out of scope (PDF static format).
 *
 * Closed в later phases:
 *  - Donut variant → Phase 55 (DonutChart)
 */
final readonly class PieChart implements BlockElement
{
    /**
     * @param  list<array{label: string, value: float, color?: string}>  $slices
     */
    public function __construct(
        public array $slices,
        public float $sizePt = 200.0,
        public ?string $title = null,
        public float $titleSizePt = 12.0,
        public float $legendSizePt = 8.0,
        public bool $showLegend = true,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 6.0,
        public float $spaceAfterPt = 6.0,
    ) {
        if ($slices === []) {
            throw new \InvalidArgumentException('PieChart requires at least one slice');
        }
        foreach ($slices as $slice) {
            if (! isset($slice['label']) || ! isset($slice['value'])) {
                throw new \InvalidArgumentException('PieChart slice must have label and value');
            }
            if ($slice['value'] < 0) {
                throw new \InvalidArgumentException('PieChart slice value must be non-negative');
            }
        }
    }
}
