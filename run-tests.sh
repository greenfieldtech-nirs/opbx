#!/usr/bin/env bash
#
# Run PHPUnit tests against the MySQL test database
#
# Usage: ./run-tests.sh [--unit] [--feature] [--filter=<pattern>]
#
# This script runs tests using the MySQL `opbx_test` database.
# SQLite is intentionally NOT used for testing because SQLite semantics can
# hide MySQL-specific failures.
#
# IMPORTANT: Ensure the `opbx_test` database exists and the application user
# has privileges before running tests:
#   docker compose exec mysql mysql -uroot -p"$DB_ROOT_PASSWORD" \
#     -e "CREATE DATABASE IF NOT EXISTS opbx_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
#   docker compose exec mysql mysql -uroot -p"$DB_ROOT_PASSWORD" \
#     -e "GRANT ALL PRIVILEGES ON opbx_test.* TO 'opbx'@'%'; FLUSH PRIVILEGES;"
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Source .env if available so DB_PASSWORD and other secrets are available
if [ -z "${DB_PASSWORD:-}" ] && [ -f .env ]; then
    set -a
    source .env
    set +a
fi

# Default to running all tests
TEST_FILTER="${1:-}"

echo "=========================================="
echo "Running OpenPBX PHPUnit Tests"
echo "Database: MySQL (opbx_test)"
echo "=========================================="

# Run tests with proper environment variables
DB_PASSWORD="${DB_PASSWORD:-}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"

docker compose exec \
    -e APP_ENV=testing \
    -e DB_CONNECTION=mysql \
    -e DB_HOST=mysql \
    -e DB_PORT=3306 \
    -e DB_DATABASE=opbx_test \
    -e DB_USERNAME=opbx \
    -e DB_PASSWORD="$DB_PASSWORD" \
    -e CACHE_STORE=array \
    -e QUEUE_CONNECTION=sync \
    -e SESSION_DRIVER=array \
    -e BROADCAST_CONNECTION=null \
    -e PULSE_ENABLED=false \
    -e TELESCOPE_ENABLED=false \
    -e NIGHTWATCH_ENABLED=false \
    app \
    php -d memory_limit=1024M artisan test $TEST_FILTER

echo ""
echo "=========================================="
echo "Tests completed!"
echo "=========================================="
