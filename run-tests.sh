#!/usr/bin/env bash
#
# Run PHPUnit tests with proper SQLite in-memory configuration
#
# Usage: ./run-tests.sh [--unit] [--feature] [--filter=<pattern>]
#
# This script runs tests using SQLite in-memory database for fast testing.
# It overrides Docker environment variables to use the testing configuration.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Default to running all tests
TEST_FILTER="${1:-}"

echo "=========================================="
echo "Running OpenPBX PHPUnit Tests"
echo "Database: SQLite in-memory"
echo "=========================================="

# Run tests with proper environment variables
docker compose exec \
    -e APP_ENV=testing \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=":memory:" \
    -e CACHE_STORE=array \
    -e QUEUE_CONNECTION=sync \
    -e SESSION_DRIVER=array \
    -e BROADCAST_CONNECTION=null \
    -e PULSE_ENABLED=false \
    -e TELESCOPE_ENABLED=false \
    -e NIGHTWATCH_ENABLED=false \
    app \
    php -d memory_limit=512M artisan test $TEST_FILTER

echo ""
echo "=========================================="
echo "Tests completed!"
echo "=========================================="
