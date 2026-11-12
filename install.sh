#!/bin/sh
# OPBX installer wrapper: builds the installer image and runs it against the container daemon.
# Usage: ./install.sh [install|reset|reconfigure]
set -eu
cd "$(dirname "$0")"

die() { echo "install.sh: $*" >&2; exit 1; }

mode="${1:-install}"
case "$mode" in install|reset|reconfigure) ;; *) die "unknown mode '$mode' (use install, reset or reconfigure)" ;; esac

command -v docker >/dev/null 2>&1 || die "docker is not installed or not in PATH"
docker version >/dev/null 2>&1 || die "'docker version' failed: is the Docker/Podman daemon running?"
docker compose version >/dev/null 2>&1 || die "'docker compose' is not available: install the Docker Compose plugin"

# Bind sources resolve inside the daemon's host/VM, so mount the socket path as the daemon sees it.
sock=/var/run/docker.sock
if docker version 2>/dev/null | grep -qi podman; then
  sock=$(docker info --format '{{.Host.RemoteSocket.Path}}' 2>/dev/null | sed 's|^unix://||')
  [ -n "$sock" ] || sock=/run/podman/podman.sock
fi
# label=disable: SELinux would otherwise deny the container access to the socket.
docker run --rm --security-opt label=disable -v "$sock:/var/run/docker.sock:ro" alpine test -S /var/run/docker.sock >/dev/null 2>&1 \
  || die "cannot reach the container daemon socket '$sock' from inside a container (is it a socket the daemon host can mount?)"

client_os=$(uname -s | tr '[:upper:]' '[:lower:]')

docker build -q -t opbx-installer:local installer >/dev/null || die "building the installer image failed"

tty=-i
[ -t 0 ] && [ -t 1 ] && tty=-it
exec docker run --rm --init $tty --security-opt label=disable \
  -v "$PWD:$PWD" -w "$PWD" -v "$sock:/var/run/docker.sock" \
  -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" -e OPBX_REPO="$PWD" -e OPBX_CLIENT_OS="$client_os" \
  opbx-installer:local "$mode"
