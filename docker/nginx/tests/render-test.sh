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

# check name "assertion shell run after render (nginx -t already ran)" waf-mount(y|n) env...
check() {
  local name=$1 assertion=$2 waf=$3; shift 3
  local args=(--rm $HOSTS -e RENDER_ONLY=1 "$@"
    -v "$PWD/docker/nginx/nginx.conf:/etc/nginx/nginx.conf:ro"
    -v "$PWD/docker/nginx/conf.d.prod:/etc/nginx/templates/conf.d:ro"
    -v "$PWD/docker/nginx/waf:/etc/nginx/templates/waf.d:ro"
    -v "$PWD/docker/nginx/render.sh:/opt/opbx/render.sh:ro")
  local image=nginx:1.27-alpine; [ "$waf" = y ] && image=opbx-nginx-waf:test
  if out=$($DOCKER run "${args[@]}" "$image" /bin/sh -c "/bin/sh /opt/opbx/render.sh >/tmp/o 2>&1 || { cat /tmp/o; exit 1; }; $assertion" 2>&1); then
    echo "PASS $name"
  else
    echo "FAIL $name"; echo "$out" | tail -15; fail=1
  fi
}
reject() { # name env-assignment expected-bad-value
  out=$($DOCKER run --rm $HOSTS -e RENDER_ONLY=1 -e "$2" -v "$PWD/docker/nginx/render.sh:/opt/opbx/render.sh:ro" \
    nginx:1.27-alpine /bin/sh /opt/opbx/render.sh 2>&1); rc=$?
  if [ $rc -ne 0 ] && echo "$out" | grep -qF "$3"; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi
}

R=/etc/nginx/conf.d/00-real-ip.conf
C=/etc/nginx/conf.d/zz-waf-console.conf
check "realip rendered" "[ \"\$(grep -c set_real_ip_from $R)\" = 2 ] && grep -qx 'set_real_ip_from 10.0.0.0/8;' $R && grep -q 'set_real_ip_from 192.168.1.5;' $R && grep -q 'real_ip_header X-Forwarded-For;' $R && grep -q 'real_ip_recursive on;' $R" n -e TRUSTED_PROXY_CIDR=10.0.0.0/8,192.168.1.5
check "realip ipv6 + spaces" "grep -q 'set_real_ip_from fd00::/8;' $R && grep -q 'set_real_ip_from 172.16.0.0/12;' $R" n -e "TRUSTED_PROXY_CIDR=fd00::/8, 172.16.0.0/12"
check "realip off when empty" "[ ! -e $R ]" n -e TRUSTED_PROXY_CIDR=
check "console off by default" "[ ! -e $C ]" y -e WAF_MODE=block
check "console off when WAF off" "[ ! -e $C ]" n -e WAF_CONSOLE_ENABLED=true
check "console on, allow-list" "grep -q 'listen 8443;' $C && grep -q 'allow 198.51.100.0/24;' $C && grep -q 'deny all;' $C && grep -q 'proxy_ssl_verify off;' $C && grep -q 'proxy_pass \$console;' $C" y -e WAF_MODE=monitor -e WAF_CONSOLE_ENABLED=true -e WAF_CONSOLE_ALLOW=198.51.100.0/24
# The installer's own check fetches the console from 127.0.0.1 inside nginx: an allowlist must not deny it.
# safeline-mgt does not resolve here, so "let in" means a 50x from the proxy, not a 403 from the allowlist.
check "console allow-list lets 127.0.0.1 in" "[ \"\$(grep -m1 'allow ' $C)\" = '    allow 127.0.0.1;' ] && nginx && r=\$(wget -qO- http://127.0.0.1:8443/ 2>&1); echo \"\$r\"; echo \"\$r\" | grep -q ' 50[0-9]' && ! echo \"\$r\" | grep -q 403" y -e WAF_MODE=monitor -e WAF_CONSOLE_ENABLED=true -e WAF_CONSOLE_ALLOW=198.51.100.0/24
check "console on, no allow-list" "grep -q 'listen 8443;' $C && ! grep -q 'deny all' $C" y -e WAF_MODE=block -e WAF_CONSOLE_ENABLED=true
reject "bad TRUSTED_PROXY_CIDR rejected" "TRUSTED_PROXY_CIDR=10.0.0.0/8,evil;x" "evil;x"
reject "bad octet-count rejected" "TRUSTED_PROXY_CIDR=10.0.0/8" "10.0.0/8"
reject "rejects 999.1.1.1" "TRUSTED_PROXY_CIDR=999.1.1.1" "999.1.1.1"
reject "rejects 1.2.3.4/99" "TRUSTED_PROXY_CIDR=1.2.3.4/99" "1.2.3.4/99"
reject "rejects ::::" "TRUSTED_PROXY_CIDR=::::" "::::"
reject "rejects abc:" "TRUSTED_PROXY_CIDR=abc:" "abc:"
reject "rejects dead:beef" "TRUSTED_PROXY_CIDR=dead:beef" "dead:beef"
reject "rejects :" "TRUSTED_PROXY_CIDR=:" ":"
reject "rejects *" "TRUSTED_PROXY_CIDR=*" "*"
check "valid entries accepted" "grep -c set_real_ip_from $R | grep -qx 5" n -e TRUSTED_PROXY_CIDR=10.0.0.0/8,192.168.1.5,fd00::/8,2001:db8::1,::1
out=$($DOCKER run --rm $HOSTS -e RENDER_ONLY=1 -e WAF_MODE=monitor -e WAF_CONSOLE_ENABLED=true -v "$PWD/docker/nginx/render.sh:/opt/opbx/render.sh:ro" opbx-nginx-waf:test /bin/sh /opt/opbx/render.sh 2>&1)
if echo "$out" | grep -q "no IP allowlist"; then echo "PASS console warns without allowlist"; else echo "FAIL console warns without allowlist"; fail=1; fi
reject "bad WAF_CONSOLE_ALLOW rejected" "WAF_CONSOLE_ALLOW=1.2.3.4/abc" "1.2.3.4/abc"
exit $fail
