# DockerManger Architecture

## Overview

DockerManger is intentionally split into a small web control plane and the external Docker Engine it manages.

```text
Browser
   |
   | HTTPS
   v
Nginx
   |
   | FastCGI
   v
PHP-FPM
   |
   v
DockerManger PHP application
   |
   +--> Docker CLI / Compose v2
   |        |
   |        v
   |    /var/run/docker.sock
   |        |
   |        v
   |    Host Docker Engine
   |
   +--> STACKS_DIR
   |        |
   |        v
   |    Compose project files
   |
   +--> /data
            |
            +--> certificates
            +--> SSH client files
```

DockerManger does **not** run `dockerd` inside its own container. The Docker CLI and Docker Compose v2 plugin communicate with the host daemon through the mounted Docker socket.

---

## Web layer

Nginx is the public web server.

It:

- serves DockerManger's static assets;
- forwards PHP requests to PHP-FPM;
- serves the local HTTP health endpoint;
- reserves the ACME HTTP-01 path;
- redirects normal HTTP browser traffic to HTTPS;
- serves the application over HTTPS.

HTTPS is mandatory for the DockerManger application.

On first startup, the container entrypoint creates a self-signed bootstrap certificate when no certificate pair exists beneath `/data/certs`.

PHP-FPM runs the application code beneath `app/`. Nginx exposes `app/public/`; source classes and templates remain outside the public web root.

---

## PHP application

The current application is intentionally small:

```text
app/
├── public/
│   ├── index.php
│   ├── api.php
│   ├── create.php
│   ├── console.php
│   ├── health.php
│   ├── css/app.css
│   └── js/
│       ├── app.js
│       └── console.js
├── src/
│   ├── bootstrap.php
│   ├── Command.php
│   ├── Compose.php
│   ├── Docker.php
│   ├── Stack.php
│   └── SystemInfo.php
└── templates/
    ├── container.php
    ├── dashboard.php
    └── stack.php
```

Responsibilities are separated as follows:

- `index.php` prepares dashboard data and loads the dashboard template.
- `api.php` exposes controlled JSON resources.
- `Command.php` centralizes subprocess execution.
- `Docker.php` provides named Docker CLI operations and parses container data.
- `Compose.php` discovers and validates Compose projects.
- `Stack.php` combines Compose discovery with Docker container state.
- `SystemInfo.php` exposes safe read-only runtime information.
- `dashboard.php` renders the current server-side dashboard.

Command execution is kept behind named PHP methods. DockerManger must not expose a generic HTTP endpoint that accepts arbitrary shell commands.

---

## Current API

Read-only resources include:

```text
GET /api.php?resource=system
GET /api.php?resource=containers
GET /api.php?resource=stacks
```

State changes use POST requests with CSRF tokens and explicit named operations for stack/container lifecycle, Compose/`.env` saves, stack creation/deletion, and Composerize conversion. The browser does not submit arbitrary shell commands to the PHP API.

Authentication/authorization is still future work. Until it exists, the management UI and console should remain on a trusted LAN/VPN.

---

## Docker control plane

DockerManger uses the Docker CLI instead of embedding a Docker daemon or requiring a separate Docker SDK.

The current application uses Docker for:

- Engine availability.
- Client/server version information.
- Container discovery.
- Container status.
- Docker Compose project labels.

Docker operations continue to be represented by explicit application methods rather than arbitrary browser-supplied commands.

The Docker socket is mounted at:

```text
/var/run/docker.sock
```

This socket is effectively a privileged host-management interface. DockerManger therefore assumes a trusted deployment environment.

---

## Compose projects

Compose projects remain ordinary directories beneath `STACKS_DIR`.

The neutral default is:

```text
/opt/stacks
```

DockerManger currently recognizes:

```text
compose.yaml
compose.yml
docker-compose.yml
docker-compose.yaml
```

Compose files remain the source of truth. DockerManger must not require proprietary metadata for a Compose project to remain usable outside DockerManger.

Current validation uses the Docker Compose v2 plugin:

```text
docker compose -f <compose-file> config --quiet
```

Paths used for Compose operations must remain contained beneath the configured stack root.

The current UI-level stack states are:

- `active` — all discovered project containers are running.
- `inactive` — a valid Compose project exists but no project containers were discovered.
- `exited` — project containers exist but none are running.
- `degraded` — Compose validation failed or only part of the project's discovered containers are running.

These are DockerManger presentation states, not new Docker or Compose primitives.

---

## Persistence

Persistent DockerManger application/runtime data lives beneath:

```text
/data
```

Expected layout:

```text
/data/
├── certs/
├── ssh/
└── compose_stacks/
```

Current uses include:

- `/data/certs` — persistent TLS certificate/key.
- `/data/ssh` — persistent OpenSSH client configuration, known hosts, and optional keys.

Additional current use:

- `/data/compose_stacks` — default host-side stack storage for the repository Compose deployment. It is mounted at `/opt/stacks` inside DockerManger.

DockerManger currently has no application database. Compose project files remain the source of truth beneath `STACKS_DIR`.

---

## Terminal architecture

The initial browser terminal is implemented as a small Node/node-pty WebSocket helper behind Nginx. xterm.js runs in the browser while PHP remains the application/control plane. The helper accepts only named local/container/host targets rather than arbitrary command strings.

Current flow:

```text
Browser
   |
   v
xterm.js
   |
   | WebSocket
   v
PTY helper
   |
   +--> local shell inside DockerManger
   |
   +--> docker exec -it <container> /bin/bash
   |        or /bin/sh when bash is unavailable
   |
   +--> ssh <user>@host.docker.internal
```

PHP remains the control plane while the PTY service handles interactive terminal I/O and fixed named targets. The PTY helper must not become a second general application backend. A future authentication/authorization layer should add the user/session boundary before broader exposure.

Container-console support should detect or gracefully fall back between shells such as `/bin/bash` and `/bin/sh`.

---

## Host console

DockerManger is an SSH **client**, not an SSH server.

The host-console path is outbound:

```text
Browser -> xterm.js -> PTY helper -> OpenSSH client -> host SSH server
```

The Docker/LXC/Proxmox/Linux host is responsible for installing, configuring, and securing its own SSH service.

Persistent SSH client files live beneath `/data/ssh` and are exposed to OpenSSH as `/root/.ssh` by the container entrypoint.

See `HOST-CONSOLE.md` for the host-console boundary.

---

## Architecture targets

Primary intended targets are:

```text
linux/amd64
linux/arm64
```

The arm64 target is intended to include 64-bit Raspberry Pi 4/5 deployments.

`arm/v7` is not currently a primary target.

The complete development image has been clean-built and runtime-smoke-tested on both amd64 and arm64, including a 64-bit Raspberry Pi 4. Docker Hub multi-architecture publication/verification remains the release step before documenting registry pull instructions.

---

## Design principles

DockerManger should remain:

- understandable without a large application framework;
- Compose-file-first;
- explicit about privileged Docker access;
- conservative about arbitrary command execution;
- usable with ordinary Docker/Compose projects outside DockerManger;
- small enough to troubleshoot from the command line;
- capable of growing the terminal and authentication layers without making Node.js the primary control plane.

## Stack creation and environment files

DockerManger-created stacks use `compose.yaml` plus the conventional `.env` file in the same stack directory. A top-level Compose `name:` is authoritative for stack/project identity when present; otherwise the DockerManger Stack Name field and stack directory define the project name. Service-level `container_name:` values never rename the stack. The create page can accept Compose directly or convert pasted `docker run` text with the bundled Composerize library. Conversion is a fixed-purpose transformation only; DockerManger does not execute the pasted Docker command. Both Compose and `.env` remain editable after creation.

Destructive controls remain explicit named operations. `Down & Delete` first runs Compose down and only then removes the selected stack directory beneath `STACKS_DIR`. Container `Kill` maps only to the validated `docker kill <container>` operation.
