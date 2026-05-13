<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\GroupedBarChart;
use Dskripchenko\PhpPdf\Element\MultiLineChart;
use Dskripchenko\PhpPdf\Element\ScatterChart;
use Dskripchenko\PhpPdf\Element\StackedBarChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChartGridLinesExtensionTest extends TestCase
{
    private const GRID_COLOR = '@0\.85\s+0\.85\s+0\.85\s+RG@';

    #[Test]
    public function grouped_bar_grid_lines(): void
    {
        $chart = new GroupedBarChart(
            bars: [['label' => 'A', 'values' => [1, 2]]],
            seriesNames: ['X', 'Y'],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$chart])))->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression(self::GRID_COLOR, $bytes);
    }

    #[Test]
    public function stacked_bar_grid_lines(): void
    {
        $chart = new StackedBarChart(
            bars: [['label' => 'A', 'values' => [1, 2]]],
            seriesNames: ['X', 'Y'],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$chart])))->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression(self::GRID_COLOR, $bytes);
    }

    #[Test]
    public function multi_line_grid_lines(): void
    {
        $chart = new MultiLineChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [1, 2]]],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$chart])))->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression(self::GRID_COLOR, $bytes);
    }

    #[Test]
    public function scatter_grid_lines(): void
    {
        $chart = new ScatterChart(
            series: [['name' => 'X', 'points' => [['x' => 1, 'y' => 1]]]],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$chart])))->toBytes(new Engine(compressStreams: false));
        self::assertMatchesRegularExpression(self::GRID_COLOR, $bytes);
    }

    #[Test]
    public function default_off(): void
    {
        $chart = new GroupedBarChart(
            bars: [['label' => 'A', 'values' => [1]]],
            seriesNames: ['X'],
        );
        $bytes = (new Document(new Section([$chart])))->toBytes(new Engine(compressStreams: false));
        self::assertDoesNotMatchRegularExpression(self::GRID_COLOR, $bytes);
    }
}
