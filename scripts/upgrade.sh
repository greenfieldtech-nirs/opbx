#!/bin/bash
#
# Kept for existing docs and habits: the upgrade now lives in ./opbx.sh.
#
# Usage (from the repo root, after `git pull` and ./scripts/update-compose.sh):
#   ./scripts/upgrade.sh           # same as ./opbx.sh upgrade
#   ./scripts/upgrade.sh --prod    # hand-made .env without COMPOSE_FILE: adds docker-compose.prod.yml
#   ./scripts/upgrade.sh --no-waf  # this run without the WAF
#
# See ./opbx.sh --help. It copies MinIO data to SeaweedFS once, rebuilds with fresh base images, starts SafeLine
# first when the WAF is on, then the database, Redis, SeaweedFS and the app (showing its startup log), then the
# rest, and waits until every service is up. An installer-made .env's COMPOSE_FILE always decides the compose files.

set -euo pipefail
cd "$(dirname "$0")/.." >/dev/null
exec ./opbx.sh upgrade "$@"
