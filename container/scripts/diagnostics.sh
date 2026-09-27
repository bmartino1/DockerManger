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

if command -v ssh >/dev/null 2>&1; then
    status_info "OpenSSH Client" "$(ssh -V 2>&1)"
else
    status_warn "OpenSSH Client"
fi


echo
status_info "Console Enabled" "${DOCKERMANGER_ENABLE_CONSOLE:-not configured}"
status_info "Terminal Type" "${DOCKERMANGER_TERMINAL_TYPE:-not configured}"
status_info "Default Console Target" "${DOCKERMANGER_CONSOLE_DEFAULT_TARGET:-not configured}"

TERMINAL_DIR="/opt/dockermanger-terminal"
if [ -d "${TERMINAL_DIR}" ]; then
    status_ok "Terminal Runtime Directory"

    if [ -f "${TERMINAL_DIR}/package.json" ]; then
        echo
        echo "Installed terminal npm packages:"
        (
            cd "${TERMINAL_DIR}" &&
            npm ls --depth=0 2>&1
        ) || status_warn "npm Dependency Tree"

        echo
        if (
            cd "${TERMINAL_DIR}" &&
            npm audit --omit=dev >/tmp/dockermanger-npm-audit.$$ 2>&1
        ); then
            status_ok "npm Production Audit"
        else
            status_warn "npm Production Audit"
        fi
        cat /tmp/dockermanger-npm-audit.$$ 2>/dev/null || true
        rm -f /tmp/dockermanger-npm-audit.$$ 2>/dev/null || true

        echo
        echo "npm outdated (informational; non-zero means updates are available):"
        (
            cd "${TERMINAL_DIR}" &&
            npm outdated 2>&1
        ) || true
    else
        status_warn "Terminal package.json"
    fi
else
    status_warn "Terminal Runtime Directory"
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


# Validate the services and configuration that make up the application.
section "DockerManger Services"

if command -v sv >/dev/null 2>&1; then
    for service in nginx php-fpm dockermanger-terminal; do
        if sv status "${service}" >/dev/null 2>&1; then
            status_ok "Service: ${service}"
            sv status "${service}" 2>/dev/null || true
        else
            status_warn "Service: ${service}"
            sv status "${service}" 2>&1 || true
        fi
    done
else
    status_warn "runit sv command"
fi

echo
if nginx -t >/dev/null 2>&1; then
    status_ok "Nginx Configuration"
else
    status_warn "Nginx Configuration"
    nginx -t 2>&1 || true
fi

if php-fpm8.3 -t >/dev/null 2>&1; then
    status_ok "PHP-FPM Configuration"
else
    status_warn "PHP-FPM Configuration"
    php-fpm8.3 -t 2>&1 || true
fi

if pgrep -f '/opt/dockermanger-terminal/server.js' >/dev/null 2>&1; then
    status_ok "Terminal Node Process"
    status_info "Terminal PID" "$(pgrep -f '/opt/dockermanger-terminal/server.js' | head -1)"
else
    status_warn "Terminal Node Process"
fi

if ss -lnt 2>/dev/null | grep -Eq '127\.0\.0\.1:3000|0\.0\.0\.0:3000|\[::\]:3000'; then
    status_ok "Terminal Port 3000"
else
    status_warn "Terminal Port 3000"
fi

if curl -fsS --max-time 5 http://127.0.0.1/health >/dev/null 2>&1; then
    status_ok "HTTP /health"
else
    status_warn "HTTP /health"
fi

if [ -S /var/run/docker.sock ]; then
    status_ok "Docker Socket"
    status_info "Socket Owner" "$(stat -c '%U' /var/run/docker.sock 2>/dev/null || echo unknown)"
    status_info "Socket Group" "$(stat -c '%G' /var/run/docker.sock 2>/dev/null || echo unknown)"
    status_info "Socket Numeric GID" "$(stat -c '%g' /var/run/docker.sock 2>/dev/null || echo unknown)"
    status_info "Socket Mode" "$(stat -c '%a' /var/run/docker.sock 2>/dev/null || echo unknown)"
else
    status_warn "Docker Socket"
    echo "Expected socket:"
    echo "  /var/run/docker.sock"
fi

echo

if docker info >/dev/null 2>&1; then
    status_ok "Docker as root"
else
    status_warn "Docker as root"
fi

# The web application executes Docker commands from PHP-FPM as www-data.
# Testing only as root can hide the most common docker.sock permission error.
if id www-data >/dev/null 2>&1; then
    status_info "www-data Account" "$(id www-data 2>/dev/null)"

    if runuser -u www-data -- docker info >/dev/null 2>&1; then
        status_ok "Docker as www-data"
    else
        status_warn "Docker as www-data"
        runuser -u www-data -- docker info 2>&1 | tail -n 3 || true
    fi
else
    status_warn "www-data Account"
fi

# Inspect the credentials attached to live FPM workers. PHP-FPM can drop
# supplementary groups when creating workers, so `id www-data` alone does not
# prove that the web process can access docker.sock.
fpm_workers="$(pgrep -P "$(pgrep -o php-fpm8.3 2>/dev/null || true)" php-fpm8.3 2>/dev/null || true)"

if [ -n "${fpm_workers}" ]; then
    first_worker="$(printf '%s\n' "${fpm_workers}" | head -1)"
    worker_uid="$(awk '/^Uid:/ {print $2}' "/proc/${first_worker}/status" 2>/dev/null || true)"
    worker_gid="$(awk '/^Gid:/ {print $2}' "/proc/${first_worker}/status" 2>/dev/null || true)"
    worker_groups="$(awk '/^Groups:/ {$1=""; sub(/^ /, ""); print}' "/proc/${first_worker}/status" 2>/dev/null || true)"

    status_info "PHP-FPM Worker PID" "${first_worker}"
    status_info "PHP-FPM Worker UID" "${worker_uid:-unknown}"
    status_info "PHP-FPM Worker GID" "${worker_gid:-unknown}"
    status_info "PHP-FPM Worker Groups" "${worker_groups:-unknown}"

    if [ -S /var/run/docker.sock ]; then
        socket_gid="$(stat -c '%g' /var/run/docker.sock 2>/dev/null || true)"
        if [ -n "${socket_gid}" ] && { [ "${worker_gid}" = "${socket_gid}" ] || printf ' %s ' "${worker_groups}" | grep -q " ${socket_gid} "; }; then
            status_ok "FPM Socket Group Access"
        else
            status_warn "FPM Socket Group Access"
        fi
    fi
else
    status_warn "PHP-FPM Workers"
fi

echo
echo "PHP-FPM DockerManger environment:"
fpm_master="$(pgrep -o php-fpm8.3 2>/dev/null || pgrep -o php-fpm 2>/dev/null || true)"
if [ -n "${fpm_master}" ] && [ -r "/proc/${fpm_master}/environ" ]; then
    fpm_env="$(tr '\0' '\n' < "/proc/${fpm_master}/environ" | grep -E '^(DOCKERMANGER_|STACKS_DIR=|TZ=)' || true)"
    if [ -n "${fpm_env}" ]; then
        printf '%s\n' "${fpm_env}" | sed -E 's/^(DOCKERMANGER_HOST_SSH_KEY)=.*/\1=[configured value hidden]/'
    else
        status_warn "PHP-FPM App Environment"
    fi
else
    status_warn "PHP-FPM Master Environment"
fi

# Exercise PHP's command execution as the application account. This mirrors
# DockerManger's Command/Docker layer more closely than a root shell test.
if runuser -u www-data -- php -r 'exec("docker info 2>&1", $o, $c); exit($c);' >/dev/null 2>&1; then
    status_ok "PHP Docker Command"
else
    status_warn "PHP Docker Command"
fi

server_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
if [ -n "${server_version}" ]; then
    status_info "Docker Server" "${server_version}"
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
        status_ok "Stack Directory Writable (root)"
    else
        status_warn "Stack Directory Writable (root)"
    fi

    status_info "Stack Owner/Mode" "$(stat -c '%U:%G %a' "${STACKS_DIR}" 2>/dev/null || echo unknown)"

    if command -v getfacl >/dev/null 2>&1; then
        echo
        echo "Stack directory ACL:"
        getfacl -cp "${STACKS_DIR}" 2>/dev/null || status_warn "Stack Directory ACL"
    else
        status_warn "getfacl unavailable"
    fi

    # Root access can hide the exact failure the PHP editor experiences. Test
    # creation as www-data and clean the probe immediately.
    write_probe="${STACKS_DIR}/.dockermanger-write-test-$$"
    if runuser -u www-data -- touch "${write_probe}" 2>/dev/null; then
        status_ok "Stack Create as www-data"
        rm -f "${write_probe}"
    else
        status_warn "Stack Create as www-data"
    fi

    first_stack="$(find "${STACKS_DIR}" -mindepth 1 -maxdepth 1 -type d -print -quit 2>/dev/null || true)"
    if [ -n "${first_stack}" ]; then
        stack_probe="${first_stack}/.dockermanger-write-test-$$"
        if runuser -u www-data -- touch "${stack_probe}" 2>/dev/null; then
            status_ok "Existing Stack Write as www-data"
            rm -f "${stack_probe}"
        else
            status_warn "Existing Stack Write as www-data"
            status_info "Probe Stack" "${first_stack}"
        fi
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


echo
for data_dir in /data/certs /data/ssh; do
    if [ -d "${data_dir}" ]; then
        status_ok "${data_dir}"
        status_info "${data_dir} Owner/Mode" "$(stat -c '%U:%G %a' "${data_dir}" 2>/dev/null || echo unknown)"
    else
        status_warn "${data_dir}"
    fi
done

if [ -L /root/.ssh ]; then
    status_ok "/root/.ssh Symlink"
    status_info "/root/.ssh Target" "$(readlink -f /root/.ssh 2>/dev/null || echo unknown)"
else
    status_warn "/root/.ssh Symlink"
fi

if [ -f /data/certs/dockermanger.crt ] && [ -f /data/certs/dockermanger.key ]; then
    status_ok "TLS Certificate Pair"
    status_info "TLS Certificate Mode" "$(stat -c '%a' /data/certs/dockermanger.crt 2>/dev/null || echo unknown)"
    status_info "TLS Private Key Mode" "$(stat -c '%a' /data/certs/dockermanger.key 2>/dev/null || echo unknown)"
elif [ -e /data/certs/dockermanger.crt ] || [ -e /data/certs/dockermanger.key ]; then
    status_warn "TLS Certificate Pair"
    echo "Only one member of the TLS certificate/key pair exists."
else
    status_info "TLS Certificate Pair" "not present"
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

status_info "Host Console Enabled" "${HOST_SHELL_ENABLED}"

if printf '%s' "${HOST_SHELL_ENABLED}" | grep -Eqi '^(1|true|yes|on)$'; then

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

    status_info "SSH Authentication" "interactive password or SSH key"

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
