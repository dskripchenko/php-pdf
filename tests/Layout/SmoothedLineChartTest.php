<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmoothedLineChartTest extends TestCase
{
    #[Test]
    public function smoothed_emits_bezier_curves(): void
    {
        $chart = new LineChart(
            points: [
                ['label' => 'A', 'value' => 1],
                ['label' => 'B', 'value' => 4],
                ['label' => 'C', 'value' => 2],
                ['label' => 'D', 'value' => 5],
            ],
            smoothed: true,
        );
        $bytes = (new Document(new Section([$chart])))
            ->toBytes(new Engine(compressStreams: false));

        // Cubic Bezier operator c.
        self::assertMatchesRegularExpression('@\sc\n@', $bytes);
    }

    #[Test]
    public function default_straight_line_no_bezier(): void
    {
        $chart = new LineChart(
            points: [
                ['label' => 'A', 'value' => 1],
                ['label' => 'B', 'value' => 4],
                ['label' => 'C', 'value' => 2],
            ],
        );
        $bytes = (new Document(new Section([$chart])))
            ->toBytes(new Engine(compressStreams: false));

        // No c operator (только straight l).
        self::assertDoesNotMatchRegularExpression('@\sc\n@', $bytes);
        self::assertMatchesRegularExpression('@\sl\n@', $bytes);
    }

    #[Test]
    public function smoothed_starts_with_moveto(): void
    {
        $chart = new LineChart(
            points: [
                ['label' => 'A', 'value' => 1],
                ['label' => 'B', 'value' => 2],
            ],
            smoothed: true,
        );
        $bytes = (new Document(new Section([$chart])))
            ->toBytes(new Engine(compressStreams: false));

        // m operator (moveTo).
        self::assertMatchesRegularExpression('@\sm\n@', $bytes);
    }

    #[Test]
    public function smoothed_with_two_points_only(): void
    {
        // Edge case — 2 points, single Bezier с virtual endpoints.
        $chart = new LineChart(
            points: [
                ['label' => 'A', 'value' => 1],
                ['label' => 'B', 'value' => 2],
            ],
            smoothed: true,
        );
        $bytes = (new Document(new Section([$chart])))
            ->toBytes(new Engine(compressStreams: false));
        // 1 c operator.
        self::assertSame(1, preg_match_all('@\sc\n@', $bytes));
    }
}
