<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 45: Pie chart primitive.
 *
 * Phase 166: sectors rendered как cubic Bezier arcs (true curve, не polygon).
 * Phase 167: optional exploded slices (radial offset для emphasis).
 *
 * Не реализовано:
 *  - Slice labels по периметру — v1.3 backlog.
 *  - 3D effects — out of scope (PDF static format).
 *
 * Closed в later phases:
 *  - Donut variant → Phase 55 (DonutChart)
 *  - True Bezier arc rendering → Phase 166
 *  - Exploded slices → Phase 167
 */
final readonly class PieChart implements BlockElement
{
    /**
     * @param  list<array{label: string, value: float, color?: string, explode?: bool|float}>  $slices
     *   slice.explode: true → standard ~8% radius offset; float → custom offset
     *   как fraction of radius (0.05 = 5%, 0.2 = 20%).
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
        // Phase 168: показывать labels на perimeter с leader-lines (вместо
        // или в дополнение к sidebar legend). Минимальный угол для label —
        // small slices < $minLabelAngleDeg skip'ятся (избегаем overlap).
        public bool $showPerimeterLabels = false,
        public float $perimeterLabelSizePt = 8.0,
        public float $minLabelAngleDeg = 8.0,
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
