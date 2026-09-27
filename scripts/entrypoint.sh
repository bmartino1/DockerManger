#!/bin/bash
set -euo pipefail

STACKS_DIR="${STACKS_DIR:-/opt/stacks}"

echo "[DockerManger] Starting..."
echo "[DockerManger] Architecture: $(uname -m)"
echo "[DockerManger] Stack directory: ${STACKS_DIR}"

# Application directories
mkdir -p \
    "${STACKS_DIR}" \
    /data \
    /run/php

# Docker socket is important, but don't prevent the web UI from starting.
if [ -S /var/run/docker.sock ]; then
    echo "[DockerManger] Docker socket detected."
else
    echo "[DockerManger] WARNING: /var/run/docker.sock is not available."
fi

# Stack directory sanity check.
if [ -d "${STACKS_DIR}" ]; then
    echo "[DockerManger] Stack directory available."
else
    echo "[DockerManger] WARNING: Stack directory is unavailable."
fi

exec "$@"
