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

final class ChartAxisRangesTest extends TestCase
{
    #[Test]
    public function bar_chart_ymax_overrides_auto_scale(): void
    {
        // Data max = 50, но yMax = 100 — y-axis label should show 100.
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 50]],
            yMax: 100,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(100) Tj', $bytes);
        self::assertStringNotContainsString('(50) Tj', $bytes);
    }

    #[Test]
    public function line_chart_ymax_overrides(): void
    {
        $chart = new LineChart(
            points: [['label' => 'A', 'value' => 10], ['label' => 'B', 'value' => 20]],
            yMax: 50,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(50) Tj', $bytes);
    }

    #[Test]
    public function area_chart_ymax_overrides(): void
    {
        $chart = new AreaChart(
            xLabels: ['A', 'B'],
            series: [['name' => 'X', 'values' => [10, 20]]],
            yMax: 100,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(100) Tj', $bytes);
    }

    #[Test]
    public function null_ymax_uses_auto(): void
    {
        // Default behavior preserved.
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 42]],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(42) Tj', $bytes);
    }

    #[Test]
    public function ymax_lower_than_data_clips_visually(): void
    {
        // Если yMax < data, bars exceed plot area, но не throw.
        $chart = new BarChart(
            bars: [['label' => 'A', 'value' => 100]],
            yMax: 50,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
        self::assertStringContainsString('(50) Tj', $bytes);
    }
}
