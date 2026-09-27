#!/bin/bash

# ============================================================================
# DockerManger Container Entrypoint
# ============================================================================
#
# Lightweight runtime initialization for DockerManger.
#
# Responsibilities:
#   - Prepare persistent runtime directories
#   - Prepare persistent outbound-SSH client storage
#   - Prepare/validate mandatory HTTPS certificates
#   - Configure the external HTTPS redirect port
#   - Configure PHP-FPM Docker socket access
#   - Preserve the container environment for PHP-FPM
#   - Prepare stack ACLs without changing host ownership
#   - Validate Nginx and PHP-FPM before runit starts
#
# Application logic and Docker lifecycle operations do NOT belong here.
# ============================================================================

set -euo pipefail

# ============================================================================
# Runtime Configuration
# ============================================================================

STACKS_DIR="${STACKS_DIR:-/opt/stacks}"
HTTPS_PORT="${DOCKERMANGER_HTTPS_PORT:-5443}"

DATA_DIR="/data"
CERT_DIR="${DATA_DIR}/certs"
SSH_DIR="${DATA_DIR}/ssh"

TLS_CERT="${CERT_DIR}/dockermanger.crt"
TLS_KEY="${CERT_DIR}/dockermanger.key"

NGINX_CONFIG="/etc/nginx/conf.d/dockermanger.conf"
PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
PHP_FPM_USER="www-data"

# ============================================================================
# Startup Information
# ============================================================================

echo "============================================================"
echo " DockerManger"
echo "============================================================"
echo "Architecture : $(uname -m)"
echo "Stacks       : ${STACKS_DIR}"
echo "Data         : ${DATA_DIR}"
echo "Timezone     : ${TZ:-not configured}"
echo "HTTPS        : required"
echo "HTTPS Port   : ${HTTPS_PORT}"
echo "============================================================"

# ============================================================================
# Runtime Directories
# ============================================================================

mkdir -p \
    "${DATA_DIR}" \
    "${CERT_DIR}" \
    "${SSH_DIR}" \
    /run/php \
    "${STACKS_DIR}"

# ============================================================================
# Persistent SSH Client Storage
# ============================================================================
#
# DockerManger is an SSH CLIENT only. /data/ssh persists root's outbound SSH
# configuration, keys, and known_hosts state.
# ============================================================================

if [ -e /root/.ssh ] && [ ! -L /root/.ssh ]; then
    echo "[DockerManger] Existing /root/.ssh detected."

    # Remove only an empty base-image directory. Never delete SSH material.
    if [ -d /root/.ssh ] && [ -z "$(ls -A /root/.ssh 2>/dev/null)" ]; then
        rmdir /root/.ssh
    fi
fi

if [ ! -e /root/.ssh ]; then
    ln -s "${SSH_DIR}" /root/.ssh
    echo "[DockerManger] SSH client storage linked to ${SSH_DIR}."
elif [ -L /root/.ssh ]; then
    echo "[DockerManger] SSH client storage ready."
else
    echo "[DockerManger] WARNING: /root/.ssh exists and is not a symlink."
    echo "[DockerManger] WARNING: Persistent SSH storage at ${SSH_DIR} is not linked."
fi

# Old repository revisions may have shipped config/known_hosts as placeholder
# directories containing only .gitkeep. OpenSSH requires these paths to be
# regular files. Repair only those known-empty placeholders.
for ssh_name in config known_hosts; do
    ssh_path="${SSH_DIR}/${ssh_name}"

    if [ -d "${ssh_path}" ]; then
        non_placeholder="$(
            find "${ssh_path}" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' -print -quit 2>/dev/null || true
        )"

        if [ -z "${non_placeholder}" ]; then
            rm -f "${ssh_path}/.gitkeep"
            rmdir "${ssh_path}"
            echo "[DockerManger] Repaired legacy SSH ${ssh_name} placeholder."
        else
            echo "[DockerManger] WARNING: ${ssh_path} is a non-empty directory."
            echo "[DockerManger] WARNING: Leaving it untouched."
        fi
    fi
done

chmod 700 "${SSH_DIR}"

# known_hosts must be a writable regular file for interactive Host Console SSH.
if [ ! -e "${SSH_DIR}/known_hosts" ]; then
    : > "${SSH_DIR}/known_hosts"
fi

if [ -f "${SSH_DIR}/known_hosts" ]; then
    chmod 600 "${SSH_DIR}/known_hosts"
else
    echo "[DockerManger] WARNING: ${SSH_DIR}/known_hosts is not a regular file."
fi

# Tighten standard OpenSSH files when present. Do not alter arbitrary files.
if [ -f "${SSH_DIR}/config" ]; then
    chmod 600 "${SSH_DIR}/config"
fi

for ssh_key in "${SSH_DIR}"/id_*; do
    [ -f "${ssh_key}" ] || continue
    case "${ssh_key}" in
        *.pub) chmod 644 "${ssh_key}" ;;
        *)     chmod 600 "${ssh_key}" ;;
    esac
done

echo "[DockerManger] SSH client storage permissions prepared."

# ============================================================================
# TLS Certificate Storage
# ============================================================================

if [ -f "${TLS_CERT}" ] && [ -f "${TLS_KEY}" ]; then
    echo "[DockerManger] TLS certificate pair detected."

elif [ ! -f "${TLS_CERT}" ] && [ ! -f "${TLS_KEY}" ]; then
    echo "[DockerManger] No TLS certificate detected."
    echo "[DockerManger] Generating bootstrap self-signed certificate."

    openssl req \
        -x509 \
        -nodes \
        -newkey rsa:2048 \
        -sha256 \
        -days 3650 \
        -keyout "${TLS_KEY}" \
        -out "${TLS_CERT}" \
        -subj "/CN=dockermanger" \
        -addext "subjectAltName=DNS:dockermanger,DNS:localhost,IP:127.0.0.1"

    chmod 600 "${TLS_KEY}"
    chmod 644 "${TLS_CERT}"

    echo "[DockerManger] Bootstrap TLS certificate generated."

else
    echo "[DockerManger] ERROR: Incomplete TLS certificate pair."
    echo "[DockerManger] Expected both:"
    echo "[DockerManger]   ${TLS_CERT}"
    echo "[DockerManger]   ${TLS_KEY}"
    echo "[DockerManger] Refusing startup with incomplete TLS configuration."
    exit 1
fi

# ============================================================================
# Nginx Runtime Configuration
# ============================================================================

if ! [[ "${HTTPS_PORT}" =~ ^[0-9]+$ ]] || \
   [ "${HTTPS_PORT}" -lt 1 ] || \
   [ "${HTTPS_PORT}" -gt 65535 ]; then
    echo "[DockerManger] ERROR: Invalid DOCKERMANGER_HTTPS_PORT: ${HTTPS_PORT}"
    exit 1
fi

if grep -q '__DOCKERMANGER_HTTPS_PORT__' "${NGINX_CONFIG}"; then
    sed -i "s/__DOCKERMANGER_HTTPS_PORT__/${HTTPS_PORT}/g" "${NGINX_CONFIG}"
    echo "[DockerManger] Nginx HTTPS redirect port set to ${HTTPS_PORT}."
fi

nginx -t

# ============================================================================
# Docker Engine / PHP-FPM Socket Access
# ============================================================================

if [ -S /var/run/docker.sock ]; then
    echo "[DockerManger] Docker socket detected."

    DOCKER_SOCKET_GID="$(stat -c '%g' /var/run/docker.sock)"
    DOCKER_SOCKET_GROUP="$(
        getent group "${DOCKER_SOCKET_GID}" | cut -d: -f1 || true
    )"

    if [ -z "${DOCKER_SOCKET_GROUP}" ]; then
        DOCKER_SOCKET_GROUP="dockermanger-docker"

        if getent group "${DOCKER_SOCKET_GROUP}" >/dev/null 2>&1; then
            existing_gid="$(getent group "${DOCKER_SOCKET_GROUP}" | cut -d: -f3)"
            if [ "${existing_gid}" != "${DOCKER_SOCKET_GID}" ]; then
                DOCKER_SOCKET_GROUP="dockermanger-docker-${DOCKER_SOCKET_GID}"
            fi
        fi

        if ! getent group "${DOCKER_SOCKET_GROUP}" >/dev/null 2>&1; then
            groupadd --gid "${DOCKER_SOCKET_GID}" "${DOCKER_SOCKET_GROUP}"
            echo "[DockerManger] Created Docker socket group ${DOCKER_SOCKET_GROUP} (${DOCKER_SOCKET_GID})."
        fi
    else
        echo "[DockerManger] Docker socket group ${DOCKER_SOCKET_GROUP} (${DOCKER_SOCKET_GID}) detected."
    fi

    usermod -aG "${DOCKER_SOCKET_GROUP}" "${PHP_FPM_USER}"

    if [ -f "${PHP_FPM_POOL}" ]; then
        sed -i -E \
            "s|^[[:space:]]*group[[:space:]]*=.*$|group = ${DOCKER_SOCKET_GROUP}|" \
            "${PHP_FPM_POOL}"

        echo "[DockerManger] PHP-FPM group set to ${DOCKER_SOCKET_GROUP}."
    else
        echo "[DockerManger] ERROR: PHP-FPM pool configuration not found: ${PHP_FPM_POOL}"
        exit 1
    fi
else
    echo "[DockerManger] WARNING: /var/run/docker.sock not detected."
    echo "[DockerManger] WARNING: Docker management will be unavailable in the web UI."
fi

# ============================================================================
# PHP-FPM Application Environment
# ============================================================================
#
# IMPORTANT:
# Do not dynamically append env[NAME] directives here. PHP-FPM's pool parser
# rejects malformed/empty generated values and can turn a harmless environment
# setting into a container restart loop.
#
# DockerManger intentionally receives its configuration through the container
# environment. Keep that inherited environment available to PHP-FPM workers by
# setting clear_env = no in the pool. This lets PHP getenv() see TZ,
# DOCKERMANGER_HOST_SHELL_ENABLED, SSH target settings, console settings, etc.,
# without serializing those values back into www.conf.
# ============================================================================

if [ ! -f "${PHP_FPM_POOL}" ]; then
    echo "[DockerManger] ERROR: PHP-FPM pool configuration not found: ${PHP_FPM_POOL}"
    exit 1
fi

if grep -Eq '^[[:space:]]*;?[[:space:]]*clear_env[[:space:]]*=' "${PHP_FPM_POOL}"; then
    sed -i -E \
        's|^[[:space:]]*;?[[:space:]]*clear_env[[:space:]]*=.*$|clear_env = no|' \
        "${PHP_FPM_POOL}"
else
    printf '\nclear_env = no\n' >> "${PHP_FPM_POOL}"
fi

echo "[DockerManger] PHP-FPM container environment enabled."
echo "[DockerManger] PHP-FPM Host Console enabled: ${DOCKERMANGER_HOST_SHELL_ENABLED:-false}."

# Validate the final generated pool configuration before runit starts.
php-fpm8.3 -t

# ============================================================================
# Stack Storage
# ============================================================================

MANAGE_STACK_PERMISSIONS="${DOCKERMANGER_MANAGE_STACK_PERMISSIONS:-true}"

case "${MANAGE_STACK_PERMISSIONS,,}" in
    1|true|yes|on)
        if setfacl -Rm "u:${PHP_FPM_USER}:rwX" "${STACKS_DIR}" 2>/dev/null && \
           setfacl -Rm "d:u:${PHP_FPM_USER}:rwX" "${STACKS_DIR}" 2>/dev/null; then
            echo "[DockerManger] Stack ACL prepared for ${PHP_FPM_USER} without changing host ownership."
        else
            echo "[DockerManger] WARNING: Unable to apply stack ACLs to ${STACKS_DIR}."
            echo "[DockerManger] WARNING: Compose viewing/lifecycle may work, but create/edit operations may be read-only."
        fi
        ;;
    *)
        echo "[DockerManger] Automatic stack permission management disabled."
        ;;
esac

if runuser -u "${PHP_FPM_USER}" -- test -r "${STACKS_DIR}"; then
    echo "[DockerManger] Stack directory readable by ${PHP_FPM_USER}."
else
    echo "[DockerManger] WARNING: Stack directory is not readable by ${PHP_FPM_USER}."
fi

if runuser -u "${PHP_FPM_USER}" -- test -w "${STACKS_DIR}"; then
    echo "[DockerManger] Stack directory writable by ${PHP_FPM_USER}."
else
    echo "[DockerManger] WARNING: Stack directory is not writable by ${PHP_FPM_USER}."
    echo "[DockerManger] WARNING: Host path mounted at ${STACKS_DIR} must permit UID $(id -u "${PHP_FPM_USER}") to write."
fi

# ============================================================================
# Initialization Complete
# ============================================================================

echo "============================================================"
echo "[DockerManger] Initialization complete."
echo "============================================================"

exec "$@"
