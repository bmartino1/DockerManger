#!/bin/bash

# ============================================================================
# DockerManger Container Entrypoint
# ============================================================================
#
# Performs lightweight container initialization before handing control to
# Phusion Baseimage / runit.
#
# Application and Docker management logic does NOT belong here.
# ============================================================================

set -euo pipefail

STACKS_DIR="${STACKS_DIR:-/opt/stacks}"

echo "============================================================"
echo " DockerManger"
echo "============================================================"
echo "Architecture : $(uname -m)"
echo "Stacks       : ${STACKS_DIR}"
echo "Timezone     : ${TZ:-not configured}"
echo "============================================================"

# Runtime directories.
mkdir -p \
    /data \
    /run/php \
    "${STACKS_DIR}"

# Docker socket is required for Docker management but its absence should not
# prevent the DockerManger web application from starting. The UI should be
# capable of reporting that Docker is unavailable.
if [ -S /var/run/docker.sock ]; then
    echo "[DockerManger] Docker socket detected."
else
    echo "[DockerManger] WARNING: /var/run/docker.sock not detected."
fi

# Verify stack storage.
if [ -r "${STACKS_DIR}" ]; then
    echo "[DockerManger] Stack directory readable."
else
    echo "[DockerManger] WARNING: Stack directory is not readable."
fi

if [ -w "${STACKS_DIR}" ]; then
    echo "[DockerManger] Stack directory writable."
else
    echo "[DockerManger] WARNING: Stack directory is not writable."
fi

echo "[DockerManger] Initialization complete."

exec "$@"
