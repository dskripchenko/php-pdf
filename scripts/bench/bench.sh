#!/usr/bin/env bash
# Single entry point for the benchmark harness (wired to `composer bench`).
# Installs the pinned competitor libraries (scripts/bench/composer.lock),
# runs the orchestrator and prints the markdown tables. Extra arguments are
# passed through to run.php (e.g. --docs to regenerate the published docs).
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -d "$DIR/vendor" ]; then
    composer install --working-dir "$DIR" --prefer-dist --no-interaction --no-progress
fi

php "$DIR/run.php" "$@" > /dev/null
cat "$DIR/out/results.md"
