# DockerManger

DockerManger is a lightweight, homelab-focused Docker and Docker Compose web manager.

The project is intentionally **Docker/Compose first**: normal Compose files remain the source of truth, while DockerManger provides a clean web UI over the standard Docker CLI and Compose v2 commands.

The goal is Dockge-adjacent simplicity without reproducing Dockge or Portainer. DockerManger should remain understandable, easy to build from source, easy to troubleshoot from its own terminal, and practical to maintain on both Raspberry Pi ARM64 and x86-64 Docker hosts.

## Design goals

- Simple Nginx + PHP-FPM application/control plane
- Standard HTML/CSS/JavaScript frontend
- Docker CLI + Docker Compose v2, talking to the host through `/var/run/docker.sock`
- Ordinary file-based Compose projects under `/opt/stacks`
- Small Node.js component only where useful, primarily future PTY/WebSocket terminal support
- Useful Linux/Docker troubleshooting tools inside the image
- SQLite for DockerManger application state when persistence is needed
- No Docker daemon inside DockerManger
- Primary architectures: `linux/amd64` and `linux/arm64`
- Build locally from a normal Git clone with a small number of commands

## Security boundary

Mounting `/var/run/docker.sock` gives DockerManger extremely powerful control over the host Docker daemon. Treat the application like a host administration interface.

Recommended deployment is a trusted LAN, management VLAN, or VPN/Tailscale network. Do not expose it directly to the public Internet.

## Current status

The current application is an early read-only foundation.

It currently provides:

- Nginx
- PHP-FPM
- Docker CLI
- Docker Compose v2
- OpenSSH server
- Node.js/npm available for future terminal/frontend helpers
- Docker daemon connectivity checks
- Container discovery
- Compose stack discovery
- Compose configuration validation
- JSON system/container/stack endpoints
- Dark responsive dashboard
- Container health check
- Common diagnostic and editing tools

Write/destructive Docker operations are intentionally not exposed by the web UI yet.

## Container toolbox

The management image includes useful utilities for diagnosing Docker and network problems:

```text
docker / docker compose
bash
curl / wget
ping
ip / ss
netstat
dig / nslookup
traceroute
nc
lsof
ps / pstree / killall
nano / vi / mc
jq
git
rsync
sqlite3
zip / unzip
```

This is intentional: the DockerManger shell should also be a useful Docker administration toolbox.

## Stack layout

DockerManger expects ordinary Compose project directories:

```text
/opt/stacks/
├── adguardhome/
│   ├── compose.yaml
│   └── .env
├── immich/
│   └── compose.yaml
├── plex/
│   └── compose.yaml
└── ...
```

The host and container paths should normally match:

```yaml
volumes:
  - /opt/stacks:/opt/stacks
```

This helps Compose bind paths behave predictably.

## Quick start

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

mkdir -p data
sudo mkdir -p /opt/stacks

docker compose build
docker compose up -d
```

Open:

```text
http://HOST-IP:5001
```

Check status:

```bash
docker compose ps
docker compose logs -f dockermanger
curl http://127.0.0.1:5001/health
```

Enter the management container:

```bash
docker compose exec dockermanger bash
```

Then useful tests include:

```bash
docker version
docker compose version
docker ps
docker info
ping 1.1.1.1
dig example.com
curl -I https://example.com
ip addr
ss -lntup
mc
```

## Configuration

Defaults can be overridden with `.env`.

Copy:

```bash
cp .env.example .env
```

Current options:

```text
TZ=America/Chicago
STACKS_DIR=/opt/stacks
WEB_PORT=5001
SSH_PORT=2222
```

## SSH

OpenSSH is installed as an optional administration path. Password login and root password login are disabled by default.

The future browser terminal will **not** depend on SSH. The planned web terminal will use a dedicated PTY/WebSocket helper and will be authenticated by DockerManger.

## Data storage

Compose definitions stay as files in `STACKS_DIR`.

DockerManger-specific persistent data belongs in:

```text
/data
```

SQLite is available and is the preferred initial application database for users, settings, sessions, preferences, and audit data. PostgreSQL/MySQL are not bundled into this image. External database support can be added later if a real need develops.

## Planned development

### v0.1 — foundation

- Docker connectivity
- stack discovery
- container discovery
- Compose validation
- diagnostics toolbox
- amd64 + arm64 build foundation

### v0.2 — Docker controls

- start / stop / restart
- logs
- inspect
- stats

### v0.3 — Compose management

- Compose editor
- `.env` editor
- validation
- `up -d`
- `down`
- `pull`
- recreate/update
- new stack creation

### v0.4 — terminal

- xterm.js frontend
- small Node.js WebSocket/PTY service
- DockerManger shell
- container shell with `/bin/bash` → `/bin/sh` fallback

### v0.5 — authentication and safety

- local users
- password hashing
- sessions
- CSRF protection
- audit log
- authorization around command execution

### v0.6 — quality of life

- image/update information
- network and volume inspection
- safe cleanup tools
- stack search
- templates
- Docker-run-to-Compose helpers

## Multi-architecture

Primary supported targets:

```text
linux/amd64
linux/arm64
```

Build the current architecture normally:

```bash
docker compose build
```

Or use Buildx:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t dockermanger:dev \
  .
```

A multi-platform build without `--push`/`--output` may not be loaded into the local Docker image store. For normal local testing, `docker compose build` is simplest.

## Philosophy

DockerManger should stay boring in the good sense:

- Docker remains Docker.
- Compose files remain normal Compose files.
- Commands should be visible and understandable.
- The repository should be readable without reverse-engineering a large framework.
- Dependencies should earn their place.
- A failed UI should never make the underlying Compose projects unusable.
