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
            +--> future SQLite/settings
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
│   ├── health.php
│   ├── css/app.css
│   └── js/app.js
├── src/
│   ├── bootstrap.php
│   ├── Command.php
│   ├── Compose.php
│   ├── Docker.php
│   ├── Stack.php
│   └── SystemInfo.php
└── templates/
    └── dashboard.php
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

The current API is read-only:

```text
GET /api.php?resource=system
GET /api.php?resource=containers
GET /api.php?resource=stacks
```

Future destructive actions should use explicit operations such as container start/stop/restart or stack up/down rather than passing raw Docker commands from the browser.

Authentication, authorization, and CSRF protection belong in front of destructive actions.

---

## Docker control plane

DockerManger uses the Docker CLI instead of embedding a Docker daemon or requiring a separate Docker SDK.

The current application uses Docker for:

- Engine availability.
- Client/server version information.
- Container discovery.
- Container status.
- Docker Compose project labels.

Future Docker operations should continue to be represented by explicit application methods.

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
├── database/
├── config/
└── compose_stacks/
```

Current uses include:

- `/data/certs` — persistent TLS certificate/key.
- `/data/ssh` — persistent OpenSSH client configuration, known hosts, and optional keys.

Planned uses include:

- `/data/database` — SQLite application state.
- `/data/config` — generated application settings.

Compose project files do **not** move into SQLite. They remain beneath `STACKS_DIR`.

---

## Terminal architecture

The browser terminal is planned but is not implemented in the current PHP application.

Node.js/npm are installed in the image specifically so a small PTY/WebSocket helper can be added without moving the main application to Node.

Planned flow:

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

PHP remains the control plane. It should authorize terminal-session creation and decide which target the user is allowed to access. The PTY service should handle interactive terminal I/O, not become a second general application backend.

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

Actual release support should be declared only after the complete image and its upstream base image/packages have been successfully built and tested for the target architecture.

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
