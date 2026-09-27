# DockerManger

DockerManger is a lightweight homelab Docker and Docker Compose management web UI.

The project is being built around a deliberately small control plane:

- **Nginx** serves the HTTPS web interface.
- **PHP-FPM** runs the DockerManger application and API.
- **Docker CLI + Compose v2** communicate with the host Docker Engine through `/var/run/docker.sock`.
- **Compose files remain the source of truth** beneath the configured stack directory.
- **Node.js/npm are reserved for the future WebTTY/xterm.js PTY/WebSocket service.**
- **OpenSSH client only** is installed for optional outbound host-console access. DockerManger does not run an SSH server.

DockerManger is intended for trusted home/lab environments where a simple Docker/Compose workflow is preferred over a larger management platform.

> **Development status:** DockerManger is under active development. The current application provides the dashboard, Docker/Compose discovery, Compose validation, container visibility, and read-only JSON resources. Container/stack mutation controls, authentication, the Compose editor, logs, and browser terminal are still being built.

---

## Current application

The current dashboard combines information from the host Docker Engine and Compose directories beneath `STACKS_DIR`.

Current application behavior includes:

- Docker Engine connectivity/status.
- Docker client and server version reporting.
- Container discovery using `docker ps -a`.
- Container state, image, ports, and Compose-project association.
- Compose stack discovery.
- Recognition of:
  - `compose.yaml`
  - `compose.yml`
  - `docker-compose.yml`
  - `docker-compose.yaml`
- Compose validation using `docker compose ... config --quiet`.
- Stack summaries using the current states:
  - `active`
  - `inactive`
  - `exited`
  - `degraded`
- Stack search/filtering in the dashboard.
- Read-only JSON resources for system, container, and stack information.

The current read-only API resources are:

```text
GET /api.php?resource=system
GET /api.php?resource=containers
GET /api.php?resource=stacks
```

DockerManger does **not** expose an arbitrary shell-command HTTP API.

---

## Repository layout

```text
DockerManger/
├── app/
│   ├── public/
│   │   ├── api.php
│   │   ├── health.php
│   │   ├── index.php
│   │   ├── css/
│   │   │   └── app.css
│   │   └── js/
│   │       └── app.js
│   ├── src/
│   │   ├── bootstrap.php
│   │   ├── Command.php
│   │   ├── Compose.php
│   │   ├── Docker.php
│   │   ├── Stack.php
│   │   └── SystemInfo.php
│   └── templates/
│       └── dashboard.php
├── container/
│   ├── nginx/
│   │   ├── default.conf
│   │   └── run
│   ├── php/
│   │   ├── docker-manager.ini
│   │   └── run
│   └── scripts/
│       ├── diagnostics.sh
│       ├── entrypoint.sh
│       └── healthcheck.sh
├── data/
│   ├── certs/
│   ├── compose_stacks/
│   ├── config/
│   ├── database/
│   └── ssh/
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DEPLOYMENT.md
│   └── HOST-CONSOLE.md
├── Dockerfile
├── compose.yaml
└── dockerenvironment.env
```

---

## HTTPS

HTTPS is a required part of DockerManger.

The default published ports are:

```text
HTTP  -> 5001
HTTPS -> 5443
```

HTTP is retained for the local health endpoint, future ACME HTTP-01 validation, and redirecting normal browser traffic to HTTPS.

On first container startup, if neither TLS file exists, DockerManger automatically creates a self-signed bootstrap certificate:

```text
/data/certs/dockermanger.crt
/data/certs/dockermanger.key
```

The generated certificate is persistent because `./data` is mounted at `/data`.

Existing certificate pairs are preserved. If only the certificate or only the key exists, startup fails rather than silently replacing certificate material.

The bootstrap certificate can later be replaced with an administrator-provided trusted certificate. Certbot is present in the image for future certificate-management work, but certificate issuance is not automatically performed during container startup.

Because the initial certificate is self-signed, a browser certificate warning is expected until a trusted certificate is installed.

---

## Persistent data

DockerManger keeps application/runtime state beneath `/data`.

The default Compose deployment maps:

```yaml
volumes:
  - ./data:/data
```

Expected persistent layout:

```text
/data/
├── certs/
│   ├── dockermanger.crt
│   └── dockermanger.key
├── ssh/
│   ├── config
│   ├── known_hosts
│   └── optional private/public keys
├── database/
│   └── future SQLite application database
├── config/
│   └── future generated application settings
└── compose_stacks/
```

Runtime certificates, SSH keys, databases, and generated configuration should not be committed to Git.

SQLite remains the planned default application-state database. Compose project definitions themselves remain ordinary files under `STACKS_DIR`.

---

## Stack storage

The neutral stack path inside the container is:

```text
/opt/stacks
```

The repository does not assume a specific host storage layout.

The default Compose mapping is:

```yaml
volumes:
  - "${HOST_STACKS_DIR:-/opt/stacks}:${STACKS_DIR:-/opt/stacks}"
```

For example, a host may keep its Compose projects somewhere else while DockerManger continues to see `/opt/stacks`:

```bash
HOST_STACKS_DIR=/srv/docker/stacks docker compose up -d --build
```

`STACKS_DIR` inside DockerManger should normally remain `/opt/stacks`.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for deployment examples.

---

## Environment overview

`dockerenvironment.env` contains DockerManger's in-container runtime defaults.

Important current settings include:

| Variable | Default | Purpose |
| --- | --- | --- |
| `TZ` | `America/Chicago` | Container/application timezone. |
| `STACKS_DIR` | `/opt/stacks` | Stack directory inside DockerManger. |
| `DOCKERMANGER_ENABLE_CONSOLE` | `true` | Reserves/enables the future console subsystem configuration. |
| `DOCKERMANGER_TERMINAL_TYPE` | `xterm-256color` | Terminal type for future interactive shells. |
| `DOCKERMANGER_CONSOLE_DEFAULT_TARGET` | `local` | Planned default terminal target. |
| `DOCKERMANGER_HOST_SHELL_ENABLED` | `false` | Optional host SSH console switch; currently disabled by default. |
| `DOCKERMANGER_HOST_SSH_HOST` | `host.docker.internal` | Default outbound SSH target. |
| `DOCKERMANGER_HOST_SSH_PORT` | `22` | Default outbound SSH port. |
| `DOCKERMANGER_HOST_SSH_USER` | `root` | Default outbound SSH user. |
| `DOCKERMANGER_HOST_SSH_KEY` | empty | Optional explicit SSH private-key path. |
| `DOCKERMANGER_HOST_SSH_PASSWORD` | empty | Optional password configuration for future console integration. |

`DOCKERMANGER_HTTPS_PORT` is supplied by `compose.yaml` from the externally published `WEB_HTTPS_PORT`. HTTPS itself is not optional.

Host-side Compose interpolation such as `WEB_HTTP_PORT`, `WEB_HTTPS_PORT`, and `HOST_STACKS_DIR` is separate from variables supplied to the container through `env_file:`.

---

## Build and test from Git

Clone the repository and enter it:

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger
```

Create the persistent directories if they are not already present:

```bash
mkdir -p \
  data/certs \
  data/compose_stacks \
  data/config \
  data/database \
  data/ssh

chmod 700 data/ssh
```

Review the Compose configuration:

```bash
docker compose config
```

Build the local development image:

```bash
docker compose build --no-cache
```

Start DockerManger:

```bash
docker compose up -d
```

Check status and startup logs:

```bash
docker compose ps
docker compose logs --tail=100 dockermanger
```

Run the bundled read-only diagnostics:

```bash
docker compose exec dockermanger dockermanger-diagnostics
```

Open the web interface at:

```text
https://HOST-IP:5443
```

The default HTTP address:

```text
http://HOST-IP:5001
```

redirects normal browser requests to HTTPS.

### Update an existing Git deployment

```bash
cd DockerManger
git pull
docker compose build --no-cache
docker compose up -d
docker compose ps
docker compose logs --tail=100 dockermanger
```

Persistent `/data` and the host stack directory are not part of the image and should survive normal image/container replacement when the same volume mappings are retained.

---

## x86_64 / amd64 and Raspberry Pi

The project targets:

```text
linux/amd64
linux/arm64
```

Typical x86_64/amd64 Docker hosts and **64-bit Raspberry Pi 4/5 installations** should use the same repository workflow:

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger
docker compose build --no-cache
docker compose up -d
```

Check the host architecture with:

```bash
uname -m
docker info --format '{{.Architecture}}'
```

Typical values are:

```text
x86_64   -> amd64 host
aarch64  -> arm64 host
```

The current development target for Raspberry Pi is a 64-bit OS. `arm/v7` is not a current primary target.

> Multi-architecture support still needs to be verified through actual builds of the complete image and all upstream image/package dependencies on both `linux/amd64` and `linux/arm64`. Do not treat an architecture as release-tested until that build has been completed successfully.

---

## Docker socket security

DockerManger mounts:

```text
/var/run/docker.sock
```

Access to the Docker socket is effectively administrative access to the host Docker Engine and can lead to host-level control.

DockerManger should therefore be deployed only where that level of trust is appropriate. Keep the management UI on a trusted LAN/VPN and do not expose it directly to the public Internet.

Authentication and CSRF protection are planned before destructive web controls are considered complete.

---

## Console model

The browser-terminal subsystem is not implemented yet.

The intended separation is:

```text
Browser
   |
   +--> xterm.js / WebSocket
            |
            +--> small PTY helper
                    |
                    +--> local shell inside DockerManger
                    +--> docker exec shell inside a managed container
                    +--> outbound ssh to the Docker host
```

PHP remains the application/control plane and will be responsible for authorization/session creation. Node.js is reserved for the PTY/WebSocket portion where PHP is not a good fit.

DockerManger installs the **OpenSSH client only**. It does not expose an SSH server.

See [docs/HOST-CONSOLE.md](docs/HOST-CONSOLE.md).

---

## Development roadmap

The current application is intentionally being built in stages.

Planned work includes:

1. Controlled container actions such as start, stop, and restart.
2. Controlled Compose actions such as up, down, restart, and pull.
3. Compose file viewing/editing with path containment and validation.
4. Container/stack logs.
5. WebTTY/xterm.js terminal support.
6. Authentication, authorization, and CSRF protection before destructive UI operations.
7. SQLite-backed application settings/state where useful.
8. Certificate-management improvements and general UI quality-of-life work.

The goal is to keep DockerManger understandable and maintainable rather than turning it into a large framework.

---

## Docker Hub

A public Docker Hub/multi-architecture image workflow is a work in progress.

For now, the documented installation path is to clone the Git repository and build the image locally with Docker Compose. Image names/tags and registry instructions should be documented once the release/build pipeline is finalized.

---

## License

See the repository `LICENSE` file for the project's license terms.
