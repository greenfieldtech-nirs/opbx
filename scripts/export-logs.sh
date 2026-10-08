#!/usr/bin/env bash
# Export filtered Call Flow (or Platform) logs from Loki to a text file.
#
# Usage:
#   ./scripts/export-logs.sh [options] [output-file]
#
# Options:
#   --type call_flow|platform   Log type (default: call_flow)
#   --session <token>           Filter by Cloudonix session token
#   --text <string>             Free-text filter
#   --all                       Include entries without a session token
#                               (default: sessions only, like the dashboard)
#   --since <duration>          Lookback window (default: 24h; e.g. 1h, 7d)
#
# Examples:
#   ./scripts/export-logs.sh call.txt
#   ./scripts/export-logs.sh --session sess-abc123 call.txt
#   ./scripts/export-logs.sh --type platform --since 1h platform.txt
set -euo pipefail

TYPE="call_flow"
SESSION=""
TEXT=""
SESSIONS_ONLY='| session_token != ""'
SINCE="24h"
OUT=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --type)    TYPE="$2"; shift 2 ;;
    --session) SESSION="$2"; shift 2 ;;
    --text)    TEXT="$2"; shift 2 ;;
    --all)     SESSIONS_ONLY='|~ ""'; shift ;;
    --since)   SINCE="$2"; shift 2 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *)         OUT="$1"; shift ;;
  esac
done

OUT="${OUT:-opbx-logs-${TYPE}-$(date +%Y%m%d-%H%M%S).txt}"

# Same rendering as the dashboard: [level] [session_token] message
QUERY=$(printf '{log_type="%s"} |~ "%s" |~ "%s" | json %s | line_format "{{ if .level }}[{{ .level }}] {{ end }}{{ if .session_token }}[{{ .session_token }}] {{ end }}{{ if .msg }}{{ .msg }}{{ else }}(non-JSON log line){{ end }}"' \
  "$TYPE" "$SESSION" "$TEXT" "$SESSIONS_ONLY")

echo "Querying Loki (type=$TYPE, since=$SINCE)..." >&2
docker exec opbx_loki wget -q -O - \
  "http://localhost:3100/loki/api/v1/query_range?query=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$QUERY")&limit=5000&since=$SINCE&direction=forward" \
| python3 -c "
import json, sys, datetime
d = json.load(sys.stdin)
lines = []
for r in d['data']['result']:
    for ts, line in r['values']:
        ns = int(ts)
        t = datetime.datetime.fromtimestamp(ns / 1e9, datetime.UTC)
        lines.append((ns, f'{t:%Y-%m-%d %H:%M:%S}.{ns % 10**9 // 10**6:03d}  {line}'))
lines.sort()
print('\n'.join(l for _, l in lines))
" > "$OUT"

echo "Wrote $(wc -l < "$OUT" | tr -d ' ') lines to $OUT" >&2
