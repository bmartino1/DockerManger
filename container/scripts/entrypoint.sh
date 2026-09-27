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

HTTPS_ENABLED="${DOCKERMANGER_HTTPS_ENABLED:-true}"
HTTPS_PORT="${DOCKERMANGER_HTTPS_PORT:-5443}"

DATA_DIR="/data"
CERT_DIR="${DATA_DIR}/certs"
SSH_DIR="${DATA_DIR}/ssh"

TLS_CERT="${CERT_DIR}/dockermanger.crt"
TLS_KEY="${CERT_DIR}/dockermanger.key"


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
echo "HTTPS        : ${HTTPS_ENABLED}"
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
# DockerManger's Nginx configuration expects:
#
#   /data/certs/dockermanger.crt
#   /data/certs/dockermanger.key
#
# When HTTPS is enabled and no certificate exists, create a self-signed
# certificate so a fresh installation can start HTTPS immediately.
#
# This certificate is intended as a bootstrap/fallback certificate.
#
# It can later be replaced by:
#
#   - an administrator-provided certificate
#   - a locally trusted certificate
#   - Certbot / Let's Encrypt
#   - future DockerManger certificate-management tooling
#
# ============================================================================

if [ "${HTTPS_ENABLED}" = "true" ]; then

    if [ -f "${TLS_CERT}" ] && [ -f "${TLS_KEY}" ]; then
        echo "[DockerManger] TLS certificate detected."

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
            -subj "/CN=DockerManger"

        chmod 600 "${TLS_KEY}"
        chmod 644 "${TLS_CERT}"

        echo "[DockerManger] Bootstrap TLS certificate generated."

    else
        echo "[DockerManger] ERROR: Incomplete TLS certificate pair."
        echo "[DockerManger] Expected:"
        echo "[DockerManger]   ${TLS_CERT}"
        echo "[DockerManger]   ${TLS_KEY}"
        exit 1
    fi

else
    echo "[DockerManger] HTTPS disabled."
fi


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
else
    echo "[DockerManger] WARNING: /var/run/docker.sock not detected."
fi


# ============================================================================
# Stack Storage
# ============================================================================

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


# ============================================================================
# Initialization Complete
# ============================================================================

echo "============================================================"
echo "[DockerManger] Initialization complete."
echo "============================================================"

exec "$@"
