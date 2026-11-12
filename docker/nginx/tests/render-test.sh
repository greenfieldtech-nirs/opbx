#!/usr/bin/env bash
# Renders each nginx config combination in a throwaway container and checks nginx -t.
# Upstream names don't resolve outside the stack, so stub them with --add-host.
set -u
cd "$(dirname "$0")/../../.."
DOCKER=${DOCKER:-docker}
HOSTS=""
for h in app frontend soketi amd-worker mcp-server grafana safeline-detector; do HOSTS="$HOSTS --add-host $h:127.0.0.1"; done
fail=0

run() { # name image mount-conf.d-dir waf-dir|"" env...
  local name=$1 image=$2 confd=$3 waf=$4; shift 4
  local args=(--rm $HOSTS -e RENDER_ONLY=1 "$@"
    -v "$PWD/docker/nginx/nginx.conf:/etc/nginx/nginx.conf:ro"
    -v "$PWD/docker/nginx/$confd:/etc/nginx/templates/conf.d:ro"
    -v "$PWD/docker/nginx/render.sh:/opt/opbx/render.sh:ro")
  [ -n "$waf" ] && args+=(-v "$PWD/docker/nginx/$waf:/etc/nginx/templates/waf.d:ro")
  # shellcheck disable=SC2016
  if out=$($DOCKER run "${args[@]}" "$image" /bin/sh -c '/bin/sh /opt/opbx/render.sh && ! grep -rl "__[A-Z_]*__" /etc/nginx/conf.d' 2>&1); then
    echo "PASS $name"
  else
    echo "FAIL $name"; echo "$out" | tail -15; fail=1
  fi
}

$DOCKER build -q -t opbx-nginx-waf:test -f docker/nginx/Dockerfile.waf docker/nginx >/dev/null || { echo "FAIL build waf image"; exit 1; }

run dev nginx:1.27-alpine conf.d ""
run prod nginx:1.27-alpine conf.d.prod ""
run "prod+waf block" opbx-nginx-waf:test conf.d.prod waf -e WAF_MODE=block
run "waf off on stock image" nginx:1.27-alpine conf.d.prod waf -e WAF_MODE=off

out=$($DOCKER run --rm $HOSTS -e RENDER_ONLY=1 -e WAF_MODE=bogus -v "$PWD/docker/nginx/render.sh:/opt/opbx/render.sh:ro" \
  nginx:1.27-alpine /bin/sh /opt/opbx/render.sh 2>&1); rc=$?
if [ $rc -ne 0 ] && echo "$out" | grep -q "WAF_MODE must be"; then echo "PASS bogus WAF_MODE rejected"
else echo "FAIL bogus WAF_MODE accepted"; fail=1; fi
exit $fail
