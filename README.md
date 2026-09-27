# DockerManger

Lightweight homelab Docker/Compose management UI.

## Core design

PHP/Nginx is the application control plane. DockerManger uses Docker CLI + Compose v2 against the host `/var/run/docker.sock`. Compose projects remain normal files beneath `STACKS_DIR`. Node/npm is available for the future xterm.js PTY/WebSocket helper.

DockerManger installs **OpenSSH client only**. It does not run an SSH server.

### Console model

- **Local console:** WebTTY/xterm -> PTY -> shell inside DockerManger.
- **Host console:** WebTTY/xterm -> PTY -> `ssh` client -> host-provided SSH server.

The Docker/LXC/PVE host is responsible for installing, configuring and securing its SSH server. The default host target is `root@host.docker.internal:2222`. SSH keys/config may be mounted from `./ssh` to `/root/.ssh`.

### Stack path

Default deployment uses `/VMs/docker` on both sides of the bind mount so Compose paths remain predictable.

### Build/test

```bash
mkdir -p data ssh
chmod 700 ssh
docker compose config
docker compose build --no-cache
docker compose up -d
docker compose ps
docker compose logs --tail=100 dockermanger
docker compose exec dockermanger dockermanger-diagnostics
```

Open `http://HOST-IP:5001`.

Primary image targets are `linux/amd64` and `linux/arm64`.

> Mounting the Docker socket gives the application extremely powerful host control. Keep the UI on a trusted LAN/VPN.
