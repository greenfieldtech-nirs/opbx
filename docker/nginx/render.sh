#!/bin/sh
# Renders nginx templates into /etc/nginx and starts nginx. One script for dev, prod and WAF.
set -e

# Derive the resolver from the container: 127.0.0.11 under Docker, the gateway under Podman.
RESOLVER=$(awk '/^nameserver/ {print $2; exit}' /etc/resolv.conf)
: "${RESOLVER:?could not determine DNS resolver from /etc/resolv.conf}"

WAF_MODE=${WAF_MODE:-off}
WAF_DETECTOR_HOST=${WAF_DETECTOR_HOST:-safeline-detector}
case "$WAF_MODE" in off|monitor|block) ;; *) echo "WAF_MODE must be off|monitor|block (got '$WAF_MODE')" >&2; exit 1;; esac

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

if [ -n "$RENDER_ONLY" ]; then
  exec nginx -t
fi
exec nginx -g 'daemon off;'
