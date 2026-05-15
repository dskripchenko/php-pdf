<?php

declare(strict_types=1);

/**
 * Benchmark orchestrator. For each (library, scenario) combination, spawns
 * REPETITIONS subprocesses running one.php — this gives each run a clean
 * process so peak memory readings reflect that scenario alone.
 *
 *   php scripts/bench/run.php            # JSON to stdout
 *   php scripts/bench/run.php --md > docs/en/BENCHMARKS.md
 */

const REPETITIONS = 5;
const WARMUP = 1;

$scenarios = [
    'hello'   => ['label' => 'Hello world (single page, one paragraph)'],
    'invoice' => ['label' => '100-page invoice (50 rows/page)'],
    'images'  => ['label' => 'Image grid (20 pages × 4 JPEGs)'],
    'html'    => ['label' => 'HTML → PDF article (~5 pages)'],
];

$libs = ['phppdf', 'mpdf', 'tcpdf', 'dompdf', 'fpdf'];

$results = [];
foreach ($scenarios as $scenario => $meta) {
    foreach ($libs as $lib) {
        // Warmup.
        for ($i = 0; $i < WARMUP; $i++) {
            run_one($lib, $scenario);
        }

        $times = $mems = [];
        $size = 0;
        $skipReason = null;
        for ($i = 0; $i < REPETITIONS; $i++) {
            $r = run_one($lib, $scenario);
            if (isset($r['skipped'])) {
                $skipReason = $r['skipped'];
                break;
            }
            $times[] = $r['ms'];
            $mems[] = $r['mem'];
            $size = $r['bytes'];
        }

        if ($skipReason !== null) {
            $results[$scenario][$lib] = ['skipped' => $skipReason];
            fwrite(STDERR, sprintf("%-10s %-7s   skipped (%s)\n", $scenario, $lib, $skipReason));
            continue;
        }

        sort($times);
        sort($mems);
        $median = $times[(int) (count($times) / 2)];
        $medianMem = $mems[(int) (count($mems) / 2)];
        $results[$scenario][$lib] = [
            'ms'    => round($median, 1),
            'mem'   => $medianMem,
            'bytes' => $size,
        ];
        fwrite(STDERR, sprintf(
            "%-10s %-7s %7.1fms  %6.1fMB  %6dKB\n",
            $scenario, $lib, $median, $medianMem / 1024 / 1024, (int) ($size / 1024),
        ));
    }
}

if (in_array('--md', $argv, true)) {
    emit_markdown($scenarios, $results);
} else {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function run_one(string $lib, string $scenario): array
{
    $cmd = sprintf(
        '%s %s/one.php %s %s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__),
        escapeshellarg($lib),
        escapeshellarg($scenario),
    );
    $output = shell_exec($cmd) ?? '';
    $output = trim($output);
    if ($output === '') {
        return ['skipped' => 'not implemented'];
    }
    $r = json_decode($output, true);
    if (! is_array($r) || ! isset($r['ms'])) {
        return ['skipped' => 'subprocess failure'];
    }
    return $r;
}

function emit_markdown(array $scenarios, array $results): void
{
    echo "## Benchmark results\n\n";
    echo sprintf(
        "Methodology: each (library, scenario) ran %d times in an isolated\n"
        . "PHP subprocess (after %d warmup); median wall time and peak memory\n"
        . "are reported. Output size is the produced PDF byte count.\n\n",
        REPETITIONS, WARMUP,
    );
    foreach ($scenarios as $key => $meta) {
        echo "### {$meta['label']}\n\n";
        echo "| Library | Wall time (ms) | Peak memory | Output size |\n";
        echo "|---|---:|---:|---:|\n";
        $rows = $results[$key];
        uasort($rows, fn ($a, $b) =>
            (isset($a['skipped']) <=> isset($b['skipped']))
            ?: (($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX))
        );
        foreach ($rows as $lib => $r) {
            if (isset($r['skipped'])) {
                echo "| {$lib} | _skipped_ ({$r['skipped']}) | — | — |\n";
                continue;
            }
            printf(
                "| %s | %.1f | %.1f MB | %d KB |\n",
                $lib,
                $r['ms'],
                $r['mem'] / 1024 / 1024,
                (int) ($r['bytes'] / 1024),
            );
        }
        echo "\n";
    }
}
