#!/bin/bash
#
# Update an existing installation's docker-compose.yml from the new docker-compose.yml.example.
#
# Usage (from the repo root, after pulling the new version):
#   ./scripts/update-compose.sh              # report, ask per changed service, then write
#   ./scripts/update-compose.sh --dry-run    # report only, write nothing
#   ./scripts/update-compose.sh --yes        # unattended: take the new version of every changed service
#   ./scripts/update-compose.sh --file other.yml --example docker-compose.yml.example
#
# What it does:
#   - Compares the two files service by service (all profiles, e.g. logs and dev) and reports:
#       new services (added), changed services (diff shown), services only in this installation
#       (always kept), and retired services that upstream removed (e.g. minio).
#   - For every changed service asks: take the new version, or keep yours. --yes takes the new one.
#   - Retired services are removed only when you confirm; --yes keeps them and warns
#     (minio must stay until ./scripts/migrate-minio-to-seaweedfs.sh has succeeded).
#   - networks / volumes / other top-level keys come from the new file; entries that exist
#     only in your file are kept.
#   - Validates the result with `docker compose config`, backs up the old file
#     (docker-compose.yml.bak-YYYYmmdd-HHMMSS) and writes the new one. Never restarts anything.
#
# Needs only Docker: YAML is handled by the mikefarah/yq image.

set -euo pipefail
cd "$(dirname "$0")/.."

LOCAL="docker-compose.yml"
NEW="docker-compose.yml.example"
YES=false
DRY_RUN=false
while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) YES=true ;;
        --dry-run) DRY_RUN=true ;;
        --file) LOCAL="$2"; shift ;;
        --example) NEW="$2"; shift ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1"; exit 2 ;;
    esac
    shift
done

# Services upstream has removed. Removing one is always confirmed by hand.
RETIRED="minio"
YQ_IMAGE="docker.io/mikefarah/yq:4.53.6"

[ -f "$LOCAL" ] || { echo "❌ $LOCAL not found."; exit 1; }
[ -f "$NEW" ] || { echo "❌ $NEW not found."; exit 1; }

WORK="$(mktemp -d "$PWD/.compose-update.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
cp "$LOCAL" "$WORK/local.yml"
cp "$NEW" "$WORK/new.yml"
# The yq image runs as a non-root user; this throwaway dir must be readable/writable by it.
chmod 777 "$WORK" && chmod 666 "$WORK"/*.yml

yq() { docker run --rm -v "$WORK":/workdir -w /workdir "$YQ_IMAGE" "$@"; }

# One line per service: "name<TAB>canonical JSON" (sorted keys), so formatting and key order do not
# count as changes. One container run per file instead of one per service.
yq '.services // {} | to_entries | .[] | .key + "\t" + (.value | sort_keys(..) | to_json(0))' local.yml > "$WORK/local.idx"
yq '.services // {} | to_entries | .[] | .key + "\t" + (.value | sort_keys(..) | to_json(0))' new.yml > "$WORK/new.idx"
services() { cut -f1 "$WORK/${1%.yml}.idx"; }
canon() { awk -F'\t' -v s="$2" '$1 == s { print $2 }' "$WORK/${1%.yml}.idx"; }
service_yaml() { yq ".services.\"$2\"" "$1"; }

INTERACTIVE=true
[ "$YES" = "true" ] || [ "$DRY_RUN" = "true" ] && INTERACTIVE=false

ask() {
    # ask "question" default(y|n) -> 0 for yes. Non-interactive runs take the default.
    local answer=""
    if [ "$INTERACTIVE" = "true" ]; then
        read -r -p "$1 " answer </dev/tty || answer=""
    fi
    answer="${answer:-$2}"
    case "$answer" in [Yy]*) return 0 ;; *) return 1 ;; esac
}

# Plain space-separated lists (service names have no spaces) keep this bash-3.2 compatible.
LOCAL_SVCS="$(services local.yml | tr '\n' ' ')"
NEW_SVCS="$(services new.yml | tr '\n' ' ')"
in_list() { case " $2 " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

ADDED="" CHANGED="" SAME_COUNT=0 LOCAL_ONLY="" RETIRED_FOUND=""
for s in $NEW_SVCS; do
    if ! in_list "$s" "$LOCAL_SVCS"; then ADDED="$ADDED $s"
    elif [ "$(canon local.yml "$s")" != "$(canon new.yml "$s")" ]; then CHANGED="$CHANGED $s"
    else SAME_COUNT=$((SAME_COUNT + 1)); fi
done
for s in $LOCAL_SVCS; do
    in_list "$s" "$NEW_SVCS" && continue
    if in_list "$s" "$RETIRED"; then RETIRED_FOUND="$RETIRED_FOUND $s"; else LOCAL_ONLY="$LOCAL_ONLY $s"; fi
done
TOP_DIFF=false
[ "$(yq -o=json -I0 'del(.services) | del(.version) | sort_keys(..)' local.yml)" != "$(yq -o=json -I0 'del(.services) | del(.version) | sort_keys(..)' new.yml)" ] && TOP_DIFF=true

echo "Comparing $LOCAL with $NEW"
echo "  unchanged services:    $SAME_COUNT"
echo "  new (will be added):  ${ADDED:- none}"
echo "  changed:              ${CHANGED:- none}"
echo "  only in yours (kept): ${LOCAL_ONLY:- none}"
echo "  retired upstream:     ${RETIRED_FOUND:- none}"
[ "$TOP_DIFF" = "true" ] && echo "  networks/volumes/top-level settings differ (new values win, yours-only entries kept)"

if [ -z "$ADDED$CHANGED$RETIRED_FOUND" ] && [ "$TOP_DIFF" = "false" ]; then
    echo "✓ $LOCAL is already up to date."
    exit 0
fi
if [ "$INTERACTIVE" = "true" ] && ! { : </dev/tty; } 2>/dev/null; then
    echo "❌ No terminal to ask on. Run it interactively, or pass --yes (or --dry-run)."
    exit 2
fi

# Start from the new file (keeps its order and comments), then put back what we keep from yours.
cp "$WORK/new.yml" "$WORK/result.yml" && chmod 666 "$WORK/result.yml"
keep_local() { yq -i ".services.\"$1\" = load(\"local.yml\").services.\"$1\"" result.yml; }

for s in $CHANGED; do
    echo
    echo "── $s changed (- yours, + new) ──"
    diff -u --label "yours" --label "new" <(service_yaml local.yml "$s") <(service_yaml new.yml "$s") | tail -n +3 || true
    [ "$DRY_RUN" = "true" ] && continue
    if ask "Take the NEW version of '$s'? [Y/n]" y; then
        echo "  → new version"
    else
        keep_local "$s"
        echo "  → keeping yours"
    fi
done

for s in $LOCAL_ONLY; do keep_local "$s"; done

for s in $RETIRED_FOUND; do
    echo
    echo "── $s was removed upstream ──"
    [ "$s" = "minio" ] && echo "  Only remove it after ./scripts/migrate-minio-to-seaweedfs.sh succeeded and MINIO_ENDPOINT points at seaweedfs."
    if [ "$INTERACTIVE" = "true" ] && ask "Remove '$s'? [y/N]" n; then
        echo "  → removed"
    else
        keep_local "$s"
        echo "  → kept (remove it later by re-running this script)"
    fi
done

# Top-level networks/volumes/etc.: the new file wins, entries only in yours are kept.
for key in $(yq 'del(.services) | del(.version) | keys | .[]' local.yml); do
    yq -i ".\"$key\" = (load(\"local.yml\").\"$key\" * (.\"$key\" // {}))" result.yml 2>/dev/null \
        || yq -i ".\"$key\" = (.\"$key\" // load(\"local.yml\").\"$key\")" result.yml
done

if [ "$DRY_RUN" = "true" ]; then
    echo
    echo "✓ Dry run: nothing was written."
    exit 0
fi

# Validate before replacing anything (all profiles, so logs/dev services are checked too).
if ! docker compose --project-directory "$PWD" -f "$WORK/result.yml" --profile '*' config --quiet 2>"$WORK/config.err"; then
    echo "❌ The merged file does not validate; $LOCAL was NOT changed:"
    grep -v -E 'compose provider|podman-compose|attribute .version' "$WORK/config.err" | head -20
    exit 1
fi

BACKUP="$LOCAL.bak-$(date +%Y%m%d-%H%M%S)"
cp "$LOCAL" "$BACKUP"
cp "$WORK/result.yml" "$LOCAL"
echo
echo "✓ $LOCAL updated (backup: $BACKUP)."
echo "  Nothing was restarted. Apply it with:"
echo "    docker compose build && docker compose up -d                 # core services"
echo "    docker compose --profile logs up -d                         # if you run the logs profile"
echo "    docker compose --profile dev up -d ngrok                    # if you use the dev tunnel"
if in_list minio "$RETIRED_FOUND" && grep -q '^  minio:' "$LOCAL"; then
    echo
    echo "  MinIO -> SeaweedFS: storage content must be copied before the app switches over:"
    echo "    docker compose stop minio app queue-worker queue-worker-long scheduler"
    echo "    ./scripts/migrate-minio-to-seaweedfs.sh"
    echo "    # set MINIO_ENDPOINT=http://seaweedfs:8333 in .env, then: docker compose up -d"
    echo "    ./scripts/update-compose.sh      # once more, to remove the minio service"
fi
