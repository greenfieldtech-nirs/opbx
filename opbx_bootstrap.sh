#!/bin/bash
# Replaced by the guided installer. Kept so existing instructions keep working.
set -e
cd "$(dirname "$0")"
echo "opbx_bootstrap.sh has been replaced by ./install.sh; running it now." >&2
exec ./install.sh "$@"
