<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\DonutChart;
use Dskripchenko\PhpPdf\Element\ScatterChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DonutScatterChartTest extends TestCase
{
    #[Test]
    public function donut_emits_ring_segments(): void
    {
        $chart = new DonutChart(
            slices: [
                ['label' => 'A', 'value' => 30],
                ['label' => 'B', 'value' => 70],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 ring segments = 2 polygon fills (h + f).
        self::assertSame(2, substr_count($bytes, "\nh\nf\n"));
        // Legend labels.
        self::assertStringContainsString('(A) Tj', $bytes);
        self::assertStringContainsString('(B) Tj', $bytes);
    }

    #[Test]
    public function donut_inner_ratio_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DonutChart(slices: [['label' => 'X', 'value' => 1]], innerRatio: 1.0);
    }

    #[Test]
    public function donut_inner_ratio_negative_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DonutChart(slices: [['label' => 'X', 'value' => 1]], innerRatio: -0.1);
    }

    #[Test]
    public function donut_empty_slices_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DonutChart(slices: []);
    }

    #[Test]
    public function donut_custom_slice_color(): void
    {
        $chart = new DonutChart(
            slices: [
                ['label' => 'Red', 'value' => 1, 'color' => 'ff0000'],
                ['label' => 'Blue', 'value' => 1, 'color' => '0000ff'],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function scatter_emits_marker_dots(): void
    {
        $chart = new ScatterChart(
            series: [
                ['name' => 'A', 'points' => [
                    ['x' => 1.0, 'y' => 2.0],
                    ['x' => 3.0, 'y' => 5.0],
                    ['x' => 5.0, 'y' => 1.0],
                ]],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // ≥3 fillRects для markers + axes + legend.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThanOrEqual(3, $count);
        self::assertStringContainsString('(A) Tj', $bytes);
    }

    #[Test]
    public function scatter_axis_labels_show_min_max(): void
    {
        $chart = new ScatterChart(
            series: [
                ['name' => 'X', 'points' => [
                    ['x' => 10, 'y' => 100],
                    ['x' => 50, 'y' => 500],
                ]],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // X-axis min/max + Y-axis max labels.
        self::assertStringContainsString('(10) Tj', $bytes);
        self::assertStringContainsString('(50) Tj', $bytes);
        self::assertStringContainsString('(500) Tj', $bytes);
    }

    #[Test]
    public function scatter_multi_series_distinct_colors(): void
    {
        $chart = new ScatterChart(
            series: [
                ['name' => 'A', 'color' => 'ff0000',
                 'points' => [['x' => 1, 'y' => 1]]],
                ['name' => 'B', 'color' => '00ff00',
                 'points' => [['x' => 2, 'y' => 2]]],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+1\s+0\s+rg@', $bytes);
    }

    #[Test]
    public function scatter_empty_series_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScatterChart(series: []);
    }

    #[Test]
    public function scatter_empty_points_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScatterChart(series: [['name' => 'X', 'points' => []]]);
    }
}
