# DockerManger

Initial boilerplate for a lightweight, self-hosted Docker/Compose manager.

## Initial goals

- Nginx + PHP-FPM web UI
- Docker CLI and Docker Compose v2 inside the management container
- Control the host Docker daemon through `/var/run/docker.sock`
- Discover Compose projects beneath `/opt/stacks`
- Read-only dashboard first; destructive controls come later
- Optional SSH administration path
- No Node.js / npm frontend dependency

> **Security:** Mounting the Docker socket gives this application effectively
> root-level control of the Docker host. Keep DockerManger on a trusted LAN/VPN
> and do not expose it directly to the public Internet.

## Quick start

```bash
mkdir -p /opt/stacks
docker compose build
docker compose up -d
```

Then open:

`http://HOST-IP:5001`

Health check:

```bash
curl http://127.0.0.1:5001/health
```

## Expected host mounts

- `/var/run/docker.sock:/var/run/docker.sock`
- `/opt/stacks:/opt/stacks`
- `./data:/data`

The host and container stack path intentionally match so relative/absolute
Compose bind paths behave predictably.

## v0.1

This boilerplate provides:

- Nginx
- PHP-FPM
- Docker CLI
- Docker Compose plugin
- OpenSSH server
- Docker daemon connectivity test
- Container discovery
- Compose stack directory discovery
- Dark dashboard modeled after the simple Dockge workflow
- JSON API endpoints for system, containers, and stacks
- Container health check

The UI is intentionally read-only at this stage.
