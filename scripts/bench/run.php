<?php

declare(strict_types=1);

/**
 * Benchmark orchestrator. For each (library, scenario) combination, spawns
 * REPETITIONS subprocesses running one.php — this gives each run a clean
 * process so peak memory readings reflect that scenario alone.
 *
 *   composer bench            # from the repository root (installs deps,
 *                             # runs, writes out/results.{json,csv,md})
 *   php scripts/bench/run.php           # run + write out/ files
 *   php scripts/bench/run.php --docs    # …and regenerate
 *                                       # docs/en/BENCHMARKS.md + the
 *                                       # README performance tables
 *
 * Honesty rules: competitor versions come from scripts/bench/composer.lock
 * (committed), the report embeds every library version plus the PHP/OS
 * environment, and the published tables are generated from the run — never
 * edited by hand.
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

$meta = collect_meta();
fwrite(STDERR, "environment: PHP {$meta['php']} · {$meta['os']}\n");
foreach ($meta['libraries'] as $lib => $version) {
    fwrite(STDERR, "  $lib $version\n");
}

$results = [];
foreach ($scenarios as $scenario => $sMeta) {
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

$outDir = __DIR__.'/out';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

file_put_contents(
    "$outDir/results.json",
    json_encode(['meta' => $meta, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);
file_put_contents("$outDir/results.csv", emit_csv($scenarios, $results, $meta));
file_put_contents("$outDir/results.md", emit_markdown($scenarios, $results, $meta));
fwrite(STDERR, "wrote $outDir/results.{json,csv,md}\n");

if (in_array('--docs', $argv, true)) {
    $root = dirname(__DIR__, 2);
    file_put_contents("$root/docs/en/BENCHMARKS.md", emit_benchmarks_doc($scenarios, $results, $meta));
    fwrite(STDERR, "wrote docs/en/BENCHMARKS.md\n");
    foreach (["$root/README.md", "$root/docs/en/README.md", "$root/docs/ru/README.md", "$root/docs/zh/README.md", "$root/docs/de/README.md"] as $readme) {
        if (patch_readme($readme, $scenarios, $results, $meta)) {
            fwrite(STDERR, 'patched '.substr($readme, strlen($root) + 1)."\n");
        }
    }
}

echo json_encode(['meta' => $meta, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

/**
 * @return array{php: string, os: string, opcache: string, date: string, libraries: array<string, string>}
 */
function collect_meta(): array
{
    $lock = json_decode((string) file_get_contents(__DIR__.'/composer.lock'), true);
    $libraries = [];
    foreach ($lock['packages'] ?? [] as $pkg) {
        if (in_array($pkg['name'], ['mpdf/mpdf', 'tecnickcom/tcpdf', 'dompdf/dompdf', 'setasign/fpdf'], true)) {
            $libraries[$pkg['name']] = $pkg['version'];
        }
    }

    // php-pdf is wired in by path (../../src) — version = git describe.
    $phpPdfVersion = trim((string) shell_exec(
        'git -C '.escapeshellarg(dirname(__DIR__, 2)).' describe --tags --always 2>/dev/null'
    ));
    $libraries = ['dskripchenko/php-pdf' => $phpPdfVersion !== '' ? $phpPdfVersion : 'dev'] + $libraries;

    return [
        'php' => PHP_VERSION,
        'os' => php_uname('s').' '.php_uname('r').' '.php_uname('m'),
        'opcache' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false)
            ? 'enabled' : 'disabled (CLI default)',
        'date' => date('Y-m-d'),
        'libraries' => $libraries,
    ];
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

function sorted_rows(array $rows): array
{
    uasort($rows, fn ($a, $b) => (isset($a['skipped']) <=> isset($b['skipped']))
        ?: (($a['ms'] ?? PHP_INT_MAX) <=> ($b['ms'] ?? PHP_INT_MAX)));

    return $rows;
}

function emit_csv(array $scenarios, array $results, array $meta): string
{
    $lines = ['scenario,library,median_ms,peak_memory_bytes,output_bytes,skipped'];
    foreach ($scenarios as $key => $sMeta) {
        foreach ($results[$key] ?? [] as $lib => $r) {
            $lines[] = isset($r['skipped'])
                ? sprintf('%s,%s,,,,"%s"', $key, $lib, $r['skipped'])
                : sprintf('%s,%s,%.1f,%d,%d,', $key, $lib, $r['ms'], $r['mem'], $r['bytes']);
        }
    }
    $lines[] = '';
    $lines[] = '# PHP '.$meta['php'].' · '.$meta['os'].' · '.$meta['date'];
    foreach ($meta['libraries'] as $lib => $v) {
        $lines[] = "# $lib $v";
    }

    return implode("\n", $lines)."\n";
}

function emit_markdown(array $scenarios, array $results, array $meta): string
{
    $out = "## Benchmark results\n\n";
    $out .= sprintf(
        "Methodology: each (library, scenario) ran %d times in an isolated\n"
        ."PHP subprocess (after %d warmup); median wall time and peak memory\n"
        ."are reported. Output size is the produced PDF byte count.\n\n",
        REPETITIONS, WARMUP,
    );
    $out .= environment_block($meta);

    return $out.results_tables($scenarios, $results);
}

function results_tables(array $scenarios, array $results): string
{
    $out = '';
    foreach ($scenarios as $key => $sMeta) {
        $out .= "### {$sMeta['label']}\n\n";
        $out .= "| Library | Wall time (ms) | Peak memory | Output size |\n";
        $out .= "|---|---:|---:|---:|\n";
        foreach (sorted_rows($results[$key]) as $lib => $r) {
            if (isset($r['skipped'])) {
                $out .= "| {$lib} | _skipped_ ({$r['skipped']}) | — | — |\n";
                continue;
            }
            $kb = $r['bytes'] / 1024;
            $out .= sprintf(
                "| %s | %.1f | %.1f MB | %s KB |\n",
                $lib, $r['ms'], $r['mem'] / 1024 / 1024,
                $kb < 10 ? sprintf('%.1f', $kb) : sprintf('%d', (int) $kb),
            );
        }
        $out .= "\n";
    }

    return $out;
}

function environment_block(array $meta): string
{
    $out = "**Environment ({$meta['date']}):**\n\n";
    $out .= "- PHP {$meta['php']} (CLI, opcache {$meta['opcache']})\n";
    $out .= "- {$meta['os']}\n";
    foreach ($meta['libraries'] as $lib => $v) {
        $out .= "- $lib $v\n";
    }

    return $out."\n";
}

/**
 * Summary table for the READMEs: wall time per scenario × library.
 */
function summary_table(array $scenarios, array $results): string
{
    $out = "| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |\n";
    $out .= "|---|---:|---:|---:|---:|---:|\n";
    foreach (['html', 'invoice', 'images', 'hello'] as $key) {
        if (! isset($scenarios[$key])) {
            continue;
        }
        $cells = [];
        foreach (['phppdf', 'mpdf', 'tcpdf', 'dompdf', 'fpdf'] as $lib) {
            $r = $results[$key][$lib] ?? ['skipped' => 'missing'];
            if (isset($r['skipped'])) {
                $cells[] = '_n/a_';
            } else {
                $ms = $r['ms'] >= 100 ? sprintf('%d ms', (int) round($r['ms'])) : sprintf('%.1f ms', $r['ms']);
                $cells[] = $lib === 'phppdf' ? "**$ms**" : $ms;
            }
        }
        $out .= '| '.$scenarios[$key]['label'].' | '.implode(' | ', $cells)." |\n";
    }

    return $out;
}

/**
 * Replace the block between bench markers in a README, keeping the rest.
 */
function patch_readme(string $path, array $scenarios, array $results, array $meta): bool
{
    if (! is_file($path)) {
        return false;
    }
    $content = (string) file_get_contents($path);
    $start = '<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->';
    $end = '<!-- bench:table:end -->';
    $s = strpos($content, $start);
    $e = strpos($content, $end);
    if ($s === false || $e === false || $e < $s) {
        return false;
    }
    $block = $start."\n".summary_table($scenarios, $results).$end;
    $content = substr($content, 0, $s).$block.substr($content, $e + strlen($end));
    file_put_contents($path, $content);

    return true;
}

function emit_benchmarks_doc(array $scenarios, array $results, array $meta): string
{
    $head = <<<'MD'
# Benchmarks

> Generated by `scripts/bench/run.php` — do not edit the numbers by hand.
> Reproduce with `composer bench` from a clean clone.

A reproducible comparison of `dskripchenko/php-pdf` against the four
most-used PHP PDF libraries on Packagist: `mpdf/mpdf`, `tecnickcom/tcpdf`,
`dompdf/dompdf`, and `setasign/fpdf`.

## Methodology

Each (library, scenario) pair runs **5 times** inside its own PHP
subprocess after **1 warmup** run. Median wall-time and peak memory are
reported. Output size is the byte count of the produced PDF.

Subprocess isolation matters: a single long-running PHP process
accumulates loaded code and OPcache pressure, which inflates the memory
peak of whatever scenario happens to run last. Spawning a fresh process
per run makes the memory column meaningful.

Wall-time is measured with `hrtime(true)`; memory with
`memory_get_peak_usage(true)`. Competitor versions are pinned in
`scripts/bench/composer.lock`, committed to the repository.

## Scenarios

| Key       | Description                                            |
|-----------|--------------------------------------------------------|
| `hello`   | Single A4 page, one `Hello, world!` paragraph.        |
| `invoice` | 100 pages × 50 rows; each page has an `<h1>` heading and a 4-column table. |
| `images`  | 20 pages × 4 JPEG thumbnails (250 × 180 pt each).     |
| `html`    | One `<h1>`, two `<h2>` sections, 24 paragraphs of Lorem with bold/italic/link plus bullet lists. ~5 pages. |

FPDF has no HTML support and is therefore skipped on the `html`
scenario.

## How to reproduce

```bash
git clone https://github.com/dskripchenko/php-pdf.git && cd php-pdf
composer install
composer bench          # installs pinned competitors, runs, prints tables
```

Machine-readable output lands in `scripts/bench/out/results.{json,csv}`.


MD;

    return $head.environment_block($meta)."## Results\n\n"
        .results_tables($scenarios, $results)
        ."---\n\nLanguage: [English](BENCHMARKS.md) · [Русский](../ru/BENCHMARKS.md) · [中文](../zh/BENCHMARKS.md) · [Deutsch](../de/BENCHMARKS.md)\n";
}
