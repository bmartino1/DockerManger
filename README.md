# DockerManger

Docker Hub images: https://hub.docker.com/r/bmmbmm01/dockermanger

DockerManger is a lightweight homelab Docker and Docker Compose
management web UI for `amd64` and `arm64`.

The application uses Nginx, PHP-FPM, Docker CLI + Compose v2, and an
optional Node.js/node-pty browser terminal. Compose files remain the
source of truth beneath the configured stack directory.

> DockerManger is intended for trusted home/lab environments.
> Authentication/authorization remains a future milestone, so keep the
> management UI on a trusted LAN/VPN.

---

# WebUI Example

<img width="1708" height="1138" alt="image" src="https://github.com/user-attachments/assets/fbfdc959-f79b-4fa9-a553-e7789c17c0cc" />

<img width="1715" height="1164" alt="image" src="https://github.com/user-attachments/assets/9e3bc15c-8d0b-4598-a75e-1affc120a579" />

<img width="1690" height="1295" alt="image" src="https://github.com/user-attachments/assets/7606221c-a763-439d-93c1-082f6e015b45" />

<img width="1018" height="1090" alt="image" src="https://github.com/user-attachments/assets/97384762-8acd-476e-a68d-6f4617c327a4" />

<img width="1716" height="1398" alt="image" src="https://github.com/user-attachments/assets/1174c8eb-006b-4ef3-82e3-ae993479d807" />


---

## Installation choices

DockerManger now supports two deployment models.

### 1. Docker / Docker Compose deployment

This remains the normal containerized deployment. DockerManger runs in
its own container and communicates with the host Docker Engine through
`/var/run/docker.sock`.

``` bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

mkdir -p data/certs data/compose_stacks data/ssh
chmod 700 data/ssh

docker compose config
docker compose build --no-cache
docker compose up -d
```

Default container deployment addresses:

``` text
HTTP  -> http://HOST-IP:5001
HTTPS -> https://HOST-IP:5443
```

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the container
deployment details.

### 2. Native / local host deployment

DockerManger can also be installed directly on a Debian-family machine,
including 64-bit Raspberry Pi/Debian and suitable Debian/Proxmox
systems.

``` bash
apt update
apt install -y git

git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

chmod +x host_installer.sh
bash -n host_installer.sh
./host_installer.sh
```

The native installer can install/repair Docker Engine + Compose v2,
Nginx, PHP-FPM, Node.js/npm, the PTY helper dependencies, SSH
components, ACL support, and common homelab/network utilities.

Installer answers are saved in:

``` text
/etc/dockermanger/installer.env
```

Later runs show those saved answers as prompt defaults. For an
unattended repair/update using the saved values:

``` bash
DOCKERMANGER_RECONFIGURE=0 ./host_installer.sh
```

The native defaults are HTTP `80`, HTTPS `443`, and stack storage at
`/opt/stacks`. `/data` and the configured stack directory are preserved
across installer reruns.

See **[docs/LOCAL-HOST-INSTALL.md](docs/LOCAL-HOST-INSTALL.md)** for the
full native-host installation, update, console, permissions, and
troubleshooting guide.

## Current application

Current behavior includes:

-   Docker Engine connectivity and Docker client/server version
    reporting.
-   Container discovery using `docker ps -a`.
-   Container state, image, ports, Compose association, networks,
    mounts, and volumes.
-   Compose stack discovery and validation.
-   Recognition of `compose.yaml`, `compose.yml`, `docker-compose.yml`,
    and `docker-compose.yaml`.
-   Controlled stack/container lifecycle actions and logs.
-   Compose creation/editing with validation.
-   Per-stack `.env` creation/editing.
-   Docker-run-to-Compose conversion using Composerize.
-   Standalone/third-party container detail pages.
-   xterm.js console support through the Node/node-pty terminal helper.
-   JSON resources for system, containers, stacks, logs, and controlled
    actions.

Read-only API resources include:

``` text
GET /api.php?resource=system
GET /api.php?resource=containers
GET /api.php?resource=stacks
```

DockerManger does not expose an arbitrary shell-command HTTP API.

## Repository layout

``` text
DockerManger/
├── app/
│   ├── public/
│   │   ├── api.php
│   │   ├── console.php
│   │   ├── create.php
│   │   ├── health.php
│   │   ├── index.php
│   │   ├── css/app.css
│   │   └── js/
│   ├── src/
│   └── templates/
├── container/
│   ├── nginx/
│   ├── php/
│   ├── terminal/
│   └── scripts/
├── data/
│   ├── certs/
│   ├── compose_stacks/
│   └── ssh/
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DEPLOYMENT.md
│   ├── HOST-CONSOLE.md
│   ├── LOCAL-HOST-INSTALL.md
│   └── RELEASE-CHECKLIST.md
├── Dockerfile
├── compose.yaml
├── dockerenvironment.env
├── host_installer.sh
├── LICENSE
└── README.md
```

## Persistent data

DockerManger keeps runtime state beneath `/data`. It currently has no
application database; Compose files remain the source of truth.

Expected persistent data includes:

``` text
/data/
├── certs/
│   ├── dockermanger.crt
│   └── dockermanger.key
├── ssh/
└── compose_stacks/
```

The configured stack directory may be somewhere else. DockerManger needs
read/write access to it for stack creation and editing.

Avoid `chmod 777`; the Docker/container and native installer paths use
ACLs where appropriate.

## HTTPS

HTTPS is required.

If no TLS certificate/key exists, DockerManger creates a self-signed
bootstrap pair. Existing complete certificate pairs are preserved; an
incomplete pair is treated as an error rather than silently replacing
certificate material.

A browser certificate warning is expected when using the generated
self-signed certificate.

## Architecture support

Primary development targets:

``` text
linux/amd64
linux/arm64
```

Typical host values:

``` text
x86_64   -> amd64
aarch64  -> arm64
```

The current Raspberry Pi target is a 64-bit OS. `arm/v7` is not a
primary target.

The project has been development-tested on both amd64 and arm64,
including a Debian/Proxmox LXC environment and a 64-bit Raspberry Pi.

## Console model

DockerManger keeps interactive PTY traffic separate from PHP's
controlled HTTP API.

Containerized deployment:

``` text
Browser
   |
   +--> xterm.js / WebSocket
             |
             +--> Node/node-pty
                       |
                       +--> local shell inside DockerManger
                       +--> docker exec shell in a managed container
                       +--> optional outbound SSH to Docker host
```

Native deployment differs because DockerManger itself is running on the
host. A native `local` console therefore has host privileges rather than
container isolation.

The native installer prompts before enabling the browser PTY and adapts
the installed UI so the container-oriented Host Console selector is not
shown. The repository application files themselves are not modified.

See [docs/HOST-CONSOLE.md](docs/HOST-CONSOLE.md) for the container
host-console model and
[docs/LOCAL-HOST-INSTALL.md](docs/LOCAL-HOST-INSTALL.md) for native
behavior.

## Docker socket and native-host security

Docker socket access is effectively administrative access to the host
Docker Engine and can lead to host-level control. A root-backed native
browser PTY is similarly powerful.

State-changing web actions use CSRF tokens and explicit named
operations, but user authentication/authorization is not yet
implemented.

Keep DockerManger on a trusted LAN/VPN and do not expose the management
interface directly to the public Internet.

## Updating

Container deployment:

``` bash
cd DockerManger
git pull
docker compose build --no-cache
docker compose up -d
docker compose ps
```

Native deployment:

``` bash
cd DockerManger
git pull
./host_installer.sh
```

Unattended native repair/update using saved settings:

``` bash
DOCKERMANGER_RECONFIGURE=0 ./host_installer.sh
```

## Development status

The core local-build feature set includes Docker/Compose discovery,
controlled lifecycle operations, Compose creation/editing and
validation, `.env` handling, Composerize conversion, logs, runtime
inspection, and browser PTY support.

Native-host installation has been tested during development on both
`arm64` Raspberry Pi/Debian and `amd64` Debian/Proxmox LXC environments.

The goal is to keep DockerManger understandable and maintainable rather
than turning it into a large management framework.

## License

See `LICENSE`.
