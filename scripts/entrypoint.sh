#!/bin/bash
set -euo pipefail

mkdir -p "${STACKS_DIR:-/opt/stacks}" /data /run/php /run/sshd

if [ -n "${TZ:-}" ] && [ -e "/usr/share/zoneinfo/${TZ}" ]; then
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
fi

echo "DockerManger starting"
echo "Stacks directory: ${STACKS_DIR:-/opt/stacks}"

if [ -S /var/run/docker.sock ]; then
    echo "Docker socket detected."
else
    echo "WARNING: /var/run/docker.sock is not available."
fi

exec "$@"
