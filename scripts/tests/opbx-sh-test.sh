#!/usr/bin/env bash
# Tests ./opbx.sh against a fake `docker` that records every call (no real containers).
# Run: scripts/tests/opbx-sh-test.sh
set -u
ROOT="$(cd "$(dirname "$0")/../.." >/dev/null && pwd)"
fail=0
pass() { echo "PASS $1"; }
bad() { echo "FAIL $1"; fail=1; }

# A throwaway checkout: opbx.sh, a .env, docker-compose.yml, optionally SafeLine's .env, and a fake docker.
setup() { # $1: .env contents, $2: "safeline" to create docker/safeline/.env
    T="$(mktemp -d)"
    cp "$ROOT/opbx.sh" "$T/"
    printf '%s\n' "$1" > "$T/.env"
    : > "$T/docker-compose.yml"
    mkdir -p "$T/bin" "$T/docker/safeline"
    [ "${2:-}" = safeline ] && : > "$T/docker/safeline/.env"
    cat > "$T/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "CF=${COMPOSE_FILE:-} PL=${COMPOSE_PARALLEL_LIMIT:-} | $*" >> "$FAKE_LOG"
case "$*" in
    "compose version") exit 0 ;;
    *"config --services"*) printf '%s\n' ${FAKE_SERVICES:-app nginx} ;;
    *"ps --all --format"*) printf '%b' "${FAKE_PS:-app running healthy\nnginx running \n}" ;;
    inspect*) echo "${FAKE_STATE:-running healthy}" ;;
    *"-p safeline ps -q"*) echo "${FAKE_SAFELINE_RUNNING:-}" ;;
esac
exit 0
EOF
    chmod +x "$T/bin/docker"
    export FAKE_LOG="$T/calls.log"
    : > "$FAKE_LOG"
}
run() { (cd "$T" && PATH="$T/bin:$PATH" START_TIMEOUT=0 ./opbx.sh "$@" > "$T/out.log" 2>&1); }
calls() { grep -v -e '| compose version' "$FAKE_LOG"; }
line_of() { calls | grep -n -- "$1" | head -1 | cut -d: -f1; }

WAF_ENV='COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml:docker-compose.waf.yml
COMPOSE_PROFILES=logs
WAF_MODE=monitor
APP_URL=http://localhost'

# 1. WAF on: SafeLine starts and is waited for before OPBX; core services before the rest.
setup "$WAF_ENV" safeline
if run start; then
    sl=$(line_of '-p safeline up -d'); det=$(line_of 'inspect .* safeline-detector'); core=$(line_of 'up -d mysql redis seaweedfs app')
    app=$(line_of 'inspect .* opbx_app'); rest=$(calls | grep -n -- 'compose up -d$' | head -1 | cut -d: -f1)
    if [ -n "$sl" ] && [ -n "$rest" ] && [ "$sl" -lt "$det" ] && [ "$det" -lt "$core" ] && [ "$core" -lt "$app" ] && [ "$app" -lt "$rest" ]; then pass "start with WAF: SafeLine, detector, core, app, rest"; else bad "start order"; calls; fi
    calls | grep -- '-p safeline up -d' | grep -q '^CF= PL' && pass "SafeLine does not see OPBX's COMPOSE_FILE" || bad "SafeLine got COMPOSE_FILE"
    calls | grep -- 'up -d mysql' | grep -q 'CF=docker-compose.yml:docker-compose.ports.yml:docker-compose.waf.yml ' && pass "OPBX uses COMPOSE_FILE from .env" || bad "COMPOSE_FILE not passed"
    grep -q 'Grafana:      http://localhost/logs/' "$T/out.log" && pass "status URLs include Grafana with the logs profile" || bad "urls"
else bad "start with WAF exited non-zero"; cat "$T/out.log"; fi

# 2. --no-waf: overlay dropped for this run only, SafeLine untouched, .env unchanged.
setup "$WAF_ENV" safeline
before=$(cat "$T/.env")
if run start --no-waf; then
    calls | grep -q -- '-p safeline' && bad "--no-waf touched SafeLine" || pass "--no-waf does not start SafeLine"
    calls | grep -- 'compose up -d$' | grep -q 'CF=docker-compose.yml:docker-compose.ports.yml PL' && pass "--no-waf drops only the WAF overlay" || { bad "--no-waf files"; calls; }
    [ "$(cat "$T/.env")" = "$before" ] && pass "--no-waf leaves .env unchanged" || bad ".env changed"
else bad "start --no-waf exited non-zero"; cat "$T/out.log"; fi

# 3. stop: OPBX (all profiles) with stop, then SafeLine; never down.
setup "$WAF_ENV" safeline
FAKE_SAFELINE_RUNNING=abc123 run stop
calls | grep -q ' down' && bad "stop used down" || pass "stop never uses down"
o=$(line_of "--profile \* stop"); s=$(line_of '-p safeline stop')
[ -n "$o" ] && [ -n "$s" ] && [ "$o" -lt "$s" ] && pass "stop: OPBX (every profile), then SafeLine" || { bad "stop order"; calls; }

# 4. Hand-made .env without COMPOSE_FILE: --prod adds the prod file; no WAF.
setup 'APP_URL=https://pbx.example.com'
run start --prod
calls | grep -- 'up -d mysql' | grep -q 'CF=docker-compose.yml:docker-compose.prod.yml PL' && pass "hand-made .env + --prod" || { bad "--prod"; calls; }
calls | grep -q safeline && bad "hand-made .env started SafeLine" || pass "hand-made .env: no WAF"
calls | grep -- 'up -d mysql' | grep -q 'PL=2 ' && pass "no COMPOSE_PARALLEL_LIMIT in .env: defaults to 2" || { bad "parallel limit default"; calls; }
setup 'COMPOSE_PARALLEL_LIMIT=4'
run start
calls | grep -- 'up -d mysql' | grep -q 'PL= ' && pass "COMPOSE_PARALLEL_LIMIT in .env is left to compose" || { bad "parallel limit override"; calls; }

# 5. WAF on but SafeLine never set up: refuse before starting anything.
setup "$WAF_ENV"
if run start; then bad "missing docker/safeline/.env did not fail"; else
    calls | grep -q 'up -d' && bad "started something anyway" || pass "WAF without docker/safeline/.env stops before starting anything"
    grep -q 'reconfigure' "$T/out.log" && pass "and points to reconfigure / --no-waf" || bad "message"
fi

# 6. A service that does not come up fails the start and is named.
setup "$WAF_ENV" safeline
if FAKE_PS='app running healthy\nnginx exited\n' run start; then bad "exited nginx not reported"; else
    grep -q 'nginx(exited)' "$T/out.log" && pass "not-ready service is named" || { bad "not-ready message"; cat "$T/out.log"; }
fi

# 7. A failing compose query is never reported as "all up".
setup "$WAF_ENV" safeline
cat > "$T/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "CF=${COMPOSE_FILE:-} | $*" >> "$FAKE_LOG"
case "$*" in
    "compose version") exit 0 ;;
    inspect*) echo "running healthy"; exit 0 ;;
    *"config --services"*) echo "yaml: broken" >&2; exit 1 ;;
esac
exit 0
EOF
if run status; then
    grep -q 'All services are up' "$T/out.log" && bad "failed query reported as all up" || pass "failed compose query is not 'all up'"
    grep -q 'docker-compose-query-failed(yaml: broken' "$T/out.log" && pass "and shows the error" || { bad "query error text"; cat "$T/out.log"; }
else bad "status exited non-zero"; fi

# 8. upgrade: build, then composer inside the app image as the host user (no-dev in production), then the start.
setup "$WAF_ENV
APP_ENV=production" safeline
if run upgrade; then
    b=$(line_of 'build --pull'); c=$(line_of '--entrypoint composer app install'); d=$(line_of 'package:discover'); u=$(line_of 'up -d mysql')
    [ -n "$b" ] && [ -n "$c" ] && [ "$b" -lt "$c" ] && [ "$c" -lt "$d" ] && [ "$d" -lt "$u" ] && pass "upgrade: build, composer, package discovery, start" || { bad "upgrade order"; calls; }
    calls | grep -- 'composer app install' | grep -q -- "--user $(id -u):$(id -g) " && pass "composer runs as the host user" || bad "composer user"
    calls | grep -- 'composer app install' | grep -q -- '--no-dev' && pass "production: --no-dev" || bad "no-dev"
else bad "upgrade exited non-zero"; cat "$T/out.log"; fi
setup "$WAF_ENV
APP_ENV=local" safeline
run upgrade
calls | grep -- 'composer app install' | grep -q -- '--no-dev' && bad "development got --no-dev" || pass "development keeps dev packages"

# 9. Unknown commands and options are refused.
setup "$WAF_ENV" safeline
run frobnicate && bad "unknown command accepted" || pass "unknown command refused"
run start --nope && bad "unknown option accepted" || pass "unknown option refused"

# 10. --nofork: the ordered, health-checked start, then `up` attached; when it returns (Ctrl-C), everything stops.
setup "$WAF_ENV" safeline
if FAKE_SAFELINE_RUNNING=abc123 run start --nofork; then
    rest=$(calls | grep -n -- 'compose up -d$' | head -1 | cut -d: -f1); fg=$(line_of 'compose up$')
    o=$(line_of "--profile \* stop"); s=$(line_of '-p safeline stop')
    [ -n "$rest" ] && [ -n "$fg" ] && [ "$rest" -lt "$fg" ] && pass "--nofork: detached start first, then attached up" || { bad "--nofork up order"; calls; }
    [ -n "$o" ] && [ -n "$s" ] && [ "$fg" -lt "$o" ] && [ "$o" -lt "$s" ] && pass "--nofork: leaving the foreground stops OPBX, then SafeLine" || { bad "--nofork stop"; calls; }
else bad "start --nofork exited non-zero"; cat "$T/out.log"; fi
setup "$WAF_ENV" safeline
run start
calls | grep -q -- 'compose up$' && bad "plain start attached to the stack" || pass "plain start stays detached"
calls | grep -q -- ' stop' && bad "plain start stopped something" || pass "plain start stops nothing"

# 11. --with-ngrok: the dev profile (ngrok) for this run only; refused without NGROK_AUTHTOKEN.
setup "$WAF_ENV
NGROK_AUTHTOKEN=tok" safeline
before=$(cat "$T/.env")
if run start --with-ngrok; then
    calls | grep -- ' up -d$' | grep -q -- '--profile dev' && pass "--with-ngrok starts the dev profile" || { bad "--with-ngrok profile"; calls; }
    calls | grep -- 'config --services' | grep -q -- '--profile dev' && pass "--with-ngrok waits for ngrok too" || { bad "--with-ngrok wait"; calls; }
    [ "$(cat "$T/.env")" = "$before" ] && pass "--with-ngrok leaves .env unchanged" || bad ".env changed"
else bad "start --with-ngrok exited non-zero"; cat "$T/out.log"; fi
run start
calls | grep -- 'compose up -d$' | grep -q -- '--profile dev' && bad "plain start enabled ngrok" || pass "plain start leaves ngrok off"
setup "$WAF_ENV" safeline
if run start --with-ngrok; then bad "--with-ngrok without NGROK_AUTHTOKEN accepted"; else
    calls | grep -q 'up -d' && bad "started something anyway" || pass "--with-ngrok without NGROK_AUTHTOKEN stops before starting anything"
    grep -q 'NGROK_AUTHTOKEN' "$T/out.log" && pass "and names NGROK_AUTHTOKEN" || bad "ngrok message"
fi

exit $fail
