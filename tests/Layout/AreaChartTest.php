<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AreaChartTest extends TestCase
{
    #[Test]
    public function single_series_area_fills_polygon(): void
    {
        $chart = new AreaChart(
            xLabels: ['Jan', 'Feb', 'Mar'],
            series: [['name' => 'Sales', 'values' => [100, 200, 150]]],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Polygon fill (h + f) для area.
        self::assertMatchesRegularExpression('@\nh\nf\n@', $bytes);
        self::assertStringContainsString('(Sales) Tj', $bytes);
        // X-axis labels.
        self::assertStringContainsString('(Jan) Tj', $bytes);
    }

    #[Test]
    public function stacked_area_max_uses_column_sums(): void
    {
        // Stacked column sums: max(30+20, 40+30, 25+15) = 70.
        $chart = new AreaChart(
            xLabels: ['A', 'B', 'C'],
            series: [
                ['name' => 'X', 'values' => [30, 40, 25]],
                ['name' => 'Y', 'values' => [20, 30, 15]],
            ],
            stacked: true,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(70) Tj', $bytes);
    }

    #[Test]
    public function unstacked_area_max_uses_single_value(): void
    {
        // Without stacking, max = max(any value) = 100.
        $chart = new AreaChart(
            xLabels: ['A', 'B'],
            series: [
                ['name' => 'X', 'values' => [50, 100]],
                ['name' => 'Y', 'values' => [30, 80]],
            ],
            stacked: false,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(100) Tj', $bytes);
    }

    #[Test]
    public function custom_colors(): void
    {
        $chart = new AreaChart(
            xLabels: ['A', 'B'],
            series: [
                ['name' => 'Red', 'color' => 'ff0000', 'values' => [1, 2]],
                ['name' => 'Blue', 'color' => '0000ff', 'values' => [3, 4]],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function multi_series_n_polygons(): void
    {
        $chart = new AreaChart(
            xLabels: ['A', 'B', 'C'],
            series: [
                ['name' => 'S1', 'values' => [1, 2, 3]],
                ['name' => 'S2', 'values' => [2, 3, 4]],
                ['name' => 'S3', 'values' => [3, 4, 5]],
            ],
            stacked: true,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 3 series → 3 polygon fills.
        $count = substr_count($bytes, "\nh\nf\n");
        self::assertGreaterThanOrEqual(3, $count);
    }

    #[Test]
    public function fewer_than_2_xlabels_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AreaChart(
            xLabels: ['Only'],
            series: [['name' => 'X', 'values' => [1]]],
        );
    }

    #[Test]
    public function empty_series_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AreaChart(xLabels: ['A', 'B'], series: []);
    }

    #[Test]
    public function values_count_mismatch_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AreaChart(
            xLabels: ['A', 'B', 'C'],
            series: [['name' => 'X', 'values' => [1, 2]]],
        );
    }
}
