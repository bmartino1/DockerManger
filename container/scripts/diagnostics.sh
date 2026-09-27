#!/bin/bash

# ============================================================================
# DockerManger Diagnostics
# ============================================================================
#
# Human-readable diagnostic report for troubleshooting the DockerManger
# container and its connection to the host Docker Engine.
#
# This script is intentionally diagnostic only. It must not modify Docker,
# Compose stacks, networking, SSH configuration, or application data.
#
# Run inside the container:
#
#   dockermanger-diagnostics
#
# Or from the Docker host:
#
#   docker exec dockermanger dockermanger-diagnostics
#
# ============================================================================

set -u

STACKS_DIR="${STACKS_DIR:-/opt/stacks}"

section() {
    echo
    echo "============================================================================"
    echo " $1"
    echo "============================================================================"
}

status_ok() {
    printf "%-30s %s\n" "$1" "OK"
}

status_warn() {
    printf "%-30s %s\n" "$1" "WARNING"
}

status_info() {
    printf "%-30s %s\n" "$1" "$2"
}


# ----------------------------------------------------------------------------
# DockerManger / System
# ----------------------------------------------------------------------------

section "DockerManger System"

status_info "Hostname" "$(hostname 2>/dev/null || echo unknown)"
status_info "Architecture" "$(uname -m 2>/dev/null || echo unknown)"
status_info "Kernel" "$(uname -r 2>/dev/null || echo unknown)"
status_info "Timezone" "${TZ:-not configured}"
status_info "Stack Directory" "${STACKS_DIR}"

echo
uname -a 2>/dev/null || true


# ----------------------------------------------------------------------------
# Installed Runtime Components
# ----------------------------------------------------------------------------

section "Runtime Components"

if command -v php >/dev/null 2>&1; then
    status_info "PHP" "$(php --version | head -1)"
else
    status_warn "PHP"
fi

if command -v nginx >/dev/null 2>&1; then
    status_info "Nginx" "$(nginx -v 2>&1)"
else
    status_warn "Nginx"
fi

if command -v node >/dev/null 2>&1; then
    status_info "Node.js" "$(node --version)"
else
    status_warn "Node.js"
fi

if command -v npm >/dev/null 2>&1; then
    status_info "npm" "$(npm --version)"
else
    status_warn "npm"
fi

if command -v sqlite3 >/dev/null 2>&1; then
    status_info "SQLite" "$(sqlite3 --version | awk '{print $1}')"
else
    status_warn "SQLite"
fi

if command -v ssh >/dev/null 2>&1; then
    status_info "OpenSSH Client" "$(ssh -V 2>&1)"
else
    status_warn "OpenSSH Client"
fi


# ----------------------------------------------------------------------------
# Docker
# ----------------------------------------------------------------------------

section "Docker Engine"

if command -v docker >/dev/null 2>&1; then
    status_info "Docker CLI" "$(docker --version 2>/dev/null)"
else
    status_warn "Docker CLI"
fi

if docker compose version >/dev/null 2>&1; then
    status_info "Docker Compose" "$(docker compose version 2>/dev/null)"
else
    status_warn "Docker Compose"
fi

if [ -S /var/run/docker.sock ]; then
    status_ok "Docker Socket"
    ls -l /var/run/docker.sock
else
    status_warn "Docker Socket"
    echo "Expected socket:"
    echo "  /var/run/docker.sock"
fi

echo

if docker info >/dev/null 2>&1; then
    status_ok "Docker Engine Connection"

    server_version="$(docker version \
        --format '{{.Server.Version}}' 2>/dev/null || true)"

    if [ -n "${server_version}" ]; then
        status_info "Docker Server" "${server_version}"
    fi
else
    status_warn "Docker Engine Connection"
    echo "DockerManger could not communicate with the Docker Engine."
fi


# ----------------------------------------------------------------------------
# Docker Containers
# ----------------------------------------------------------------------------

section "Docker Containers"

if docker info >/dev/null 2>&1; then
    docker ps -a \
        --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' \
        2>/dev/null || true
else
    echo "Container information unavailable because Docker is not reachable."
fi


# ----------------------------------------------------------------------------
# Stack Storage
# ----------------------------------------------------------------------------

section "Compose Stack Storage"

status_info "Configured STACKS_DIR" "${STACKS_DIR}"

if [ -d "${STACKS_DIR}" ]; then
    status_ok "Stack Directory Exists"

    if [ -r "${STACKS_DIR}" ]; then
        status_ok "Stack Directory Readable"
    else
        status_warn "Stack Directory Readable"
    fi

    if [ -w "${STACKS_DIR}" ]; then
        status_ok "Stack Directory Writable"
    else
        status_warn "Stack Directory Writable"
    fi

    echo
    echo "Compose files detected:"

    compose_files="$(
        find "${STACKS_DIR}" \
            -maxdepth 2 \
            -type f \
            \( \
                -name 'compose.yaml' \
                -o -name 'compose.yml' \
                -o -name 'docker-compose.yaml' \
                -o -name 'docker-compose.yml' \
            \) \
            2>/dev/null || true
    )"

    if [ -n "${compose_files}" ]; then
        printf '%s\n' "${compose_files}"
        echo
        status_info \
            "Compose File Count" \
            "$(printf '%s\n' "${compose_files}" | wc -l)"
    else
        echo "No Compose files detected."
        status_info "Compose File Count" "0"
    fi
else
    status_warn "Stack Directory Exists"
fi


# ----------------------------------------------------------------------------
# Persistent Application Data
# ----------------------------------------------------------------------------

section "Application Data"

if [ -d /data ]; then
    status_ok "/data Exists"

    if [ -r /data ]; then
        status_ok "/data Readable"
    else
        status_warn "/data Readable"
    fi

    if [ -w /data ]; then
        status_ok "/data Writable"
    else
        status_warn "/data Writable"
    fi
else
    status_warn "/data Exists"
fi


# ----------------------------------------------------------------------------
# Network
# ----------------------------------------------------------------------------

section "Network Interfaces"

ip -brief addr 2>/dev/null || ip addr 2>/dev/null || true


section "Network Routes"

ip route 2>/dev/null || true


section "DNS"

if [ -f /etc/resolv.conf ]; then
    cat /etc/resolv.conf
else
    echo "/etc/resolv.conf not found."
fi


section "Listening Ports"

ss -lntup 2>/dev/null || true


# ----------------------------------------------------------------------------
# Docker Host Resolution
# ----------------------------------------------------------------------------

section "Docker Host Connectivity"

if getent hosts host.docker.internal >/dev/null 2>&1; then
    status_ok "host.docker.internal"

    getent hosts host.docker.internal
else
    status_warn "host.docker.internal"
fi


# ----------------------------------------------------------------------------
# Optional Host SSH Console
# ----------------------------------------------------------------------------

section "Host SSH Console"

HOST_SHELL_ENABLED="${DOCKERMANGER_HOST_SHELL_ENABLED:-false}"
SSH_HOST="${DOCKERMANGER_HOST_SSH_HOST:-host.docker.internal}"
SSH_PORT="${DOCKERMANGER_HOST_SSH_PORT:-22}"
SSH_USER="${DOCKERMANGER_HOST_SSH_USER:-root}"
SSH_KEY="${DOCKERMANGER_HOST_SSH_KEY:-}"
SSH_PASSWORD="${DOCKERMANGER_HOST_SSH_PASSWORD:-}"

status_info "Host Console Enabled" "${HOST_SHELL_ENABLED}"

if [ "${HOST_SHELL_ENABLED}" = "true" ]; then

    status_info "SSH Host" "${SSH_HOST}"
    status_info "SSH Port" "${SSH_PORT}"
    status_info "SSH User" "${SSH_USER}"

    if [ -n "${SSH_KEY}" ]; then
        status_info "Explicit SSH Key" "${SSH_KEY}"

        if [ -f "${SSH_KEY}" ]; then
            status_ok "SSH Key File"
        else
            status_warn "SSH Key File"
        fi
    else
        status_info "Explicit SSH Key" "not configured"

        if [ -f /root/.ssh/id_ed25519 ] || [ -f /root/.ssh/id_rsa ]; then
            status_ok "Default SSH Key"
        else
            status_info "Default SSH Key" "not detected"
        fi
    fi

    # Never print the actual password.
    if [ -n "${SSH_PASSWORD}" ]; then
        status_info "SSH Password" "configured"
    else
        status_info "SSH Password" "not configured"
    fi

    echo

    if command -v nc >/dev/null 2>&1; then
        if nc -z -w 3 "${SSH_HOST}" "${SSH_PORT}" >/dev/null 2>&1; then
            status_ok "SSH TCP Connection"
        else
            status_warn "SSH TCP Connection"
            echo "Could not reach ${SSH_HOST}:${SSH_PORT}"
        fi
    else
        status_warn "netcat unavailable"
    fi

else
    echo "Host SSH console is disabled."
fi


# ----------------------------------------------------------------------------
# Diagnostic Complete
# ----------------------------------------------------------------------------

section "Diagnostic Complete"

echo "DockerManger diagnostics finished."
echo
