#!/bin/bash

# ============================================================================
# DockerManger Container Entrypoint
# ============================================================================
#
# Performs lightweight container initialization before handing control to
# Phusion Baseimage / runit.
#
# Responsibilities:
#
#   - Create DockerManger runtime directories
#   - Prepare persistent SSH client storage
#   - Prepare TLS certificate storage
#   - Generate an initial self-signed TLS certificate when required
#   - Report basic Docker and stack-storage availability
#
# Application logic and Docker management logic do NOT belong here.
#
# Interactive configuration should eventually live in a separate setup
# utility rather than turning this entrypoint into an installation wizard.
#
# Persistent DockerManger runtime data lives beneath:
#
#   /data
#
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
#
# /data is expected to be persistent storage supplied by the deployment.
#
# Keep generated/runtime DockerManger state beneath this directory rather
# than scattering persistent files throughout the container filesystem.
#
# ============================================================================

mkdir -p \
    "${DATA_DIR}" \
    "${CERT_DIR}" \
    "${SSH_DIR}" \
    /run/php \
    "${STACKS_DIR}"


# ============================================================================
# SSH Client Storage
# ============================================================================
#
# DockerManger is an SSH CLIENT only.
#
# Persistent SSH configuration, keys and known_hosts data are stored beneath:
#
#   /data/ssh
#
# OpenSSH normally expects root's SSH configuration beneath:
#
#   /root/.ssh
#
# Create a symlink so standard OpenSSH behavior continues to work without
# requiring a separate persistent /root/.ssh volume.
#
# No SSH keys are automatically generated here. Host-console authentication
# remains an administrator/deployment choice.
#
# ============================================================================

if [ -e /root/.ssh ] && [ ! -L /root/.ssh ]; then
    echo "[DockerManger] Existing /root/.ssh detected."

    # A normal directory may exist in the base image. Only remove it when it
    # is empty so we never silently destroy SSH material.
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

# OpenSSH rejects private keys/directories with overly permissive permissions.
chmod 700 "${SSH_DIR}"


# ============================================================================
# TLS Certificate Storage
# ============================================================================
#
# HTTPS is mandatory for DockerManger.
#
# Nginx expects:
#
#   /data/certs/dockermanger.crt
#   /data/certs/dockermanger.key
#
# On a fresh installation DockerManger generates a self-signed bootstrap
# certificate BEFORE Nginx is validated or started.
#
# Existing certificates are never overwritten automatically.
#
# A complete existing certificate pair may later be supplied by:
#
#   - the deployment administrator
#   - a locally trusted certificate authority
#   - an external ACME / Let's Encrypt client
#
# If only one half of the certificate pair exists, startup intentionally fails
# rather than silently replacing certificate material.
#
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
#
# Nginx runs on container port 443, but Docker Compose may publish that port
# on a different host port (5443 by default). The repository Nginx file keeps
# a single placeholder for that external port. Replace it at container start.
#
# This modifies only the container copy under /etc/nginx. The repository file
# mounted/build context is never changed.
# ============================================================================

if ! [[ "${HTTPS_PORT}" =~ ^[0-9]+$ ]] || [ "${HTTPS_PORT}" -lt 1 ] || [ "${HTTPS_PORT}" -gt 65535 ]; then
    echo "[DockerManger] ERROR: Invalid DOCKERMANGER_HTTPS_PORT: ${HTTPS_PORT}"
    exit 1
fi

if grep -q '__DOCKERMANGER_HTTPS_PORT__' "${NGINX_CONFIG}"; then
    sed -i "s/__DOCKERMANGER_HTTPS_PORT__/${HTTPS_PORT}/g" "${NGINX_CONFIG}"
    echo "[DockerManger] Nginx HTTPS redirect port set to ${HTTPS_PORT}."
fi

# Validate Nginx before runit starts it. This catches missing certificates,
# malformed configuration, and other startup problems with a useful error.
nginx -t


# ============================================================================
# Docker Engine
# ============================================================================
#
# Docker socket access is required for Docker management but its absence
# should NOT prevent the DockerManger web application from starting.
#
# The UI should be able to report Docker Engine connectivity problems.
#
# ============================================================================

if [ -S /var/run/docker.sock ]; then
    echo "[DockerManger] Docker socket detected."

    # The Docker socket group is a host property. Its numeric GID is not
    # portable between Debian, Raspberry Pi OS, NAS distributions, and other
    # Docker hosts. Discover the mounted socket GID at runtime rather than
    # hard-coding a docker group ID in the image or Compose file.
    DOCKER_SOCKET_GID="$(stat -c '%g' /var/run/docker.sock)"
    DOCKER_SOCKET_GROUP="$(getent group "${DOCKER_SOCKET_GID}" | cut -d: -f1 || true)"

    if [ -z "${DOCKER_SOCKET_GROUP}" ]; then
        DOCKER_SOCKET_GROUP="dockermanger-docker"

        # A stale group with our preferred name but a different GID should not
        # prevent startup. Use a GID-specific fallback name in that case.
        if getent group "${DOCKER_SOCKET_GROUP}" >/dev/null 2>&1; then
            DOCKER_SOCKET_GROUP="dockermanger-docker-${DOCKER_SOCKET_GID}"
        fi

        groupadd --gid "${DOCKER_SOCKET_GID}" "${DOCKER_SOCKET_GROUP}"
        echo "[DockerManger] Created Docker socket group ${DOCKER_SOCKET_GROUP} (${DOCKER_SOCKET_GID})."
    else
        echo "[DockerManger] Docker socket group ${DOCKER_SOCKET_GROUP} (${DOCKER_SOCKET_GID}) detected."
    fi

    # Keep the account useful for CLI diagnostics and maintenance commands.
    usermod -aG "${DOCKER_SOCKET_GROUP}" "${PHP_FPM_USER}"

    # PHP-FPM deliberately resets supplementary groups when workers drop from
    # root to www-data. Therefore supplementary membership alone is not enough
    # for the web UI. Make the socket group the FPM workers' primary group.
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

# PHP-FPM clears most inherited environment variables by default. Explicitly
# pass the deployment timezone into the www pool so application bootstrap code
# sees the same TZ value as the container and diagnostic tools.
PHP_TIMEZONE="${TZ:-UTC}"

if [ -f "${PHP_FPM_POOL}" ]; then
    sed -i '/^[[:space:]]*env\[TZ\][[:space:]]*=/d' "${PHP_FPM_POOL}"
    printf '\nenv[TZ] = %s\n' "${PHP_TIMEZONE}" >> "${PHP_FPM_POOL}"

    echo "[DockerManger] PHP-FPM timezone environment set to ${PHP_TIMEZONE}."

    # Catch an invalid dynamically generated pool configuration before runit
    # starts the service and turns the problem into a restart loop.
    php-fpm8.3 -t
fi


# ============================================================================
# Stack Storage
# ============================================================================
#
# The stack tree is commonly a host bind mount owned by a host account whose
# UID/GID has no useful meaning inside this image. Do not recursively chown it:
# doing so would unexpectedly change ownership of an administrator's Compose
# repository on the host. Instead grant the PHP account an ACL and a default
# ACL so existing files and newly-created stack content remain writable while
# preserving their host ownership.
#
# Set DOCKERMANGER_MANAGE_STACK_PERMISSIONS=false to make DockerManger strictly
# observe existing host permissions instead.

MANAGE_STACK_PERMISSIONS="${DOCKERMANGER_MANAGE_STACK_PERMISSIONS:-true}"

if [ "${MANAGE_STACK_PERMISSIONS,,}" = "true" ]; then
    if setfacl -Rm "u:${PHP_FPM_USER}:rwX" "${STACKS_DIR}" 2>/dev/null && \
       setfacl -Rm "d:u:${PHP_FPM_USER}:rwX" "${STACKS_DIR}" 2>/dev/null; then
        echo "[DockerManger] Stack ACL prepared for ${PHP_FPM_USER} without changing host ownership."
    else
        echo "[DockerManger] WARNING: Unable to apply stack ACLs to ${STACKS_DIR}."
        echo "[DockerManger] WARNING: Compose viewing/lifecycle may work, but create/edit operations may be read-only."
    fi
else
    echo "[DockerManger] Automatic stack permission management disabled."
fi

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
