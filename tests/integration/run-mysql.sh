#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

"${PHP_BINARY:-php}" -r 'if (!extension_loaded("pdo_mysql")) { fwrite(STDERR, "pdo_mysql is required for MySQL integration tests.\n"); exit(1); }'

export PHP_ENTITY_MYSQL_PORT="${PHP_ENTITY_MYSQL_PORT:-33386}"
export PHP_ENTITY_MYSQL_DSN="mysql:host=127.0.0.1;port=${PHP_ENTITY_MYSQL_PORT};charset=utf8mb4"
export PHP_ENTITY_MYSQL_USER=root
export PHP_ENTITY_MYSQL_PASSWORD=php_entity_test
compose=(docker compose -p "php-entity-mysql-test-$$" -f compose.mysql-test.yaml)
cleanup() {
    result=$?
    if (( result != 0 )); then
        "${compose[@]}" logs --tail=60 mysql || true
    fi
    "${compose[@]}" down --volumes
    exit "$result"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait --wait-timeout 150
"${PHP_BINARY:-php}" vendor/bin/phpunit -c phpunit.mysql.xml "$@"
