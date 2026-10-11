#!/usr/bin/env bash
#
# Fill in shared secrets in .env that are missing, empty or still a shipped
# placeholder. Existing real values are never changed. Values are not printed.
#
# Usage: docker/scripts/generate-secrets.sh [path/to/.env]
#
# Every consumer (Laravel, workers, Soketi) reads these from the same .env,
# so recreate the containers after a change for the new values to apply.

set -euo pipefail

ENV_FILE="${1:-.env}"
SECRETS=(DIALER_WORKER_API_TOKEN AMD_WORKER_API_TOKEN ACD_WORKER_API_TOKEN PUSHER_APP_SECRET GRAFANA_ADMIN_PASSWORD)
# Keep in sync with the placeholder list in docker/scripts/validate-env.sh.
PLACEHOLDER_RE='change-in-production|pbxappsecret|CHANGE_ME|GENERATE_32_CHAR|64_CHAR_SECRET'

[ -f "$ENV_FILE" ] || { echo "No such env file: $ENV_FILE" >&2; exit 1; }

for name in "${SECRETS[@]}"; do
    current=$(grep -E "^${name}=" "$ENV_FILE" | tail -1 | cut -d '=' -f2- | sed -E 's/[[:space:]]+#.*$//; s/^[[:space:]]+//' | tr -d "\"'" || true)
    if [ -n "$current" ] && ! [[ "$current" =~ $PLACEHOLDER_RE ]]; then
        echo "  ${name}: kept"
        continue
    fi

    value=$(openssl rand -hex 32)
    if grep -qE "^${name}=" "$ENV_FILE"; then
        tmp=$(mktemp)
        awk -v n="$name" -v v="$value" 'BEGIN { FS = OFS = "=" } $1 == n { print n "=" v; next } { print }' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE" && rm -f "$tmp"
    else
        printf '%s=%s\n' "$name" "$value" >> "$ENV_FILE"
    fi
    echo "  ${name}: generated"
done
