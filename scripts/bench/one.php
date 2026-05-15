<?php

declare(strict_types=1);

ini_set('memory_limit', '1G');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/scenarios.php';

// Usage: php one.php <lib> <scenario>
$lib = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
$fn = "bench_{$lib}_{$scenario}";
if (! function_exists($fn)) {
    fwrite(STDERR, "no scenario: {$fn}\n");
    exit(2);
}

$start = hrtime(true);
$bytes = $fn();
$ms = (hrtime(true) - $start) / 1e6;
$mem = memory_get_peak_usage(true);
$size = strlen($bytes);

echo json_encode(['ms' => $ms, 'mem' => $mem, 'bytes' => $size]) . "\n";
