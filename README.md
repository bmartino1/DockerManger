# DockerManger

DockerManger is a lightweight homelab Docker and Docker Compose management web UI.

The project is being built around a deliberately small control plane:

- **Nginx** serves the HTTPS web interface.
- **PHP-FPM** runs the DockerManger application and API.
- **Docker CLI + Compose v2** communicate with the host Docker Engine through `/var/run/docker.sock`.
- **Compose files remain the source of truth** beneath the configured stack directory.
- **Node.js + node-pty provide the small WebSocket PTY service used by the browser console; PHP remains the control plane.**
- **OpenSSH client only** is installed for optional outbound host-console access. DockerManger does not run an SSH server.

DockerManger is intended for trusted home/lab environments where a simple Docker/Compose workflow is preferred over a larger management platform.

> **Development status:** DockerManger now has a working end-to-end development foundation on both amd64 and arm64: Docker/Compose discovery, controlled lifecycle actions, Compose creation/editing with validation, `.env` editing, Docker-run-to-Compose conversion, runtime/container inspection, logs, and xterm.js consoles for DockerManger and containers. Optional host SSH console support is included and is disabled by default. Authentication/authorization remains a future milestone, so keep the UI on a trusted LAN/VPN.

---

# WebUI Example

<img width="1708" height="1138" alt="image" src="https://github.com/user-attachments/assets/fbfdc959-f79b-4fa9-a553-e7789c17c0cc" />

<img width="1715" height="1164" alt="image" src="https://github.com/user-attachments/assets/9e3bc15c-8d0b-4598-a75e-1affc120a579" />

<img width="1690" height="1295" alt="image" src="https://github.com/user-attachments/assets/7606221c-a763-439d-93c1-082f6e015b45" />

<img width="1018" height="1090" alt="image" src="https://github.com/user-attachments/assets/97384762-8acd-476e-a68d-6f4617c327a4" />

<img width="1716" height="1398" alt="image" src="https://github.com/user-attachments/assets/1174c8eb-006b-4ef3-82e3-ae993479d807" />


---

## Current application

The current dashboard combines information from the host Docker Engine and Compose directories beneath `STACKS_DIR`.

Current application behavior includes:

- Docker Engine connectivity/status.
- Docker client and server version reporting.
- Container discovery using `docker ps -a`.
- Container state, image, ports, Compose-project association, networks, and mount/volume inspection.
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
- Controlled stack/container lifecycle actions and logs.
- Dedicated stack-creation page with Compose validation, per-stack `.env` creation/editing, and Docker-run-to-Compose conversion using Composerize.
- Compose create/edit with validation before replacing the live file.
- Explicit stack Down & Delete and container Kill controls with browser confirmation.
- Standalone/third-party container detail pages.
- xterm.js console targets for DockerManger, containers, and optional outbound host SSH.
- JSON resources for system, container, stack, logs, and controlled actions.

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
│   │   ├── console.php
│   │   ├── create.php
│   │   ├── health.php
│   │   ├── index.php
│   │   ├── favicon.ico
│   │   ├── favicon.png
│   │   ├── css/app.css
│   │   └── js/
│   │       ├── app.js
│   │       └── console.js
│   ├── src/
│   │   ├── bootstrap.php
│   │   ├── Command.php
│   │   ├── Compose.php
│   │   ├── Docker.php
│   │   ├── Stack.php
│   │   └── SystemInfo.php
│   └── templates/
│       ├── container.php
│       ├── dashboard.php
│       └── stack.php
├── container/
│   ├── nginx/
│   │   ├── default.conf
│   │   └── run
│   ├── php/
│   │   ├── docker-manager.ini
│   │   └── run
│   ├── terminal/
│   │   ├── composerize.js
│   │   ├── package.json
│   │   ├── run
│   │   └── server.js
│   └── scripts/
│       ├── diagnostics.sh
│       ├── entrypoint.sh
│       └── healthcheck.sh
├── data/
│   ├── certs/.gitkeep
│   ├── compose_stacks/.gitkeep
│   └── ssh/.gitkeep
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DEPLOYMENT.md
│   ├── HOST-CONSOLE.md
│   └── RELEASE-CHECKLIST.md
├── Dockerfile
├── compose.yaml
├── dockerenvironment.env
├── .gitignore
└── .dockerignore
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

The bootstrap certificate can later be replaced with an administrator-provided trusted certificate. DockerManger does not bundle a certificate-issuance service; an administrator or external ACME client can supply the trusted certificate pair.

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
└── compose_stacks/
```

Runtime certificates, SSH keys/known-host state, and user-created Compose content should not be committed to Git. The repository keeps only `.gitkeep` placeholders beneath the persistent data directories; `.gitignore` and `.dockerignore` prevent runtime material from being committed or baked into the image build context. DockerManger currently has no application database; Compose files remain the source of truth for managed stacks.

---

## Stack storage

The neutral stack path inside the container is:

```text
/opt/stacks
```

A normal repository checkout stores stacks in `./data/compose_stacks`. Deployments can override that host path without changing DockerManger's internal `/opt/stacks` path.

The default Compose mapping is:

```yaml
volumes:
  - "${HOST_STACKS_DIR:-./data/compose_stacks}:${STACKS_DIR:-/opt/stacks}"
```

For example, a host may keep its Compose projects somewhere else while DockerManger continues to see `/opt/stacks`:

```bash
HOST_STACKS_DIR=/srv/docker/stacks docker compose up -d --build
```

`STACKS_DIR` inside DockerManger should normally remain `/opt/stacks`.

For stack identity, a top-level Compose `name:` is authoritative when present. Otherwise the DockerManger Stack Name field/project directory is authoritative. Service-level `container_name:` values identify containers and do not rename the stack.

DockerManger needs write access to this mount for Compose creation/editing. By default the entrypoint uses filesystem ACLs to grant `www-data` read/write access while preserving host ownership. Set `DOCKERMANGER_MANAGE_STACK_PERMISSIONS=false` when the host administrator wants to manage those permissions entirely outside DockerManger. Avoid using `chmod 777` as the normal deployment model.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for deployment examples.

---

## Environment overview

`dockerenvironment.env` contains DockerManger's in-container runtime defaults.

Important current settings include:

| Variable | Default | Purpose |
| --- | --- | --- |
| `TZ` | `America/Chicago` | Container/application timezone. |
| `STACKS_DIR` | `/opt/stacks` | Stack directory inside DockerManger. |
| `DOCKERMANGER_ENABLE_CONSOLE` | `true` | Enables the browser xterm.js + node-pty console subsystem. |
| `DOCKERMANGER_TERMINAL_TYPE` | `xterm-256color` | Terminal type presented to interactive shells. |
| `DOCKERMANGER_CONSOLE_DEFAULT_TARGET` | `local` | Default global-console target. |
| `DOCKERMANGER_HOST_SHELL_ENABLED` | `false` | Optional host SSH console switch; currently disabled by default. |
| `DOCKERMANGER_HOST_SSH_HOST` | `host.docker.internal` | Default outbound SSH target. |
| `DOCKERMANGER_HOST_SSH_PORT` | `22` | Default outbound SSH port. |
| `DOCKERMANGER_HOST_SSH_USER` | `root` | Default outbound SSH user. |
| `DOCKERMANGER_HOST_SSH_KEY` | empty | Optional explicit SSH private-key path. |

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

> The complete development image has been clean-built and runtime-smoke-tested on both `linux/amd64` (Debian/Proxmox LXC test host) and `linux/arm64` (64-bit Raspberry Pi 4). Docker Hub publication is the next release step; `arm/v7` remains outside the current primary target set.

---

## Docker socket security

DockerManger mounts:

```text
/var/run/docker.sock
```

Access to the Docker socket is effectively administrative access to the host Docker Engine and can lead to host-level control.

DockerManger should therefore be deployed only where that level of trust is appropriate. Keep the management UI on a trusted LAN/VPN and do not expose it directly to the public Internet.

State-changing web actions use CSRF tokens and explicit named operations. User authentication/authorization is not yet implemented, so DockerManger should remain on a trusted LAN/VPN.

---

## Console model

The first browser-terminal implementation is now included. It deliberately keeps interactive PTY traffic separate from PHP's controlled HTTP API. The Node service listens on container loopback only; Nginx exposes it as the same-origin `/terminal-ws` WebSocket endpoint.

The separation is:

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

PHP remains the application/control plane. Node.js handles PTY/WebSocket I/O and the fixed-purpose Composerize conversion helper. A future authentication layer should authorize access before privileged console sessions are exposed beyond a trusted LAN/VPN. Docker-run text is converted as data and is never executed by the conversion endpoint.

DockerManger installs the **OpenSSH client only**. It does not expose an SSH server.

See [docs/HOST-CONSOLE.md](docs/HOST-CONSOLE.md).

---

## Development roadmap

The core local-build feature set is now in place: controlled container and Compose lifecycle actions, Compose creation/editing and validation, `.env` handling, Composerize conversion, logs, runtime inspection, and browser PTY consoles. CSRF protection is present on state-changing web actions.

The next release work is intentionally narrow:

1. Final regression testing of Host Console SSH with deployment-specific enablement.
2. Publish and verify multi-architecture Docker Hub images for `linux/amd64` and `linux/arm64`.
3. Add authentication/authorization before treating the UI as suitable for anything beyond a trusted LAN/VPN.
4. Continue certificate-management and UI quality-of-life improvements only as concrete needs arise.
5. Add persistent application settings only if a future feature actually requires them.

The goal is to keep DockerManger understandable and maintainable rather than turning it into a large framework.

---

## Docker Hub

The application has now been clean-built on both amd64 and arm64, and Docker Hub multi-architecture publication is the next release step.

Until the image repository/name and tags are actually published and verified, the supported installation path remains cloning this Git repository and building locally with Docker Compose. Do not document a pull command or image tag until that published artifact exists.

Use [docs/RELEASE-CHECKLIST.md](docs/RELEASE-CHECKLIST.md) for the final amd64/arm64 regression and publication gate.

---

## License

See the repository `LICENSE` file for the project's license terms.
