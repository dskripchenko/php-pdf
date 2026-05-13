<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\GroupedBarChart;
use Dskripchenko\PhpPdf\Element\MultiLineChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultiSeriesChartsTest extends TestCase
{
    #[Test]
    public function grouped_bar_chart_emits_n_bars_per_label(): void
    {
        $chart = new GroupedBarChart(
            bars: [
                ['label' => 'Q1', 'values' => [100, 80, 60]],
                ['label' => 'Q2', 'values' => [200, 150, 120]],
            ],
            seriesNames: ['Sales', 'Costs', 'Profit'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 labels × 3 series = 6 bars expected (at minimum).
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThanOrEqual(6, $count);
        // Labels rendered.
        self::assertStringContainsString('(Q1) Tj', $bytes);
        self::assertStringContainsString('(Q2) Tj', $bytes);
    }

    #[Test]
    public function grouped_legend_shows_series_names(): void
    {
        $chart = new GroupedBarChart(
            bars: [['label' => 'A', 'values' => [1, 2]]],
            seriesNames: ['Apple', 'Banana'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Apple) Tj', $bytes);
        self::assertStringContainsString('(Banana) Tj', $bytes);
    }

    #[Test]
    public function grouped_custom_series_colors(): void
    {
        $chart = new GroupedBarChart(
            bars: [['label' => 'A', 'values' => [1, 1]]],
            seriesNames: ['Red', 'Blue'],
            seriesColors: ['ff0000', '0000ff'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function grouped_value_count_mismatch_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GroupedBarChart(
            bars: [['label' => 'A', 'values' => [1, 2, 3]]],
            seriesNames: ['X', 'Y'], // только 2 series, но 3 values.
        );
    }

    #[Test]
    public function multiline_chart_emits_n_polylines(): void
    {
        $chart = new MultiLineChart(
            xLabels: ['Jan', 'Feb', 'Mar'],
            series: [
                ['name' => 'Sales', 'values' => [100, 200, 175]],
                ['name' => 'Costs', 'values' => [80, 150, 120]],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 polylines + 2 axes lines + legend = many strokes.
        $sCount = preg_match_all('@\nS\n@', $bytes);
        self::assertGreaterThanOrEqual(4, $sCount);
        // Series names в legend.
        self::assertStringContainsString('(Sales) Tj', $bytes);
        self::assertStringContainsString('(Costs) Tj', $bytes);
        // X-axis labels.
        self::assertStringContainsString('(Jan) Tj', $bytes);
        self::assertStringContainsString('(Feb) Tj', $bytes);
        self::assertStringContainsString('(Mar) Tj', $bytes);
    }

    #[Test]
    public function multiline_value_count_mismatch_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiLineChart(
            xLabels: ['A', 'B', 'C'],
            series: [['name' => 'X', 'values' => [1, 2]]], // только 2 vs 3 labels.
        );
    }

    #[Test]
    public function multiline_at_least_two_x_labels_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiLineChart(
            xLabels: ['Only'],
            series: [['name' => 'X', 'values' => [1]]],
        );
    }

    #[Test]
    public function multiline_at_least_one_series_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiLineChart(xLabels: ['A', 'B'], series: []);
    }

    #[Test]
    public function multiline_markers_emit_extra_fills(): void
    {
        $with = new MultiLineChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [1, 2]]],
            showMarkers: true,
        );
        $without = new MultiLineChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [1, 2]]],
            showMarkers: false,
        );
        $b1 = (new Document(new Section([$with])))->toBytes(new Engine(compressStreams: false));
        $b2 = (new Document(new Section([$without])))->toBytes(new Engine(compressStreams: false));

        self::assertGreaterThan(
            preg_match_all('@^f$@m', $b2),
            preg_match_all('@^f$@m', $b1),
        );
    }
}
