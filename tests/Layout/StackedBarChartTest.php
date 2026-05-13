<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\StackedBarChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StackedBarChartTest extends TestCase
{
    #[Test]
    public function stacked_bar_emits_segments(): void
    {
        $chart = new StackedBarChart(
            bars: [
                ['label' => 'Q1', 'values' => [30, 40, 20]],
                ['label' => 'Q2', 'values' => [50, 30, 10]],
            ],
            seriesNames: ['A', 'B', 'C'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 2 bars × 3 segments = 6 fillRects (minimum — plus legend rects).
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThanOrEqual(6, $count);
        // Labels rendered.
        self::assertStringContainsString('(Q1) Tj', $bytes);
        self::assertStringContainsString('(Q2) Tj', $bytes);
        // Legend names rendered.
        self::assertStringContainsString('(A) Tj', $bytes);
        self::assertStringContainsString('(B) Tj', $bytes);
        self::assertStringContainsString('(C) Tj', $bytes);
    }

    #[Test]
    public function stacked_uses_max_total_for_scale(): void
    {
        // Q1 total = 30+40+20 = 90; Q2 = 50+30+10 = 90. Max = 90 → on y-axis.
        $chart = new StackedBarChart(
            bars: [
                ['label' => 'Q1', 'values' => [30, 40, 20]],
            ],
            seriesNames: ['A', 'B', 'C'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(90) Tj', $bytes);
    }

    #[Test]
    public function stacked_custom_colors(): void
    {
        $chart = new StackedBarChart(
            bars: [['label' => 'X', 'values' => [10, 10]]],
            seriesNames: ['R', 'B'],
            seriesColors: ['ff0000', '0000ff'],
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@1\s+0\s+0\s+rg@', $bytes);
        self::assertMatchesRegularExpression('@0\s+0\s+1\s+rg@', $bytes);
    }

    #[Test]
    public function stacked_zero_value_segment_skipped(): void
    {
        // Zero values should produce zero-height segments — no fillRect.
        $chart1 = new StackedBarChart(
            bars: [['label' => 'X', 'values' => [10, 0, 10]]],
            seriesNames: ['A', 'B', 'C'],
        );
        $chart2 = new StackedBarChart(
            bars: [['label' => 'X', 'values' => [10, 5, 10]]],
            seriesNames: ['A', 'B', 'C'],
        );
        $b1 = (new Document(new Section([$chart1])))->toBytes(new Engine(compressStreams: false));
        $b2 = (new Document(new Section([$chart2])))->toBytes(new Engine(compressStreams: false));

        // Chart 1 имеет 1 segment skipped (Zero) → fewer fillRects.
        self::assertLessThan(preg_match_all('@^f$@m', $b2), preg_match_all('@^f$@m', $b1));
    }

    #[Test]
    public function value_count_mismatch_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StackedBarChart(
            bars: [['label' => 'X', 'values' => [1, 2, 3]]],
            seriesNames: ['A', 'B'],
        );
    }

    #[Test]
    public function empty_series_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StackedBarChart(
            bars: [['label' => 'X', 'values' => []]],
            seriesNames: [],
        );
    }

    #[Test]
    public function title_rendered(): void
    {
        $chart = new StackedBarChart(
            bars: [['label' => 'X', 'values' => [1, 1]]],
            seriesNames: ['A', 'B'],
            title: 'Revenue Mix',
        );
        $doc = new Document(new Section([$chart]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Revenue Mix) Tj', $bytes);
    }
}
