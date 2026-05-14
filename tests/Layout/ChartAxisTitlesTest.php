<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\GroupedBarChart;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\MultiLineChart;
use Dskripchenko\PhpPdf\Element\ScatterChart;
use Dskripchenko\PhpPdf\Element\StackedBarChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChartAxisTitlesTest extends TestCase
{
    #[Test]
    public function bar_chart_x_axis_title_rendered(): void
    {
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 1]],
            xAxisTitle: 'Quarter',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Quarter) Tj', $bytes);
    }

    #[Test]
    public function bar_chart_y_axis_title_rotated(): void
    {
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 1]],
            yAxisTitle: 'Revenue',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Rotated text использует Tm matrix.
        self::assertStringContainsString('(Revenue) Tj', $bytes);
        self::assertStringContainsString(' Tm', $bytes);
    }

    #[Test]
    public function line_chart_axis_titles(): void
    {
        $chart = new LineChart(
            points: [['label' => 'A', 'value' => 1], ['label' => 'B', 'value' => 2]],
            xAxisTitle: 'Month',
            yAxisTitle: 'Sales',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Month) Tj', $bytes);
        self::assertStringContainsString('(Sales) Tj', $bytes);
    }

    #[Test]
    public function area_chart_axis_titles(): void
    {
        $chart = new AreaChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [1, 2]]],
            xAxisTitle: 'Time',
            yAxisTitle: 'Volume',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Time) Tj', $bytes);
        self::assertStringContainsString('(Volume) Tj', $bytes);
    }

    #[Test]
    public function null_titles_skip_rendering(): void
    {
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 1]],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // No rotated text matrix (rotated only когда y title set).
        self::assertStringNotContainsString(' Tm', $bytes);
    }

    // -------- Phase 142: axis titles на остальных charts --------

    #[Test]
    public function grouped_bar_chart_axis_titles(): void
    {
        $chart = new GroupedBarChart(
            bars: [['label' => 'Q1', 'values' => [10, 20]]],
            seriesNames: ['A', 'B'],
            xAxisTitle: 'Period',
            yAxisTitle: 'Amount',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Period) Tj', $bytes);
        self::assertStringContainsString('(Amount) Tj', $bytes);
    }

    #[Test]
    public function stacked_bar_chart_axis_titles(): void
    {
        $chart = new StackedBarChart(
            bars: [['label' => 'R1', 'values' => [10, 20]]],
            seriesNames: ['A', 'B'],
            xAxisTitle: 'Region',
            yAxisTitle: 'Sales',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Region) Tj', $bytes);
        self::assertStringContainsString('(Sales) Tj', $bytes);
    }

    #[Test]
    public function multi_line_chart_axis_titles(): void
    {
        $chart = new MultiLineChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [1, 2]]],
            xAxisTitle: 'Quarter',
            yAxisTitle: 'Revenue',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Quarter) Tj', $bytes);
        self::assertStringContainsString('(Revenue) Tj', $bytes);
    }

    #[Test]
    public function scatter_chart_axis_titles(): void
    {
        $chart = new ScatterChart(
            series: [['name' => 'S', 'points' => [['x' => 1.0, 'y' => 2.0], ['x' => 3.0, 'y' => 4.0]]]],
            xAxisTitle: 'Weight',
            yAxisTitle: 'Height',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Weight) Tj', $bytes);
        self::assertStringContainsString('(Height) Tj', $bytes);
    }
}
