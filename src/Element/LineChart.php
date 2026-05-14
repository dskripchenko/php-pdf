<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 45: Line chart primitive.
 *
 * Single-series line through (label, value) data points. Rendered native
 * PDF paths: strokeLine для axes, m/l sequence для polyline; optional
 * filled circles (~2pt radius) at data points.
 *
 * Не реализовано:
 *  - X-axis numeric scale (currently labels evenly spaced).
 *
 * Closed в later phases:
 *  - Multi-series → Phase 51 (MultiLineChart)
 *  - Spline interpolation → Phase 98 (smoothed=true)
 *  - Y-axis custom range → Phase 68 (yMin/yMax)
 *  - Grid lines → Phase 64 (showGridLines=true)
 */
final readonly class LineChart implements BlockElement
{
    /**
     * @param  list<array{label: string, value: float, color?: string}>  $points
     */
    public function __construct(
        public array $points,
        public float $widthPt = 400.0,
        public float $heightPt = 200.0,
        public ?string $title = null,
        public float $axisLabelSizePt = 8.0,
        public float $titleSizePt = 12.0,
        public string $lineColor = '4287f5',
        public bool $showMarkers = true,
        public bool $showGridLines = false,
        public bool $smoothed = false,
        public ?float $yMin = null,
        public ?float $yMax = null,
        public ?string $xAxisTitle = null,
        public ?string $yAxisTitle = null,
        public float $axisTitleSizePt = 9.0,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 6.0,
        public float $spaceAfterPt = 6.0,
        public float $xLabelRotationDeg = 0.0,
    ) {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('LineChart requires at least 2 points');
        }
    }
}
