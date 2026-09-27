FROM phusion/baseimage:noble-1.0.0

# ============================================================================
# DockerManger Container Image
# ============================================================================
#
# DockerManger is a lightweight web management interface for Docker and
# Docker Compose environments.
#
# Primary application components:
#
#   Nginx       - HTTP/HTTPS frontend
#   PHP-FPM     - DockerManger application/backend
#   Docker CLI  - communicates with the host Docker Engine
#   Node.js     - WebSocket + node-pty browser terminal service
#   OpenSSH     - CLIENT ONLY for optional outbound host administration
#
# DockerManger does NOT run:
#
#   - dockerd
#   - an SSH server
#   - a database server
#
# Docker Engine access is provided by mounting:
#
#   /var/run/docker.sock
#
# Application state is stored beneath:
#
#   /data
#
# Compose stacks are exposed inside the container beneath:
#
#   /opt/stacks
#
# ============================================================================


# ----------------------------------------------------------------------------
# Build Architecture
# ----------------------------------------------------------------------------
#
# BuildKit supplies TARGETARCH during multi-platform builds.
#
# Primary DockerManger targets:
#
#   linux/amd64  - x86-64 Docker / Debian / Ubuntu / Proxmox environments
#   linux/arm64  - Raspberry Pi 4/5 running a 64-bit operating system
#
# TARGETARCH is retained even though the current APT-based installation does
# not require architecture-specific branching. It gives us an explicit place
# for future native Node/PTTY dependencies if required.
#

ARG TARGETARCH


# ----------------------------------------------------------------------------
# Environment
# ----------------------------------------------------------------------------

ENV DEBIAN_FRONTEND=noninteractive \
    STACKS_DIR=/opt/stacks \
    TZ=America/Chicago \
    LANG=en_US.UTF-8 \
    LANGUAGE=en_US:en \
    LC_ALL=en_US.UTF-8

SHELL ["/bin/bash", "-o", "pipefail", "-c"]


# ============================================================================
# Base Packages
# ============================================================================
#
# PHP + Nginx form the primary application/control plane.
#
# Node.js/npm provide the xterm.js + node-pty WebSocket terminal service.
# They are intentionally limited to terminal I/O; PHP remains the control plane.
#
# OpenSSH CLIENT is installed for optional outbound host administration:
#
#   Browser
#      -> DockerManger WebTTY
#      -> PTY
#      -> ssh
#      -> Docker / LXC / Proxmox host
#
# DockerManger does NOT run sshd.
#
# The remaining utilities make DockerManger useful for Docker, filesystem,
# network, DNS and general homelab diagnostics.
#
# ============================================================================

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        nginx \
        php-fpm \
        php-cli \
        openssh-client \
        openssl \
        nodejs \
        npm \
        build-essential \
        python3 \
        acl \
        bash \
        curl \
        ca-certificates \
        git \
        jq \
        nano \
        mc \
        less \
        vim-tiny \
        procps \
        psmisc \
        iproute2 \
        iputils-ping \
        net-tools \
        dnsutils \
        traceroute \
        netcat-openbsd \
        lsof \
        rsync \
        locales \
        tzdata && \
    sed -i 's/^# *\(en_US.UTF-8 UTF-8\)/\1/' /etc/locale.gen && \
    locale-gen en_US.UTF-8 && \
    update-locale LANG=en_US.UTF-8 LANGUAGE=en_US:en LC_ALL=en_US.UTF-8 && \
    rm -rf /var/lib/apt/lists/*


# ============================================================================
# Docker CLI + Docker Compose v2
# ============================================================================
#
# DockerManger does NOT run its own Docker daemon.
#
# The Docker CLI communicates with the host Docker Engine through:
#
#   /var/run/docker.sock
#
# supplied by the deployment's Compose configuration.
#
# Docker's official Ubuntu repository supports the primary DockerManger
# architectures. dpkg --print-architecture selects the architecture belonging
# to the current BuildKit target.
#
# ============================================================================

RUN install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    . /etc/os-release && \
    echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME:-$VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        docker-ce-cli \
        docker-compose-plugin && \
    rm -rf /var/lib/apt/lists/*


# ============================================================================
# DockerManger Filesystem
# ============================================================================
#
# /opt/stacks is the neutral/default stack location INSIDE the container.
#
# A deployment can map any host directory to this location:
#
#   /opt/stacks:/opt/stacks
#   /mnt/docker:/opt/stacks
#   /srv/docker/stacks:/opt/stacks
#
# Host-specific storage layouts must never be baked into this image.
#
# /data stores persistent DockerManger runtime state such as TLS certificates
# and SSH client material.
#
# ============================================================================

RUN mkdir -p \
        /var/www/dockermanger \
        /opt/stacks \
        /data \
        /run/php \
        /etc/service/nginx \
        /etc/service/php-fpm \
        /var/www/acme-challenge && \
    rm -f /etc/nginx/sites-enabled/default


# ============================================================================
# Nginx Configuration
# ============================================================================

COPY container/nginx/default.conf \
    /etc/nginx/conf.d/dockermanger.conf


# ============================================================================
# PHP Configuration
# ============================================================================
#
# Use the same DockerManger PHP configuration for both FPM and CLI.
#
# PHP-FPM:
#   Web application
#
# PHP CLI:
#   Diagnostics
#   Maintenance
#   Administrative and diagnostic tools
#
# ============================================================================

COPY container/php/docker-manager.ini \
    /etc/php/8.3/fpm/conf.d/99-dockermanger.ini

COPY container/php/docker-manager.ini \
    /etc/php/8.3/cli/conf.d/99-dockermanger.ini


# ============================================================================
# Phusion / runit Services
# ============================================================================
#
# Repository service launchers live beside their corresponding configuration:
#
#   container/nginx/run
#   container/php/run
#
# Inside the image they are installed into runit's expected service paths.
#
# Only services actually required by DockerManger are supervised here.
#
# ============================================================================

COPY container/nginx/run \
    /etc/service/nginx/run

COPY container/php/run \
    /etc/service/php-fpm/run

COPY container/terminal/run \
    /etc/service/dockermanger-terminal/run


# ============================================================================
# DockerManger Application
# ============================================================================

COPY app/ /var/www/dockermanger/

# Install the small PTY/WebSocket helper and vendor xterm.js into the public
# tree so the console does not depend on a third-party CDN at runtime.
COPY container/terminal/package.json /opt/dockermanger-terminal/package.json
COPY container/terminal/server.js /opt/dockermanger-terminal/server.js
COPY container/terminal/composerize.js /opt/dockermanger-terminal/composerize.js
RUN cd /opt/dockermanger-terminal && \
    npm install --omit=dev --no-audit --no-fund && \
    mkdir -p /var/www/dockermanger/public/vendor/xterm && \
    cp node_modules/@xterm/xterm/lib/xterm.js /var/www/dockermanger/public/vendor/xterm/xterm.js && \
    cp node_modules/@xterm/xterm/css/xterm.css /var/www/dockermanger/public/vendor/xterm/xterm.css


# ============================================================================
# Operational Scripts
# ============================================================================
#
# Keep operational scripts individually named so their purpose and installed
# location remain obvious when reading the repository or inspecting the image.
#
# ============================================================================

COPY container/scripts/entrypoint.sh \
    /usr/local/bin/dockermanger-entrypoint

COPY container/scripts/healthcheck.sh \
    /usr/local/bin/dockermanger-healthcheck

COPY container/scripts/diagnostics.sh \
    /usr/local/bin/dockermanger-diagnostics

# Make Midnight Commander preserve the directory selected when it exits.
# This belongs in the image profile rather than as a separate repository script.
RUN cat >> /etc/profile <<'EOF'

# DockerManger Midnight Commander helper.
# `mc` runs in a child process, so use -P to return its final directory and
# apply that directory to the current interactive shell after MC exits.
mc_cd() {
    MC_TMPFILE="$(mktemp /tmp/mc-last-dir.XXXXXX)" || return 1

    command mc -P "$MC_TMPFILE"
    MC_STATUS=$?

    if [ -f "$MC_TMPFILE" ]; then
        LAST_DIR="$(cat "$MC_TMPFILE")"
        rm -f "$MC_TMPFILE"

        if [ -n "$LAST_DIR" ] && [ -d "$LAST_DIR" ]; then
            cd "$LAST_DIR" || return 1
        fi
    fi

    return "$MC_STATUS"
}

alias mc='mc_cd'
EOF


# ============================================================================
# Permissions
# ============================================================================

RUN chmod +x \
        /etc/service/nginx/run \
        /etc/service/php-fpm/run \
        /etc/service/dockermanger-terminal/run \
        /usr/local/bin/dockermanger-entrypoint \
        /usr/local/bin/dockermanger-healthcheck \
        /usr/local/bin/dockermanger-diagnostics && \
    chown -R www-data:www-data /var/www/dockermanger


# ============================================================================
# Network
# ============================================================================
#
# Port 80:
#   Health endpoint, optional ACME HTTP-01 challenge files, and HTTPS redirect
#
# Port 443:
#   Required DockerManger HTTPS application interface
#
# EXPOSE documents the container ports. The deployment Compose file determines
# which ports are actually published on the Docker host.
#
# Host-console SSH is an OUTBOUND connection from DockerManger to another
# machine. DockerManger does not expose an SSH port.
#
# ============================================================================

EXPOSE 80
EXPOSE 443


# ============================================================================
# Health Check
# ============================================================================
#
# The health check verifies DockerManger itself.
#
# Docker Engine connectivity, individual managed containers, Compose stacks
# and optional host SSH connectivity must not determine whether this container
# itself is healthy.
#
# ============================================================================

HEALTHCHECK \
    --interval=5m \
    --timeout=10s \
    --start-period=30s \
    --retries=3 \
    CMD ["/usr/local/bin/dockermanger-healthcheck"]


# ============================================================================
# Container Startup
# ============================================================================
#
# DockerManger's entrypoint performs lightweight initialization and then hands
# control to Phusion Baseimage's my_init/runit service supervisor.
#
# ============================================================================

ENTRYPOINT ["/usr/local/bin/dockermanger-entrypoint"]

CMD ["/sbin/my_init"]
