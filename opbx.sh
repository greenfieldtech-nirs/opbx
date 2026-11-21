#!/bin/bash
#
# Start, stop and upgrade the OPBX stack in the right order.
#
# Usage (from the repo root):
#   ./opbx.sh start [options]       start everything; SafeLine (the WAF) first when it is on
#   ./opbx.sh stop                  stop OPBX, then SafeLine. Containers, network and data are kept
#   ./opbx.sh restart [options]     stop, then start
#   ./opbx.sh status                state and health of every service, the WAF, and the URLs
#   ./opbx.sh logs [service...]     follow the logs (all services, or the ones named)
#   ./opbx.sh upgrade [options]     after `git pull` and ./scripts/update-compose.sh: copies MinIO data to
#                                   SeaweedFS once, rebuilds with fresh base images, installs the PHP packages
#                                   inside the app image, starts in order
#
# Options:
#   --no-waf   this run only: plain nginx, SafeLine not started. .env is not changed; to turn the WAF off
#              for good use ./install.sh reconfigure.
#   --prod     only for a hand-made .env without COMPOSE_FILE: add docker-compose.prod.yml.
#              (An installer-made .env lists its compose files in COMPOSE_FILE, which always wins.)
#   --nofork   stay in the foreground once everything is up, showing every service's log. Ctrl-C stops
#              the stack (OPBX, then SafeLine) the same way ./opbx.sh stop does.
#   --with-ngrok  this run only: also start the ngrok tunnel (the dev profile). Needs NGROK_AUTHTOKEN in .env.
#
# START_TIMEOUT (seconds, default 300) bounds the wait for the services; UPGRADE_TIMEOUT (default 1800)
# bounds the app's first start after an upgrade (database migrations can be slow).
#
# Why `stop` and not `down`: down deletes the Docker network. Recreating it can leave a stale host firewall
# rule behind that silently drops traffic to a container (seen with Docker 29). To remove everything and start
# over, use ./install.sh reset.

set -euo pipefail
cd "$(dirname "$0")" >/dev/null

usage() { sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
die() { echo "❌ $*" >&2; exit 1; }

cmd="${1:-}"
[ $# -gt 0 ] && shift
NO_WAF=false
PROD=false
NOFORK=false
WITH_NGROK=false
ARGS=()
for a in "$@"; do
    case "$a" in
        --no-waf) NO_WAF=true ;;
        --prod) PROD=true ;;
        --nofork) NOFORK=true ;;
        --with-ngrok) WITH_NGROK=true ;;
        -h|--help) usage ;;
        -*) die "Unknown option: $a (see ./opbx.sh --help)" ;;
        *) ARGS+=("$a") ;;
    esac
done
case "$cmd" in
    start|stop|restart|status|logs|upgrade) ;;
    ""|-h|--help|help) usage ;;
    *) echo "Unknown command: $cmd" >&2; usage 2 ;;
esac

command -v docker >/dev/null 2>&1 || die "docker was not found. Install Docker (or Podman with its docker CLI)."
docker compose version >/dev/null 2>&1 || die "'docker compose' is not available. Install the Docker Compose plugin."
[ -f .env ] || die ".env not found. Run ./install.sh first."
[ -f docker-compose.yml ] || die "docker-compose.yml not found. Run ./install.sh (or copy docker-compose.yml.example)."

# Last value of KEY in .env, without quotes or a trailing comment.
env_get() { sed -n "s/^$1=//p" .env | tail -1 | sed -e 's/[[:space:]]#.*$//' -e "s/^[\"']//" -e "s/[\"']\$//"; }

# Parallel image builds drop the network under Podman's VM and hang; the installer writes 2 to .env.
if [ -z "$(env_get COMPOSE_PARALLEL_LIMIT)" ] && [ -z "${COMPOSE_PARALLEL_LIMIT:-}" ]; then export COMPOSE_PARALLEL_LIMIT=2; fi

WAF_OVERLAY=docker-compose.waf.yml
LOG_PID=""   # the app log follower during an upgrade (global: the EXIT trap needs it)
FILES="$(env_get COMPOSE_FILE)"
if [ -n "$FILES" ]; then
    if $PROD; then echo "ℹ --prod ignored: COMPOSE_FILE in .env already lists the compose files."; fi
else
    FILES=docker-compose.yml
    if $PROD; then FILES="$FILES:docker-compose.prod.yml"; fi
fi
WAF=false
case ":$FILES:" in *":$WAF_OVERLAY:"*) WAF=true ;; esac
WAF_SKIPPED=false
if $WAF && $NO_WAF; then
    FILES="$(printf '%s\n' "$FILES" | tr ':' '\n' | grep -vx "$WAF_OVERLAY" | paste -sd: -)"
    WAF=false
    WAF_SKIPPED=true
fi

# OPBX compose with the chosen files (overrides COMPOSE_FILE from .env for this run only).
OPBX=(env "COMPOSE_FILE=$FILES" docker compose)
if $WITH_NGROK; then
    [ -n "$(env_get NGROK_AUTHTOKEN)" ] || [ -n "${NGROK_AUTHTOKEN:-}" ] \
        || die "--with-ngrok needs NGROK_AUTHTOKEN in .env (https://dashboard.ngrok.com/get-started/your-authtoken)."
    OPBX+=(--profile dev)   # ngrok is the only dev-profile service
fi
# SafeLine is its own project; it reads docker/safeline/.env and must not see OPBX's COMPOSE_FILE.
SAFELINE=(env -u COMPOSE_FILE -u COMPOSE_PROFILES docker compose --project-directory docker/safeline -p safeline)
safeline_configured() { [ -f docker/safeline/.env ]; }

# Waits until a container is running and, when it has a health check, healthy.
wait_container() {
    local name=$1 timeout=$2 started state
    started=$(date +%s)
    while :; do
        state="$(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$name" 2>/dev/null || echo missing)"
        case "$state" in
            "running healthy"|"running ") return 0 ;;
            "running unhealthy")
                echo "❌ $name is unhealthy. Last log lines:"
                docker logs --tail 30 "$name" 2>&1 | sed 's/^/  │ /' || true
                return 1 ;;
            exited*|dead*|missing)
                echo "❌ $name is not running ($state). Last log lines:"
                docker logs --tail 30 "$name" 2>&1 | sed 's/^/  │ /' || true
                return 1 ;;
        esac
        if [ $(( $(date +%s) - started )) -ge "$timeout" ]; then
            echo "❌ $name was not ready after ${timeout}s (state: $state)."
            return 1
        fi
        sleep 3
    done
}

# Services of the active compose files and profiles that are not running/healthy, e.g. "frontend(exited)".
# A failing query is reported as not ready (with its error), never as "all up".
not_ready() {
    local services ps svc line bad="" err
    err="$(mktemp)"
    if ! services="$("${OPBX[@]}" config --services 2>"$err")" \
        || ! ps="$("${OPBX[@]}" ps --all --format '{{.Service}} {{.State}} {{.Health}}' 2>"$err")"; then
        printf 'docker-compose-query-failed(%s)' "$(grep -v 'external compose provider' "$err" | tail -2 | tr '\n' ' ')"
        rm -f "$err"
        return 0
    fi
    rm -f "$err"
    ps="$(printf '%s\n' "$ps" | sed 's/ *$//')"
    [ -n "$services" ] || { printf 'no-services-in-compose-config'; return 0; }
    for svc in $services; do
        line="$(printf '%s\n' "$ps" | awk -v s="$svc" '$1 == s' | head -1)"
        case "$line" in
            "$svc running healthy"|"$svc running") ;;
            "") bad="$bad $svc(no container)" ;;
            *) bad="$bad $svc(${line#"$svc "})" ;;
        esac
    done
    printf '%s' "${bad# }"
}

wait_all() {
    local timeout=$1 started bad
    started=$(date +%s)
    echo "▶ Waiting for every service to be up..."
    while :; do
        bad="$(not_ready)"
        [ -z "$bad" ] && { echo "✓ All services are up."; return 0; }
        if [ $(( $(date +%s) - started )) -ge "$timeout" ]; then
            echo "❌ Not ready after ${timeout}s: $bad"
            echo "  Check with: ./opbx.sh logs <service>"
            return 1
        fi
        sleep 5
    done
}

urls() {
    local app port profiles
    app="$(env_get APP_URL)"; app="${app:-http://localhost}"
    echo "  Web app:      $app"
    if $WAF; then
        port="$(env_get WAF_CONSOLE_PORT)"
        echo "  WAF console:  port ${port:-9443} on this machine ($(env_get WAF_MODE || true) mode)"
    fi
    profiles="$(env_get COMPOSE_PROFILES)"
    case ",$profiles," in *,logs,*) echo "  Grafana:      $app/logs/" ;; esac
    if $WITH_NGROK; then echo "  ngrok:        public URL at http://localhost:4040 (or ./opbx.sh logs ngrok)"; fi
    if $WAF_SKIPPED; then echo "  WAF:          skipped for this run (--no-waf); the next start brings it back"; fi
}

start_safeline() {
    safeline_configured || die "The WAF is on in .env, but docker/safeline/.env is missing. Run ./install.sh reconfigure, or start with --no-waf."
    echo "▶ Starting SafeLine (the WAF)..."
    "${SAFELINE[@]}" up -d
    wait_container safeline-detector 300 || die "SafeLine did not come up. Check: docker logs safeline-detector (or start with --no-waf)."
    echo "✓ SafeLine is up."
}

# $1: seconds to wait for the app; $2: true to show the app's log while it starts.
start_stack() {
    local app_timeout=$1 follow=$2 since
    if $WAF; then start_safeline; fi
    echo "▶ Starting the database, Redis, SeaweedFS and the app..."
    since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    "${OPBX[@]}" up -d mysql redis seaweedfs app
    echo "▶ Waiting for the app (migrations and storage checks run first; a first start can take minutes)..."
    if $follow; then
        # Process substitution, so $! is `docker logs` itself and stopping it ends the follower quietly.
        docker logs --since "$since" -f opbx_app > >(sed 's/^/  │ /') 2>&1 &
        LOG_PID=$!
        trap 'kill $LOG_PID 2>/dev/null || true' EXIT
    fi
    if ! wait_container opbx_app "$app_timeout"; then
        [ -n "$LOG_PID" ] && kill "$LOG_PID" 2>/dev/null || true
        die "The app did not start. Check: ./opbx.sh logs app"
    fi
    [ -n "$LOG_PID" ] && kill "$LOG_PID" 2>/dev/null || true
    echo "✓ The app is ready."
    echo "▶ Starting the rest of the stack..."
    "${OPBX[@]}" up -d
    wait_all "${START_TIMEOUT:-300}" || exit 1
    if $WAF_SKIPPED && safeline_configured && [ -n "$(docker ps -q --filter name=^safeline-detector$)" ]; then
        echo "ℹ SafeLine is still running but not in use; ./opbx.sh stop stops it."
    fi
    echo "✓ OPBX is running."
    urls
    if $NOFORK; then
        echo "▶ Showing the logs of every service. Ctrl-C stops the stack."
        # compose handles Ctrl-C itself; the trap only keeps this script alive to stop SafeLine afterwards
        trap ':' INT
        "${OPBX[@]}" up || true
        trap - INT
        stop_stack
    fi
}

# The repo is bind-mounted over the app image's vendor/, so PHP packages live on the host. Composer runs inside
# the app image as the host user (vendor/ stays yours) without scripts; root then refreshes bootstrap/cache, which
# belongs to www-data after the first start. Same steps as the installer (installer/src/stack.js composerInstall).
composer_install() {
    local nodev=()
    [ "$(env_get APP_ENV)" = production ] && nodev=(--no-dev)
    echo "▶ Installing the PHP packages (composer, inside the app image)..."
    "${OPBX[@]}" run --rm --no-deps --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer --entrypoint composer \
        app install --no-interaction --prefer-dist ${nodev[@]+"${nodev[@]}"} --optimize-autoloader --no-scripts \
        || die "composer install failed (see above)."
    "${OPBX[@]}" run --rm --no-deps --entrypoint sh app -c \
        'rm -f bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php && php artisan package:discover --ansi' \
        || die "Laravel package discovery failed (see above)."
    echo "✓ PHP packages installed."
}

stop_stack() {
    echo "▶ Stopping OPBX..."
    # every profile, so services started by hand (ngrok, the log stack) stop too
    "${OPBX[@]}" --profile '*' stop
    if safeline_configured && [ -n "$("${SAFELINE[@]}" ps -q 2>/dev/null)" ]; then
        echo "▶ Stopping SafeLine..."
        "${SAFELINE[@]}" stop
    fi
    echo "✓ Stopped. Data and containers are kept; ./opbx.sh start brings everything back."
}

case "$cmd" in
    start) start_stack "${START_TIMEOUT:-300}" false ;;
    stop) stop_stack ;;
    restart) stop_stack; start_stack "${START_TIMEOUT:-300}" false ;;
    status)
        "${OPBX[@]}" --profile '*' ps
        if safeline_configured; then
            echo; echo "SafeLine:"; "${SAFELINE[@]}" ps --format '  {{.Name}} {{.State}} {{.Health}}' || true
        fi
        echo; echo "WAF: $($WAF && echo "on ($(env_get WAF_MODE) mode)" || echo off)"
        bad="$(not_ready)"
        if [ -z "$bad" ]; then echo "✓ All services are up."; else echo "Not running/healthy: $bad"; fi
        urls ;;
    logs) "${OPBX[@]}" logs -f --tail 100 ${ARGS[@]+"${ARGS[@]}"} ;;
    upgrade)
        MINIO_MARKER="volumes/.minio-migrated-to-seaweedfs"
        if [ -d volumes/minio ] && [ -n "$(ls -A volumes/minio 2>/dev/null | grep -v '^\.minio\.sys$' || true)" ] && [ ! -f "$MINIO_MARKER" ]; then
            echo "▶ Copying MinIO data to SeaweedFS (this can take a long time with many recordings)..."
            ./scripts/migrate-minio-to-seaweedfs.sh
            date -u +%Y-%m-%dT%H:%M:%SZ > "$MINIO_MARKER"
            echo "✓ MinIO data migrated (recorded in $MINIO_MARKER)."
        fi
        echo "▶ Building the images (pulling fresh base images)..."
        "${OPBX[@]}" build --pull
        composer_install
        start_stack "${UPGRADE_TIMEOUT:-1800}" true
        echo "✓ Upgrade complete." ;;
esac
