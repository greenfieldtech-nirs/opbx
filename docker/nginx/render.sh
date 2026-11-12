#!/bin/sh
# Renders nginx templates into /etc/nginx and starts nginx. One script for dev, prod and WAF.
set -e

# Derive the resolver from the container: 127.0.0.11 under Docker, the gateway under Podman.
RESOLVER=$(awk '/^nameserver/ {print $2; exit}' /etc/resolv.conf)
: "${RESOLVER:?could not determine DNS resolver from /etc/resolv.conf}"

WAF_MODE=${WAF_MODE:-off}
WAF_DETECTOR_HOST=${WAF_DETECTOR_HOST:-safeline-detector}
case "$WAF_MODE" in off|monitor|block) ;; *) echo "WAF_MODE must be off|monitor|block (got '$WAF_MODE')" >&2; exit 1;; esac

# Exit 0 only for an IPv4/IPv6 address with optional prefix length.
valid_addr() {
  awk -v e="$1" 'function grp(x,  n,i,g,c){ if (x=="") return 0; n=split(x,g,":"); for(i=1;i<=n;i++) if (g[i] !~ /^[0-9a-fA-F]{1,4}$/) return -1; return n }
  BEGIN {
    n = split(e, pa, "/"); if (n > 2) exit 1
    a = pa[1]; max = 32
    if (n == 2 && pa[2] !~ /^[0-9]{1,3}$/) exit 1
    if (a ~ /^[0-9.]+$/) {
      if (split(a, o, ".") != 4) exit 1
      for (i = 1; i <= 4; i++) if (o[i] !~ /^[0-9]{1,3}$/ || o[i] + 0 > 255) exit 1
    } else if (a ~ /^[0-9a-fA-F:]+$/ && a ~ /:/) {
      max = 128
      if (a ~ /:::/) exit 1
      if (a ~ /::/) {
        i = index(a, "::"); l = substr(a, 1, i - 1); r = substr(a, i + 2)
        if (r ~ /::/) exit 1
        x = grp(l); y = grp(r)
        if (x < 0 || y < 0 || x + y > 7) exit 1
      } else if (grp(a) != 8) exit 1
    } else exit 1
    if (n == 2 && pa[2] + 0 > max) exit 1
  }'
}

# Comma-separated IPv4/IPv6 addresses or CIDRs; prints one per line, exits on a bad entry.
addr_list() { # var-name value
  [ -n "$2" ] || return 0
  set -f # a "*" entry must be reported as itself, not glob-expanded
  for e in $(echo "$2" | tr -d ' ' | tr ',' ' '); do
    valid_addr "$e" || { echo "$1: '$e' is not an IPv4/IPv6 address or CIDR" >&2; exit 1; }
    echo "$e"
  done
  set +f
}
PROXIES=$(addr_list TRUSTED_PROXY_CIDR "${TRUSTED_PROXY_CIDR:-}") || exit 1
CONSOLE_ALLOW=$(addr_list WAF_CONSOLE_ALLOW "${WAF_CONSOLE_ALLOW:-}") || exit 1

mkdir -p /etc/nginx/conf.d /etc/nginx/opbx.d

render() { # src-dir dest-dir
  for f in "$1"/*.conf; do
    [ -e "$f" ] || continue
    sed -e "s/__NGINX_RESOLVER__/${RESOLVER}/g" \
        -e "s/__WAF_MODE__/${WAF_MODE}/g" \
        -e "s/__WAF_DETECTOR_HOST__/${WAF_DETECTOR_HOST}/g" \
        "$f" > "$2/$(basename "$f")"
  done
}

# Stale output from a previous start (restart keeps the container filesystem) must not survive.
rm -f /etc/nginx/conf.d/*.conf /etc/nginx/opbx.d/*.conf

render /etc/nginx/templates/conf.d /etc/nginx/conf.d
# The WAF hook needs Lua, so it is rendered only when the WAF is on (stock nginx can't load it).
[ "$WAF_MODE" = off ] || render /etc/nginx/templates/waf.d /etc/nginx/conf.d

if [ -n "$PROXIES" ]; then
  { echo "$PROXIES" | sed 's/.*/set_real_ip_from &;/'
    echo "real_ip_header X-Forwarded-For;"
    echo "real_ip_recursive on;"; } > /etc/nginx/conf.d/00-real-ip.conf
fi

# SafeLine's console can't live under a path, so it gets its own port. Lazy DNS keeps nginx starting without safeline-mgt.
if [ "$WAF_MODE" != off ] && [ "${WAF_CONSOLE_ENABLED:-}" = true ]; then
  {
    [ -n "$CONSOLE_ALLOW" ] || echo "render.sh: WARNING: WAF console on :8443 has no IP allowlist; only SafeLine's login protects it." >&2
    echo "server {"
    echo "    listen 8443;"
    echo "    resolver ${RESOLVER} valid=10s ipv6=off;"
    [ -z "$CONSOLE_ALLOW" ] || { echo "$CONSOLE_ALLOW" | sed 's/.*/    allow &;/'; echo "    deny all;"; }
    cat <<'EOF'
    location / {
        set $console https://safeline-mgt:1443;
        proxy_pass $console;
        proxy_ssl_verify off;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        client_max_body_size 100m;
    }
}
EOF
  } > /etc/nginx/conf.d/zz-waf-console.conf
fi

if [ -n "$RENDER_ONLY" ]; then
  exec nginx -t
fi
exec nginx -g 'daemon off;'
