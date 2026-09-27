FROM phusion/baseimage:noble-1.0.0
ENV DEBIAN_FRONTEND=noninteractive STACKS_DIR=/VMs/docker TZ=America/Chicago
SHELL ["/bin/bash","-o","pipefail","-c"]
RUN apt-get update && apt-get install -y --no-install-recommends nginx php-fpm php-cli php-curl php-mbstring php-xml php-zip php-sqlite3 openssh-client nodejs npm bash curl wget ca-certificates gnupg git jq nano mc less vim-tiny procps psmisc iproute2 iputils-ping net-tools dnsutils traceroute netcat-openbsd lsof rsync unzip zip sqlite3 tzdata && rm -rf /var/lib/apt/lists/*
RUN install -m 0755 -d /etc/apt/keyrings && curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc && chmod a+r /etc/apt/keyrings/docker.asc && . /etc/os-release && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME:-$VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list && apt-get update && apt-get install -y --no-install-recommends docker-ce-cli docker-compose-plugin && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /var/www/dockermanger /VMs/docker /data /run/php /etc/service/nginx /etc/service/php-fpm && rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx/default.conf /etc/nginx/conf.d/dockermanger.conf
COPY docker/services/nginx/run /etc/service/nginx/run
COPY docker/services/php-fpm/run /etc/service/php-fpm/run
COPY app/ /var/www/dockermanger/
COPY scripts/ /usr/local/lib/dockermanger/
RUN chmod +x /etc/service/*/run /usr/local/lib/dockermanger/*.sh && ln -s /usr/local/lib/dockermanger/healthcheck.sh /usr/local/bin/dockermanger-healthcheck && ln -s /usr/local/lib/dockermanger/diagnostics.sh /usr/local/bin/dockermanger-diagnostics
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD ["/usr/local/bin/dockermanger-healthcheck"]
ENTRYPOINT ["/usr/local/lib/dockermanger/entrypoint.sh"]
CMD ["/sbin/my_init"]
