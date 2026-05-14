<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\GroupedBarChart;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\MultiLineChart;
use Dskripchenko\PhpPdf\Element\StackedBarChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 140: chart x-axis label rotation.
 */
final class ChartLabelRotationTest extends TestCase
{
    #[Test]
    public function bar_chart_default_rotation_is_zero(): void
    {
        $chart = new BarChart(bars: [['label' => 'Q1', 'value' => 10]]);
        self::assertSame(0.0, $chart->xLabelRotationDeg);
    }

    #[Test]
    public function bar_chart_rotated_labels_emit_tm_matrix(): void
    {
        $chart = new BarChart(
            bars: [['label' => 'January', 'value' => 10]],
            xLabelRotationDeg: 45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Rotated text uses Tm matrix via drawWatermark; label content shows in PDF.
        self::assertStringContainsString('(January) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function bar_chart_zero_rotation_skips_tm_matrix_for_labels(): void
    {
        // Without rotation и без yAxisTitle, no Tm matrix appears in output.
        $chart = new BarChart(
            bars: [['label' => 'January', 'value' => 10]],
            xLabelRotationDeg: 0.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(January) Tj', $bytes);
        self::assertStringNotContainsString(' Tm', $bytes);
    }

    #[Test]
    public function bar_chart_negative_rotation_emits_tm_matrix(): void
    {
        // Excel-style -45° (clockwise rotation, text down-right).
        $chart = new BarChart(
            bars: [['label' => 'Foo', 'value' => 5]],
            xLabelRotationDeg: -45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Foo) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function bar_chart_ninety_degrees_vertical(): void
    {
        // 90° = labels stacked vertically.
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 1], ['label' => 'B', 'value' => 2]],
            xLabelRotationDeg: 90.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(A) Tj', $bytes);
        self::assertStringContainsString('(B) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function rotated_labels_for_multiple_bars(): void
    {
        $chart = new BarChart(
            bars: [
                ['label' => 'Jan', 'value' => 10],
                ['label' => 'Feb', 'value' => 15],
                ['label' => 'Mar', 'value' => 12],
            ],
            xLabelRotationDeg: 45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // All three labels rendered.
        self::assertStringContainsString('(Jan) Tj', $bytes);
        self::assertStringContainsString('(Feb) Tj', $bytes);
        self::assertStringContainsString('(Mar) Tj', $bytes);
        // Each rotated label emits its own Tm — count matrices >= 3.
        $tmCount = substr_count($bytes, ' Tm');
        self::assertGreaterThanOrEqual(3, $tmCount);
    }

    // -------- Phase 141: rotation на остальные charts --------

    #[Test]
    public function line_chart_rotated_labels(): void
    {
        $chart = new LineChart(
            points: [['label' => 'AlphaX', 'value' => 1], ['label' => 'BetaY', 'value' => 2]],
            xLabelRotationDeg: 45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(AlphaX) Tj', $bytes);
        self::assertStringContainsString('(BetaY) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function area_chart_rotated_labels(): void
    {
        $chart = new AreaChart(
            xLabels: ['Mon', 'Tue', 'Wed'],
            series: [['name' => 'S1', 'values' => [1, 2, 3]]],
            xLabelRotationDeg: 30.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Mon) Tj', $bytes);
        self::assertStringContainsString('(Tue) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function multi_line_chart_rotated_labels(): void
    {
        $chart = new MultiLineChart(
            xLabels: ['Apr', 'May'],
            series: [['name' => 'X', 'values' => [10, 20]]],
            xLabelRotationDeg: 60.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Apr) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function grouped_bar_chart_rotated_labels(): void
    {
        $chart = new GroupedBarChart(
            bars: [
                ['label' => 'Quarter1', 'values' => [10, 20]],
                ['label' => 'Quarter2', 'values' => [15, 25]],
            ],
            seriesNames: ['Sales', 'Costs'],
            xLabelRotationDeg: 45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Quarter1) Tj', $bytes);
        self::assertStringContainsString('(Quarter2) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function stacked_bar_chart_rotated_labels(): void
    {
        $chart = new StackedBarChart(
            bars: [
                ['label' => 'Region-A', 'values' => [10, 20]],
                ['label' => 'Region-B', 'values' => [15, 25]],
            ],
            seriesNames: ['North', 'South'],
            xLabelRotationDeg: -45.0,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Region-A) Tj', $bytes);
        self::assertStringContainsString('(Region-B) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function all_charts_default_rotation_zero(): void
    {
        self::assertSame(0.0, (new LineChart(points: [['label' => 'A', 'value' => 1], ['label' => 'B', 'value' => 2]]))->xLabelRotationDeg);
        self::assertSame(0.0, (new AreaChart(xLabels: ['A', 'B'], series: [['name' => 'X', 'values' => [1, 2]]]))->xLabelRotationDeg);
        self::assertSame(0.0, (new MultiLineChart(xLabels: ['A', 'B'], series: [['name' => 'X', 'values' => [1, 2]]]))->xLabelRotationDeg);
        self::assertSame(0.0, (new GroupedBarChart(bars: [['label' => 'A', 'values' => [1]]], seriesNames: ['X']))->xLabelRotationDeg);
        self::assertSame(0.0, (new StackedBarChart(bars: [['label' => 'A', 'values' => [1]]], seriesNames: ['X']))->xLabelRotationDeg);
    }
}
