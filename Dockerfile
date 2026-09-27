FROM phusion/baseimage:noble-1.0.0

ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    STACKS_DIR=/opt/stacks \
    TZ=America/Chicago

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Web stack + Docker administration toolbox.
#
# Node/npm are intentionally present for future xterm.js + PTY/WebSocket work,
# but PHP remains the primary application/control plane.
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
        openssh-server \
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

# Docker's official Ubuntu repository. Install the client and Compose plugin
# only; dockerd runs on the host and is reached through docker.sock.
RUN install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
      -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    . /etc/os-release && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME:-$VERSION_CODENAME} stable" \
      > /etc/apt/sources.list.d/docker.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        docker-ce-cli \
        docker-compose-plugin && \
    rm -rf /var/lib/apt/lists/*

RUN mkdir -p \
      /var/www/dockermanger \
      /opt/stacks \
      /data \
      /run/sshd \
      /run/php \
      /etc/service/nginx \
      /etc/service/php-fpm \
      /etc/service/sshd && \
    rm -f /etc/nginx/sites-enabled/default

COPY docker/nginx/default.conf /etc/nginx/conf.d/dockermanger.conf
COPY docker/php/docker-manager.ini /etc/php/8.3/fpm/conf.d/99-dockermanger.ini
COPY docker/php/docker-manager.ini /etc/php/8.3/cli/conf.d/99-dockermanger.ini
COPY docker/ssh/sshd_config.dockermanger /etc/ssh/sshd_config.d/99-dockermanger.conf

COPY docker/services/nginx/run /etc/service/nginx/run
COPY docker/services/php-fpm/run /etc/service/php-fpm/run
COPY docker/services/sshd/run /etc/service/sshd/run

COPY app/ /var/www/dockermanger/
COPY scripts/entrypoint.sh /usr/local/bin/dockermanger-entrypoint
COPY scripts/healthcheck.sh /usr/local/bin/healthcheck.sh
COPY scripts/diagnostics.sh /usr/local/bin/dockermanger-diagnostics

RUN chmod +x \
      /etc/service/nginx/run \
      /etc/service/php-fpm/run \
      /etc/service/sshd/run \
      /usr/local/bin/dockermanger-entrypoint \
      /usr/local/bin/healthcheck.sh \
      /usr/local/bin/dockermanger-diagnostics && \
    chown -R www-data:www-data /var/www/dockermanger

EXPOSE 80 22

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]

ENTRYPOINT ["/usr/local/bin/dockermanger-entrypoint"]
CMD ["/sbin/my_init"]
