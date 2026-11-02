#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
export BENCH_ROWS="${2:-${BENCH_ROWS:-10000}}"
composer install --no-interaction
compose=(docker compose -p php-entity-select-bench -f compose.yaml)
trap '"${compose[@]}" down --volumes' EXIT
"${compose[@]}" up -d --wait --wait-timeout 150
php benchmark.php seed
php benchmark.php run "${1:-15}"
