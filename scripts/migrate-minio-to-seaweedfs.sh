#!/bin/bash
#
# Copy all object storage content from the old MinIO data directory to SeaweedFS.
#
# Usage (from the repo root, after updating docker-compose.yml to the SeaweedFS version):
#   ./scripts/migrate-minio-to-seaweedfs.sh            # copy and verify every bucket
#   ./scripts/migrate-minio-to-seaweedfs.sh --dry-run  # list buckets and object counts only
#
# What it does:
#   1. Starts SeaweedFS (docker compose up -d seaweedfs) and waits until it is healthy.
#   2. Starts a temporary MinIO container on ./volumes/minio (the old data, same keys).
#   3. For every bucket in MinIO (recordings, cdr-archive, ...): creates it in SeaweedFS,
#      mirrors all objects, then verifies with `mc diff` and object counts.
#   4. Stops the temporary MinIO container. ./volumes/minio is left in place as a fallback.
#
# Safe to re-run: existing identical objects are skipped. Exits non-zero if any bucket
# does not match. Copied objects get a new "last modified" date (S3 cannot preserve it),
# so recording retention counts from the migration date.
#
# After it succeeds: make sure .env has MINIO_ENDPOINT=http://seaweedfs:8333, then start the stack with
#   ./scripts/upgrade.sh   (it also runs this script once by itself when ./volumes/minio has data)
#

set -euo pipefail

cd "$(dirname "$0")/.."

DRY_RUN=false
[ "${1:-}" = "--dry-run" ] && DRY_RUN=true

MINIO_IMAGE="docker.io/bitnamilegacy/minio:2025.5.24"   # the last MinIO image OPBX shipped
TMP_CONTAINER="opbx_minio_migrate"
SW_CONTAINER="opbx_seaweedfs"
SRC_DIR="$PWD/volumes/minio"

env_value() {
    # Read one key from .env without sourcing it (values may contain shell characters).
    grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

ACCESS_KEY="$(env_value MINIO_ACCESS_KEY)"
SECRET_KEY="$(env_value MINIO_SECRET_KEY)"
if [ -z "$ACCESS_KEY" ] || [ -z "$SECRET_KEY" ]; then
    echo "❌ MINIO_ACCESS_KEY / MINIO_SECRET_KEY must be set in .env (they are the S3 keys for both MinIO and SeaweedFS)."
    exit 1
fi

if [ ! -d "$SRC_DIR" ] || [ -z "$(ls -A "$SRC_DIR" 2>/dev/null | grep -v '^\.minio\.sys$' || true)" ]; then
    echo "ℹ️  No MinIO data found in ./volumes/minio — nothing to migrate."
    exit 0
fi

# Two MinIO servers must never share one data directory.
if [ "$(docker inspect -f '{{.State.Running}}' opbx_minio 2>/dev/null)" = "true" ]; then
    echo "❌ The old MinIO container (opbx_minio) is still running on ./volumes/minio. Stop it first:"
    echo "   docker compose stop minio   (or: docker stop opbx_minio)"
    exit 1
fi

cleanup() { docker rm -f "$TMP_CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "▶ Starting SeaweedFS..."
docker compose up -d seaweedfs >/dev/null 2>&1 || { echo "❌ Could not start seaweedfs (docker compose up -d seaweedfs)."; exit 1; }
for _ in $(seq 1 60); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$SW_CONTAINER" 2>/dev/null)" = "healthy" ] && break
    sleep 2
done
if [ "$(docker inspect -f '{{.State.Health.Status}}' "$SW_CONTAINER" 2>/dev/null)" != "healthy" ]; then
    echo "❌ SeaweedFS did not become healthy (docker compose logs seaweedfs)."
    exit 1
fi
NETWORK="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$SW_CONTAINER" | awk '{print $1}')"

echo "▶ Starting a temporary MinIO on ./volumes/minio..."
cleanup
docker run -d --name "$TMP_CONTAINER" --network "$NETWORK" --user root \
    -v "$SRC_DIR:/data" \
    -e MINIO_ROOT_USER="$ACCESS_KEY" -e MINIO_ROOT_PASSWORD="$SECRET_KEY" -e MINIO_SCANNER=off \
    "$MINIO_IMAGE" server /data >/dev/null
for _ in $(seq 1 60); do
    docker exec "$TMP_CONTAINER" curl -sf http://localhost:9000/minio/health/live >/dev/null 2>&1 && break
    sleep 2
done
if ! docker exec "$TMP_CONTAINER" curl -sf http://localhost:9000/minio/health/live >/dev/null 2>&1; then
    echo "❌ The temporary MinIO did not start (docker logs $TMP_CONTAINER)."
    docker logs --tail 20 "$TMP_CONTAINER" || true
    exit 1
fi

# Everything below runs inside the temporary container (it ships the mc client).
# Keys are passed as environment variables, never printed.
status=0
docker exec -i -e AK="$ACCESS_KEY" -e SK="$SECRET_KEY" -e DRY_RUN="$DRY_RUN" "$TMP_CONTAINER" sh -s <<'IN_CONTAINER' || status=$?
set -eu
export MC_CONFIG_DIR=/tmp/.mc
mc alias set src http://localhost:9000 "$AK" "$SK" >/dev/null
mc alias set dst http://seaweedfs:8333 "$AK" "$SK" >/dev/null

count() { mc ls --recursive "$1" 2>/dev/null | wc -l | tr -d ' '; }

buckets=$(mc ls src | awk '{print $NF}' | tr -d '/')
if [ -z "$buckets" ]; then
    echo "ℹ️  MinIO has no buckets — nothing to migrate."
    exit 0
fi

failed=0
for b in $buckets; do
    n=$(count "src/$b")
    if [ "$DRY_RUN" = "true" ]; then
        echo "  • $b: $n objects (dry run, nothing copied)"
        continue
    fi
    echo "▶ $b: $n objects"
    mc mb --ignore-existing "dst/$b" >/dev/null
    mc mirror --overwrite --quiet "src/$b" "dst/$b" >/dev/null
    m=$(count "dst/$b")
    # Objects only in SeaweedFS (">", e.g. recordings made after an earlier switch) are fine; anything
    # missing from it ("<") or different ("!") is a mismatch.
    mc diff "src/$b" "dst/$b" | grep -v '^>' > /tmp/diff.txt || true
    diffs=$(grep -c . /tmp/diff.txt || true)
    if [ "$diffs" -eq 0 ] && [ "$m" -ge "$n" ]; then
        echo "  ✓ $b verified: $m objects in SeaweedFS ($n from MinIO), nothing missing or different"
    else
        echo "  ✗ $b MISMATCH: source $n, SeaweedFS $m, $diffs missing or differing objects:"
        head -20 /tmp/diff.txt | sed 's/^/      /'
        failed=1
    fi
done
exit $failed
IN_CONTAINER

if [ $status -ne 0 ]; then
    echo "❌ Migration NOT complete (see the mismatches above). Do not switch MINIO_ENDPOINT yet; fix and re-run."
elif [ "$DRY_RUN" = "true" ]; then
    echo "✓ Dry run finished. Nothing was copied."
else
    echo "✓ Migration complete. ./volumes/minio was left untouched as a fallback."
    echo "  Next: make sure .env has MINIO_ENDPOINT=http://seaweedfs:8333, then start the stack: ./scripts/upgrade.sh"
fi
exit $status
