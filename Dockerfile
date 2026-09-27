FROM phusion/baseimage:noble-1.0.0

# BuildKit supplies TARGETARCH during multi-platform builds.
# Primary DockerManger targets:
#   linux/amd64  - x86-64 Docker / Debian / Proxmox environments
#   linux/arm64  - Raspberry Pi 4/5 running a 64-bit OS
ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    STACKS_DIR=/opt/stacks \
    TZ=America/Chicago

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# ---------------------------------------------------------------------------
# DockerManger base packages
# ---------------------------------------------------------------------------
#
# PHP + Nginx remain the primary web application/control plane.
#
# Node/npm are included for the future xterm.js + PTY/WebSocket terminal
# service. They are not intended to replace the PHP application backend.
#
# OpenSSH CLIENT is installed for the optional host console:
#
#   Browser -> WebTTY/xterm.js -> PTY -> ssh -> Docker/PVE host
#
# DockerManger does NOT run an SSH server. The target host must provide and
# secure its own SSH service.
#
# The remaining packages make the local DockerManger console useful for
# Docker, networking, DNS, filesystem and general homelab diagnostics.
#
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        nginx \
        php-fpm \
        php-cli \
        php-curl \
        php-mbstring \
        php-xml \
        php-zip \
        php-sqlite3 \
        openssh-client \
        nodejs \
        npm \
        bash \
        curl \
        wget \
        ca-certificates \
        gnupg \
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
        unzip \
        zip \
        sqlite3 \
        tzdata && \
    rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# Docker CLI + Docker Compose v2
# ---------------------------------------------------------------------------
#
# DockerManger does NOT run dockerd.
#
# The Docker CLI communicates with the host Docker Engine through the
# /var/run/docker.sock bind mount supplied by the deployment compose file.
#
# Docker's Ubuntu repository supports our primary amd64 and arm64 targets.
# dpkg --print-architecture automatically selects the architecture belonging
# to the current BuildKit target.
#
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

# ---------------------------------------------------------------------------
# DockerManger filesystem
# ---------------------------------------------------------------------------
#
# /opt/stacks is the neutral/default stack location INSIDE the container.
# A deployment may map any host directory to it:
#
#   /opt/stacks:/opt/stacks
#   /mnt/docker:/opt/stacks
#   /some/site/specific/path:/opt/stacks
#
# Host storage layout must never be baked into the DockerManger image.
#
RUN mkdir -p \
        /var/www/dockermanger \
        /opt/stacks \
        /data \
        /run/php \
        /etc/service/nginx \
        /etc/service/php-fpm && \
    rm -f /etc/nginx/sites-enabled/default

# ---------------------------------------------------------------------------
# Nginx / PHP configuration
# ---------------------------------------------------------------------------

COPY docker/nginx/default.conf \
    /etc/nginx/conf.d/dockermanger.conf

COPY docker/php/docker-manager.ini \
    /etc/php/8.3/fpm/conf.d/99-dockermanger.ini

COPY docker/php/docker-manager.ini \
    /etc/php/8.3/cli/conf.d/99-dockermanger.ini

# ---------------------------------------------------------------------------
# Phusion/runit services
# ---------------------------------------------------------------------------
#
# Only the services DockerManger actually needs are supervised here.
# There is deliberately no sshd service.
#
COPY container/services/nginx/run \
    /etc/service/nginx/run

COPY container/services/php-fpm/run \
    /etc/service/php-fpm/run

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------

COPY app/ /var/www/dockermanger/

# Keep operational scripts individually named so their purpose and installed
# location remain obvious when reading the repository or inspecting the image.
COPY scripts/entrypoint.sh \
    /usr/local/bin/dockermanger-entrypoint

COPY scripts/healthcheck.sh \
    /usr/local/bin/dockermanger-healthcheck

COPY scripts/diagnostics.sh \
    /usr/local/bin/dockermanger-diagnostics

RUN chmod +x \
        /etc/service/nginx/run \
        /etc/service/php-fpm/run \
        /usr/local/bin/dockermanger-entrypoint \
        /usr/local/bin/dockermanger-healthcheck \
        /usr/local/bin/dockermanger-diagnostics && \
    chown -R www-data:www-data /var/www/dockermanger

# ---------------------------------------------------------------------------
# Network
# ---------------------------------------------------------------------------
#
# Only the web interface is exposed.
# Host-console SSH is an OUTBOUND connection from DockerManger to the host.
#
EXPOSE 80

# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

HEALTHCHECK \
    --interval=30s \
    --timeout=5s \
    --start-period=20s \
    --retries=3 \
    CMD ["/usr/local/bin/dockermanger-healthcheck"]

# Phusion Baseimage provides my_init/runit for service supervision.
ENTRYPOINT ["/usr/local/bin/dockermanger-entrypoint"]
CMD ["/sbin/my_init"]
