#!/usr/bin/env bash
set -Eeuo pipefail

# DockerManger native Debian/Proxmox host installer.
# This file is intentionally additive: it reads the repository as source and
# installs generated/copy artifacts outside the checkout. Existing repository
# files are never edited by this script. but allows the manger to be a docker or host install
#

#Clone repo and run
#cd DockerManger
#chmod +x host_installer.sh
#./host_installer.sh

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
APP_SRC="$SCRIPT_DIR/app"
TERMINAL_SRC="$SCRIPT_DIR/container/terminal"
PHP_INI_SRC="$SCRIPT_DIR/container/php/docker-manager.ini"
ENV_SRC="$SCRIPT_DIR/dockerenvironment.env"

APP_DST="/var/www/dockermanger"
TERMINAL_DST="/opt/dockermanger-terminal"
ENV_DST="/etc/dockermanger/dockermanger.env"
NGINX_DST="/etc/nginx/sites-available/dockermanger.conf"
SYSTEMD_DST="/etc/systemd/system/dockermanger-terminal.service"
DATA_DIR="/data"
DEFAULT_STACKS_DIR="/opt/stacks"
ACME_DIR="/var/www/acme-challenge"

log()  { printf '\n\033[1;34m[DockerManger Host Installer]\033[0m %s\n' "$*"; }
warn() { printf '\n\033[1;33m[WARNING]\033[0m %s\n' "$*" >&2; }
die()  { printf '\n\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "Run this installer as root (or with sudo)."
[[ -f /etc/os-release ]] || die "Unable to identify this operating system."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  debian) ;;
  *)
    if [[ " ${ID_LIKE:-} " != *" debian "* ]]; then
      die "This installer targets Debian/Proxmox-style hosts. Detected ID=${ID:-unknown}."
    fi
    ;;
esac
[[ -d "$APP_SRC" && -f "$APP_SRC/public/index.php" ]] || die "Run this script from the DockerManger repository root."
[[ -f "$TERMINAL_SRC/server.js" && -f "$TERMINAL_SRC/package.json" ]] || die "Terminal source files are missing."
[[ -f "$PHP_INI_SRC" && -f "$ENV_SRC" ]] || die "Required repository configuration files are missing."

if [[ -r /etc/pve/.version || -d /etc/pve ]]; then
  IS_PROXMOX=true
else
  IS_PROXMOX=false
fi

prompt_default() {
  local __var="$1" __prompt="$2" __default="$3" __value
  read -r -p "$__prompt [$__default]: " __value || true
  printf -v "$__var" '%s' "${__value:-$__default}"
}

prompt_yes_no() {
  local __var="$1" __prompt="$2" __default="$3" __hint __value
  if [[ "$__default" == "yes" ]]; then __hint="Y/n"; else __hint="y/N"; fi
  while true; do
    read -r -p "$__prompt [$__hint]: " __value || true
    __value="${__value:-$__default}"
    case "${__value,,}" in
      y|yes) printf -v "$__var" '%s' "true"; return 0 ;;
      n|no)  printf -v "$__var" '%s' "false"; return 0 ;;
      *) echo "Please answer yes or no." ;;
    esac
  done
}

valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && (( 10#$1 >= 1 && 10#$1 <= 65535 )); }

cat <<EOF

DockerManger native host installation
=====================================
OS          : ${PRETTY_NAME:-$ID}
Proxmox     : $IS_PROXMOX
Repository  : $SCRIPT_DIR
Application : $APP_DST
Runtime data: $DATA_DIR

The repository checkout will be READ ONLY to this installer. It will create or
replace only host installation artifacts under /etc, /opt, /var/www, /data and
the selected stacks directory.

HTTPS remains required, matching the Docker deployment.
EOF

prompt_default HTTP_PORT  "HTTP listen port"  "80"
prompt_default HTTPS_PORT "HTTPS listen port" "443"
valid_port "$HTTP_PORT"  || die "Invalid HTTP port: $HTTP_PORT"
valid_port "$HTTPS_PORT" || die "Invalid HTTPS port: $HTTPS_PORT"
[[ "$HTTP_PORT" != "$HTTPS_PORT" ]] || die "HTTP and HTTPS ports must be different."

prompt_default STACKS_DIR "Compose stacks directory" "$DEFAULT_STACKS_DIR"
[[ "$STACKS_DIR" == /* ]] || die "Stacks directory must be an absolute path."

prompt_default TIMEZONE "Application timezone" "${TZ:-America/Chicago}"
[[ -e "/usr/share/zoneinfo/$TIMEZONE" ]] || warn "Timezone '$TIMEZONE' is not currently present; tzdata installation may provide it."

prompt_yes_no INSTALL_DOCKER "Install/ensure Docker Engine + Compose v2 from Docker's official Debian repository?" "yes"
prompt_yes_no INSTALL_SSHD "Install and enable OpenSSH server?" "yes"

warn "The browser console has no application authentication layer in the current repo. On a native host, its 'local' target is a shell on THIS host, not an isolated container."
prompt_yes_no ENABLE_CONSOLE "Enable the browser terminal service on this host?" "no"

if [[ "$ENABLE_CONSOLE" == "true" ]]; then
  prompt_yes_no CONFIRM_CONSOLE "I understand the native browser console grants powerful host/Docker access" "no"
  [[ "$CONFIRM_CONSOLE" == "true" ]] || { warn "Browser console will remain disabled."; ENABLE_CONSOLE=false; }
fi

prompt_yes_no MANAGE_STACK_PERMS "Grant www-data read/write ACLs on the stacks directory?" "yes"

log "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
  nginx php-fpm php-cli openssh-client openssl nodejs npm build-essential python3 \
  acl bash curl ca-certificates git jq nano mc less vim-tiny procps psmisc \
  iproute2 iputils-ping net-tools dnsutils traceroute netcat-openbsd lsof rsync \
  locales tzdata gnupg

if grep -Eq '^# *en_US.UTF-8 UTF-8' /etc/locale.gen; then
  sed -i 's/^# *\(en_US.UTF-8 UTF-8\)/\1/' /etc/locale.gen
fi
locale-gen en_US.UTF-8 >/dev/null
update-locale LANG=en_US.UTF-8 LANGUAGE=en_US:en LC_ALL=en_US.UTF-8

    ssl_session_tickets off;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "same-origin" always;

    location = /health {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/health.php;
        fastcgi_param SCRIPT_NAME /health.php;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    location = /terminal-ws {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 1d;
        proxy_send_timeout 1d;
    }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    location ~ /\.(?!well-known) { deny all; }
    client_max_body_size 10m;
}
EOF

# Disable Debian's stock default site only when it would collide with our ports.
if [[ -L /etc/nginx/sites-enabled/default ]] && { [[ "$HTTP_PORT" == "80" ]] || [[ "$HTTPS_PORT" == "443" ]]; }; then
  rm -f /etc/nginx/sites-enabled/default
fi
ln -sfn "$NGINX_DST" /etc/nginx/sites-enabled/dockermanger.conf

log "Creating systemd terminal service"
cat > "$SYSTEMD_DST" <<EOF
[Unit]
Description=DockerManger browser terminal service
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=$ENV_DST
WorkingDirectory=$TERMINAL_DST
ExecStart=/usr/bin/node $TERMINAL_DST/server.js
Restart=on-failure
RestartSec=2
User=root
Group=root

[Install]
WantedBy=multi-user.target
EOF

# Validate before restarting anything.
php-fpm${PHP_VERSION} -t
nginx -t

log "Enabling services"
systemctl daemon-reload
systemctl enable --now "$PHP_FPM_SERVICE"
systemctl enable --now nginx
if [[ "$ENABLE_CONSOLE" == "true" ]]; then
  systemctl enable --now dockermanger-terminal.service
else
  systemctl disable --now dockermanger-terminal.service 2>/dev/null || true
fi
systemctl restart "$PHP_FPM_SERVICE" nginx

log "Running installation checks"
FAIL=0
command -v docker >/dev/null && docker version --format 'Docker client: {{.Client.Version}}' 2>/dev/null || { warn "Docker client check failed."; FAIL=1; }
docker compose version 2>/dev/null || { warn "Docker Compose v2 check failed."; FAIL=1; }
systemctl is-active --quiet "$PHP_FPM_SERVICE" || { warn "$PHP_FPM_SERVICE is not active."; FAIL=1; }
systemctl is-active --quiet nginx || { warn "nginx is not active."; FAIL=1; }
if [[ "$ENABLE_CONSOLE" == "true" ]]; then
  systemctl is-active --quiet dockermanger-terminal.service || { warn "Terminal service is not active."; FAIL=1; }
fi
curl -fsS "http://127.0.0.1:$HTTP_PORT/health" >/dev/null || { warn "HTTP health check failed."; FAIL=1; }
curl -kfsS "https://127.0.0.1:$HTTPS_PORT/health" >/dev/null || { warn "HTTPS health check failed."; FAIL=1; }

HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[[ -n "$HOST_IP" ]] || HOST_IP="HOST-IP"
cat <<EOF

============================================================
DockerManger native host installation complete
============================================================
Web UI       : https://$HOST_IP:$HTTPS_PORT
HTTP redirect: http://$HOST_IP:$HTTP_PORT
App copy     : $APP_DST
Runtime env  : $ENV_DST
Runtime data : $DATA_DIR
Stacks       : $STACKS_DIR
PHP-FPM      : $PHP_FPM_SERVICE
Browser tty  : $ENABLE_CONSOLE
SSH server   : $INSTALL_SSHD

Repository source was not modified by installation actions.
Re-run this script after pulling application updates to refresh the installed
copy and generated host configuration.
EOF

if (( FAIL != 0 )); then
  warn "Installation finished with one or more failed checks. Review systemctl status and journalctl before relying on the UI."
  exit 2
fi
