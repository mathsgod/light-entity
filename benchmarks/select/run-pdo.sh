#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
export BENCH_ROWS="${2:-${BENCH_ROWS:-10000}}"
compose=(docker compose -p php-entity-pdo-bench -f compose.yaml)
trap '"${compose[@]}" down --volumes' EXIT
"${compose[@]}" up -d --wait --wait-timeout 150
php pdo-benchmark.php seed
php pdo-benchmark.php run "${1:-30}"
