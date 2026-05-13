<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\PieChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinePieChartTest extends TestCase
{
    #[Test]
    public function line_chart_polyline_emitted(): void
    {
        $chart = new LineChart([
            ['label' => 'Jan', 'value' => 100],
            ['label' => 'Feb', 'value' => 250],
            ['label' => 'Mar', 'value' => 175],
        ]);
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Polyline: m + l+ + S.
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+m@', $bytes);
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+l@', $bytes);
        self::assertStringContainsString('S', $bytes);
        // Labels rendered.
        self::assertStringContainsString('(Jan) Tj', $bytes);
        self::assertStringContainsString('(Feb) Tj', $bytes);
        self::assertStringContainsString('(Mar) Tj', $bytes);
    }

    #[Test]
    public function line_chart_markers_optional(): void
    {
        $withMarkers = new LineChart([
            ['label' => 'A', 'value' => 1],
            ['label' => 'B', 'value' => 2],
        ], showMarkers: true);
        $without = new LineChart([
            ['label' => 'A', 'value' => 1],
            ['label' => 'B', 'value' => 2],
        ], showMarkers: false);

        $doc1 = new Document(new Section([$withMarkers]));
        $bytes1 = $doc1->toBytes(new Engine(compressStreams: false));
        $doc2 = new Document(new Section([$without]));
        $bytes2 = $doc2->toBytes(new Engine(compressStreams: false));

        // Markers add filled rects (3×3); count differs.
        $count1 = preg_match_all('@^f$@m', $bytes1);
        $count2 = preg_match_all('@^f$@m', $bytes2);
        self::assertGreaterThan($count2, $count1);
    }

    #[Test]
    public function line_chart_requires_at_least_2_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LineChart([['label' => 'A', 'value' => 1]]);
    }

    #[Test]
    public function pie_chart_emits_polygon_fills(): void
    {
        $chart = new PieChart([
            ['label' => 'Slice A', 'value' => 30],
            ['label' => 'Slice B', 'value' => 50],
            ['label' => 'Slice C', 'value' => 20],
        ]);
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Polygon fill: m + l+ + h + f. Multiple slices → multiple polygons.
        $hCount = preg_match_all('@\nh\n@', $bytes);
        self::assertGreaterThanOrEqual(3, $hCount);
    }

    #[Test]
    public function pie_chart_legend_labels_rendered(): void
    {
        $chart = new PieChart([
            ['label' => 'Apples', 'value' => 40],
            ['label' => 'Pears', 'value' => 60],
        ]);
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Apples) Tj', $bytes);
        self::assertStringContainsString('(Pears) Tj', $bytes);
    }

    #[Test]
    public function pie_chart_title_rendered(): void
    {
        $chart = new PieChart(
            slices: [['label' => 'X', 'value' => 1]],
            title: 'PieTitle',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(PieTitle) Tj', $bytes);
    }

    #[Test]
    public function pie_chart_legend_disabled(): void
    {
        $chart = new PieChart(
            slices: [['label' => 'NoShow', 'value' => 1]],
            showLegend: false,
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('(NoShow) Tj', $bytes);
    }

    #[Test]
    public function pie_chart_empty_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PieChart(slices: []);
    }

    #[Test]
    public function pie_chart_negative_value_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PieChart(slices: [['label' => 'X', 'value' => -1]]);
    }

    #[Test]
    public function pie_chart_custom_slice_color(): void
    {
        $chart = new PieChart(
            slices: [
                ['label' => 'Red', 'value' => 1, 'color' => 'ff0000'],
                ['label' => 'Blue', 'value' => 1, 'color' => '0000ff'],
            ],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 1 0 0 rg + 0 0 1 rg colors.
        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }
}
