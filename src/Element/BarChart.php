<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 44: Bar chart primitive.
 *
 * Single-series vertical или horizontal bar chart. Rendered native PDF
 * paths (fillRect для bars, strokeLine для axes, showText для labels).
 *
 * Не реализовано:
 *  - Animation / interactivity (PDF-format limitation, out of scope).
 *
 * Closed в later phases:
 *  - Multi-series → Phase 51 (GroupedBarChart), Phase 54 (StackedBarChart)
 *  - Custom axis ranges → Phase 68 (yMin/yMax)
 *  - Grid lines → Phase 64 (showGridLines=true)
 *  - X-axis label rotation → Phase 140 (xLabelRotationDeg)
 *  - LineChart/PieChart → Phase 45
 */
final readonly class BarChart implements BlockElement
{
    /**
     * @param  list<array{label: string, value: float, color?: string}>  $bars
     *   Each entry — bar с label, value (height), optional hex color.
     */
    public function __construct(
        public array $bars,
        public float $widthPt = 400.0,
        public float $heightPt = 200.0,
        public ?string $title = null,
        public bool $horizontal = false,
        public float $axisLabelSizePt = 8.0,
        public float $titleSizePt = 12.0,
        public string $defaultBarColor = '4287f5',
        public bool $showGridLines = false,
        // Phase 68: optional fixed y-axis range. null = auto (max value).
        public ?float $yMin = null,
        public ?float $yMax = null,
        public ?string $xAxisTitle = null,
        public ?string $yAxisTitle = null,
        public float $axisTitleSizePt = 9.0,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 6.0,
        public float $spaceAfterPt = 6.0,
        // Phase 140: x-axis label rotation в degrees. Positive = CCW.
        // Common values: 45 (matplotlib default), -45 (Excel-style), 90.
        // End-anchor convention: label's natural right-edge lands at tick position.
        public float $xLabelRotationDeg = 0.0,
    ) {
        if ($bars === []) {
            throw new \InvalidArgumentException('BarChart requires at least one bar');
        }
        foreach ($bars as $bar) {
            if (! isset($bar['label']) || ! isset($bar['value'])) {
                throw new \InvalidArgumentException('BarChart bar must have label and value');
            }
        }
    }
}
