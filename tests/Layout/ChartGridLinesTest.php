<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChartGridLinesTest extends TestCase
{
    #[Test]
    public function bar_chart_grid_lines_add_extra_strokes(): void
    {
        $without = new BarChart(
            bars: [['label' => 'A', 'value' => 10], ['label' => 'B', 'value' => 20]],
            showGridLines: false,
        );
        $with = new BarChart(
            bars: [['label' => 'A', 'value' => 10], ['label' => 'B', 'value' => 20]],
            showGridLines: true,
        );
        $b1 = (new Document(new Section([$without])))->toBytes(new Engine(compressStreams: false));
        $b2 = (new Document(new Section([$with])))->toBytes(new Engine(compressStreams: false));

        // С grid lines — 3 extra stroke ops (25%/50%/75%).
        $s1 = preg_match_all('@\nS\n@', $b1);
        $s2 = preg_match_all('@\nS\n@', $b2);
        self::assertSame(3, $s2 - $s1);
    }

    #[Test]
    public function line_chart_grid_lines(): void
    {
        $with = new LineChart(
            points: [['label' => 'A', 'value' => 1], ['label' => 'B', 'value' => 2]],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$with])))->toBytes(new Engine(compressStreams: false));

        // Grid lines emitted с light gray 0.85 0.85 0.85 RG.
        self::assertMatchesRegularExpression('@0\.85\s+0\.85\s+0\.85\s+RG@', $bytes);
    }

    #[Test]
    public function area_chart_grid_lines(): void
    {
        $with = new AreaChart(
            xLabels: ['A', 'B', 'C'],
            series: [['name' => 'S', 'values' => [10, 20, 15]]],
            showGridLines: true,
        );
        $bytes = (new Document(new Section([$with])))->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@0\.85\s+0\.85\s+0\.85\s+RG@', $bytes);
    }

    #[Test]
    public function grid_lines_default_off(): void
    {
        $bar = new BarChart(bars: [['label' => 'A', 'value' => 1]]);
        $bytes = (new Document(new Section([$bar])))->toBytes(new Engine(compressStreams: false));
        self::assertDoesNotMatchRegularExpression('@0\.85\s+0\.85\s+0\.85\s+RG@', $bytes);
    }
}
