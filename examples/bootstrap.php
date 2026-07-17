<?php

declare(strict_types=1);

/**
 * Shared plumbing for the runnable examples. Each example writes its
 * output into samples/ (committed to the repository as a gallery).
 */

require dirname(__DIR__).'/vendor/autoload.php';

const SAMPLES_DIR = __DIR__.'/../samples';

function save_sample(string $name, string $bytes): void
{
    if (! is_dir(SAMPLES_DIR)) {
        mkdir(SAMPLES_DIR, 0777, true);
    }
    $path = SAMPLES_DIR.'/'.$name;
    file_put_contents($path, $bytes);
    printf("wrote %s (%d KB)\n", $path, (int) (strlen($bytes) / 1024));
}

/**
 * Liberation fonts cached by scripts/fetch-fonts.sh, or null when absent.
 * Examples that strictly need embedded fonts (PDF/A) skip politely.
 */
function liberation_dir(): ?string
{
    $dir = dirname(__DIR__).'/.cache/fonts/liberation-fonts-ttf-2.1.5';

    return is_readable($dir.'/LiberationSans-Regular.ttf') ? $dir : null;
}
