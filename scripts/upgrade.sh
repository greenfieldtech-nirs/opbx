#!/bin/bash
#
# Start OPBX after pulling a new version, in order, showing progress instead of failing.
#
# Usage (from the repo root, after `git pull` and ./scripts/update-compose.sh):
#   ./scripts/upgrade.sh           # development compose file
#   ./scripts/upgrade.sh --prod    # adds docker-compose.prod.yml (production)
#
# What it does:
#   1. MinIO -> SeaweedFS: if ./volumes/minio still holds data and has not been migrated yet,
#      runs ./scripts/migrate-minio-to-seaweedfs.sh (can take a long time with many recordings)
#      and records completion in ./volumes/.minio-migrated-to-seaweedfs. Stops on a mismatch.
#   2. Builds every image, pulling fresh base images (a cached base can miss a security release),
#      starts mysql, redis, seaweedfs and app, then follows the app's startup log
#      (database migrations, seeding, bucket creation) until it is healthy. A slow first start is
#      expected and is shown, not reported as an error.
#   3. Starts the rest of the stack and prints `docker compose ps`.
#
# UPGRADE_TIMEOUT (seconds, default 1800) bounds the wait for the app.

set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSE=(docker compose)
case "${1:-}" in
    --prod) COMPOSE+=(-f docker-compose.yml -f docker-compose.prod.yml) ;;
    "") ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1"; exit 2 ;;
esac

TIMEOUT="${UPGRADE_TIMEOUT:-1800}"
APP_CONTAINER="opbx_app"
MINIO_MARKER="volumes/.minio-migrated-to-seaweedfs"

[ -f .env ] || { echo "❌ .env not found."; exit 1; }
[ -f docker-compose.yml ] || { echo "❌ docker-compose.yml not found (copy or update it from docker-compose.yml.example)."; exit 1; }

# 1. MinIO data from an older installation, copied to SeaweedFS once.
if [ -d volumes/minio ] && [ -n "$(ls -A volumes/minio 2>/dev/null | grep -v '^\.minio\.sys$' || true)" ] && [ ! -f "$MINIO_MARKER" ]; then
    echo "▶ Copying MinIO data to SeaweedFS (this can take a long time with many recordings)..."
    ./scripts/migrate-minio-to-seaweedfs.sh
    date -u +%Y-%m-%dT%H:%M:%SZ > "$MINIO_MARKER"
    echo "✓ MinIO data migrated (recorded in $MINIO_MARKER)."
fi

# 2. The app first: it runs the database migrations and prepares storage before anything else starts.
echo "▶ Building the images (pulling fresh base images)..."
"${COMPOSE[@]}" build --pull

echo "▶ Starting the database, Redis, SeaweedFS and the app..."
# Taken before the start so the app's log is shown from its first line.
since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
"${COMPOSE[@]}" up -d mysql redis seaweedfs app

echo "▶ Waiting for the app to finish its startup tasks (migrations, seeding, storage)."
echo "  The first start after an upgrade can take several minutes; its log follows."
started=$(date +%s)
# Process substitution, so $! is `docker logs` itself and stopping it ends the follower quietly.
docker logs --since "$since" -f "$APP_CONTAINER" > >(sed 's/^/  │ /') 2>&1 &
log_pid=$!
trap 'kill $log_pid 2>/dev/null || true' EXIT

while true; do
    state="$(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$APP_CONTAINER" 2>/dev/null || echo "missing")"
    case "$state" in
        "running healthy")
            break ;;
        "running unhealthy")
            kill $log_pid 2>/dev/null || true
            echo "❌ The app container is unhealthy. Check: docker compose logs app"
            exit 1 ;;
        exited*|dead*|missing)
            kill $log_pid 2>/dev/null || true
            echo "❌ The app container stopped during startup ($state). Last log lines:"
            docker logs --tail 30 "$APP_CONTAINER" 2>&1 | sed 's/^/  │ /' || true
            exit 1 ;;
    esac
    if [ $(( $(date +%s) - started )) -ge "$TIMEOUT" ]; then
        kill $log_pid 2>/dev/null || true
        echo "❌ The app was not ready after ${TIMEOUT}s (raise UPGRADE_TIMEOUT if migrations are still running)."
        exit 1
    fi
    sleep 5
done

kill $log_pid 2>/dev/null || true
echo "✓ App ready after $(( $(date +%s) - started ))s."

# 3. Everything else (nginx, workers, scheduler, ...).
echo "▶ Starting the rest of the stack..."
"${COMPOSE[@]}" up -d
"${COMPOSE[@]}" ps
echo "✓ Upgrade complete."
