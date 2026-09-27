#!/bin/bash
set -u
echo "=== DockerManger diagnostics ==="
uname -a
php --version | head -1 || true
node --version || true
npm --version || true
ssh -V 2>&1 || true
docker --version || true
docker compose version || true
ls -l /var/run/docker.sock || true
docker ps -a || true
ip addr || true
ip route || true
ss -lntup || true
if [ "${DOCKERMANGER_HOST_SHELL_ENABLED:-false}" = true ]; then
 h="${DOCKERMANGER_HOST_SSH_HOST:-host.docker.internal}"; p="${DOCKERMANGER_HOST_SSH_PORT:-2222}"
 echo "Host SSH target: ${DOCKERMANGER_HOST_SSH_USER:-root}@${h}:${p}"
 nc -z -w 3 "$h" "$p" && echo "SSH TCP reachable" || echo "SSH TCP not reachable (optional host service)"
fi
