<?php

declare(strict_types=1);

// Native vector charts — no image rasterization, crisp at any zoom.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\PieChart;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Section;

$doc = new Document(new Section([
    new Heading(1, [new Run('Charts')]),
    new PieChart([
        ['label' => 'MIT', 'value' => 62],
        ['label' => 'GPL', 'value' => 23],
        ['label' => 'Proprietary', 'value' => 15],
    ], title: 'License share'),
    new BarChart(bars: [
        ['label' => 'php-pdf', 'value' => 12.0],
        ['label' => 'tcpdf', 'value' => 37.4],
        ['label' => 'dompdf', 'value' => 51.7],
        ['label' => 'mpdf', 'value' => 64.1],
    ], title: 'HTML render, ms (lower is better)'),
    new LineChart(points: [
        ['label' => 'Apr', 'value' => 3],
        ['label' => 'May', 'value' => 8],
        ['label' => 'Jun', 'value' => 6],
        ['label' => 'Jul', 'value' => 12],
    ], title: 'Monthly downloads, k'),
]));

save_sample('04-charts.pdf', $doc->toBytes());
