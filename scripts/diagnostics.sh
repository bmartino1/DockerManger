#!/bin/bash
set -u

echo "============================================================"
echo " DockerManger diagnostics"
echo "============================================================"
echo
echo "Architecture: $(uname -m)"
echo "Hostname:     $(hostname)"
echo "Stacks:       ${STACKS_DIR:-/opt/stacks}"
echo

echo "---- Versions ------------------------------------------------"
php --version | head -n 1 || true
nginx -v 2>&1 || true
node --version 2>/dev/null || true
npm --version 2>/dev/null || true
docker --version 2>/dev/null || true
docker compose version 2>/dev/null || true
sqlite3 --version 2>/dev/null | head -n 1 || true
echo

echo "---- Docker socket -------------------------------------------"
if [ -S /var/run/docker.sock ]; then
    ls -l /var/run/docker.sock
else
    echo "NOT PRESENT"
fi
echo

echo "---- Docker daemon -------------------------------------------"
docker version 2>&1 || true
echo

echo "---- Containers ----------------------------------------------"
docker ps -a 2>&1 || true
echo

echo "---- Network -------------------------------------------------"
ip addr 2>&1 || true
echo
ip route 2>&1 || true
echo
ss -lntup 2>&1 || true
echo

echo "---- DNS -----------------------------------------------------"
cat /etc/resolv.conf 2>/dev/null || true
echo

echo "---- Stack directories ---------------------------------------"
find "${STACKS_DIR:-/opt/stacks}" -maxdepth 2 \
    \( -name compose.yaml -o -name compose.yml -o \
       -name docker-compose.yml -o -name docker-compose.yaml \) \
    -print 2>/dev/null || true

echo
echo "============================================================"
echo " Diagnostics complete"
echo "============================================================"
