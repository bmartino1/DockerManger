# DockerManger

[![Docker Hub](https://img.shields.io/badge/Docker%20Hub-bmmbmm01%2Fdockermanger-2496ED?logo=docker&logoColor=white)](https://hub.docker.com/r/bmmbmm01/dockermanger)
[![GitHub Source](https://img.shields.io/badge/Source-bmartino1%2FDockerManger-181717?logo=github&logoColor=white)](https://github.com/bmartino1/DockerManger)

DockerManger is a lightweight homelab Docker and Docker Compose
management web UI for `amd64` and `arm64`. Docker Hub publishes **three tags**:
`latest` (multi-architecture), `amd64` (x86-64), and `arm64` (64-bit ARM).

The application uses Nginx, PHP-FPM, Docker CLI + Compose v2, and an
optional Node.js/node-pty browser terminal. Compose files remain the
source of truth beneath the configured stack directory.

> DockerManger is intended for trusted home/lab environments.
> DockerManger includes first-run administrator setup and session-based login.
> Docker socket access remains highly privileged: use only on a trusted LAN/VPN,
> and do not expose the management interface directly to the Internet.

---

# WebUI Example

<img width="1708" height="998" alt="Screenshot 2026-11-01 150627" src="https://github.com/user-attachments/assets/1958d81e-1c6d-4db3-a154-6a52ce06b8fa" />

<img width="1712" height="959" alt="Screenshot 2026-11-01 150646" src="https://github.com/user-attachments/assets/d7ea34da-8aca-4b3c-a7d7-7feb93c8760f" />

<img width="1715" height="993" alt="Screenshot 2026-11-01 150701" src="https://github.com/user-attachments/assets/e8c10328-83b9-4b39-a740-fa1051198459" />

<img width="1706" height="1094" alt="Screenshot 2026-11-01 150714" src="https://github.com/user-attachments/assets/71108eda-ef66-4060-9522-ddb4f93cb062" />

<img width="1698" height="881" alt="Screenshot 2026-11-01 150725" src="https://github.com/user-attachments/assets/f8f9a460-11e8-4ce7-928f-6e1b0bf692bc" />

<img width="1701" height="481" alt="Screenshot 2026-11-01 150739" src="https://github.com/user-attachments/assets/3d857c37-7c74-4cc1-a1f0-0db216631447" />

<img width="1713" height="583" alt="Screenshot 2026-11-01 150750" src="https://github.com/user-attachments/assets/89aae8bc-6d82-4369-9f59-89afbc59e14d" />


---

## Administrator setup and login

On the first visit over HTTPS, DockerManger redirects to `/setup.php` to create
its administrator account. The username field is prefilled with `admin`, but
**there is no default password**. Choose your own username and password.

- Usernames must be 3–32 characters, start with a letter, and contain only
  letters, numbers, `_`, or `-`.
- The default minimum password length is 12 characters. Configure
  `DOCKERMANGER_MIN_PASSWORD_LENGTH=12` in `dockerenvironment.env` (supported
  minimum range: 8–128).
- After setup, sign in at `/login.php`; use **Sign out** to end the session.
- The account is stored as a password hash in `/data/auth/admin.json`. Keep
  `/data` persistent and protect its contents. No application database is required.
- Existing installations with a persisted administrator account go directly to
  the login screen after upgrading. A fresh `/data` requires new setup.

Authentication protects the web UI and API but is **not** a substitute for
network isolation. Mounting `/var/run/docker.sock` gives the application
administrative control over the host Docker Engine.

## Pull the published Docker Hub image

The published **`latest`** tag is a multi-platform image index for
`linux/amd64` and `linux/arm64`. Docker automatically pulls the right platform.
The `amd64` and `arm64` tags are single-platform alternatives.

| Tag | Platforms | When to use |
| --- | --- | --- |
| [`latest`](https://hub.docker.com/r/bmmbmm01/dockermanger/tags) | `linux/amd64`, `linux/arm64` | **Recommended**; automatic architecture selection |
| `amd64` | `linux/amd64` | Explicit x86-64 image |
| `arm64` | `linux/arm64` | Explicit ARM64 image, such as 64-bit Raspberry Pi |

The three tags were published and the multi-platform index was verified
on **November 1, 2026**. The verified `latest` digest at publication was
`sha256:45a3863a518c1393df23fc19081a3010cb2b7b0af3ccc367398347947f92b3f4`.
Tags are mutable; pin this digest for reproducible deployments, or follow
`latest` to receive subsequent releases.
For an existing deployment that uses `image: bmmbmm01/dockermanger:latest`:

```bash
cd /path/to/your/dockermanger-deployment
docker compose config --quiet
docker compose pull dockermanger
docker compose up -d --no-deps --force-recreate dockermanger
docker compose ps
```

Keep the existing `./data:/data` volume and stack mounts unchanged during
upgrades. Back up the deployment's `compose.yaml`, environment file, and
`data/` before major changes. The repository's own `compose.yaml` instead
builds `dockermanger:dev` from source; it is not the same as a pull-only
Docker Hub deployment.

Example pull commands:

```bash
docker pull bmmbmm01/dockermanger:latest
# Optional platform-specific pulls:
docker pull bmmbmm01/dockermanger:amd64
docker pull bmmbmm01/dockermanger:arm64
```

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

See [docs/DEPLOYMENT.md](https://github.com/bmartino1/DockerManger/blob/main/docs/DEPLOYMENT.md) for the container
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

See **[docs/LOCAL-HOST-INSTALL.md](https://github.com/bmartino1/DockerManger/blob/main/docs/LOCAL-HOST-INSTALL.md)** for the
full native-host installation, update, console, permissions, and
troubleshooting guide.

## Web UI and features

The current navigation is intentionally small: **Dashboard** (default landing
page), **Apps** (cards, filtering, third-party discovery and contextual display
settings), **Stacks** (Compose project management and creation), and
**Resources** (on-demand CPU/RAM usage with optional 30-second refresh).
A top-right **Console** button provides terminal access. The Dashboard retains
Docker host/runtime versions (including PHP, Node.js and npm), container
inventory, ports, ownership, and mount details. Standalone containers remain
available from the inventory; their controls have not been removed.

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
-   JSON resources for system, containers, stacks, logs, one-shot Docker stats,
    and controlled actions.

Read-only API resources include:

``` text
GET /api.php?resource=system
GET /api.php?resource=containers
GET /api.php?resource=stacks
GET /api.php?resource=stats
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
│   │   ├── setup.php / login.php / logout.php
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
│   ├── auth/
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
├── auth/
│   └── admin.json
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

The current images were built from the same GitHub source checkout with
Docker Buildx on an amd64 Debian/Proxmox LXC. ARM64 execution used
PVE-host `binfmt_misc`/QEMU. Both locally built images passed basic runtime
smoke tests (PHP, Node.js, npm, Docker CLI, Compose, and `node-pty`/WebSocket
module loading) before publication. This is **not** a substitute for a full
browser/SSH integration test of each published image.

## Rebuilding and publishing multi-architecture images

A Buildx builder with the `docker-container` driver and functioning
`linux/arm64` emulation (or a native ARM64 builder) is required to reproduce
the cross-architecture build on an amd64 host. On a Proxmox LXC, configure
QEMU/`binfmt_misc` on the PVE host if the container cannot register it.

From a clean checkout, first validate the ARM64 runtime:

```bash
docker run --rm --platform linux/arm64 alpine:3.22 uname -m
docker buildx create --name dockermanger-multiarch --driver docker-container --use
docker buildx inspect --bootstrap
```

The Alpine command should return `aarch64`. If a builder with this name
already exists, use `docker buildx use dockermanger-multiarch` instead.

Build each platform locally and test it before publishing:

```bash
docker buildx build --builder dockermanger-multiarch --platform linux/amd64 \
  -t dockermanger:test-amd64 --load .
docker buildx build --builder dockermanger-multiarch --platform linux/arm64 \
  -t dockermanger:test-arm64 --load .

for arch in amd64 arm64; do
  docker run --rm --platform "linux/$arch" --entrypoint /bin/bash \
    "dockermanger:test-$arch" -c \
    'php -v | head -1; node -v; npm -v; docker --version; docker compose version; cd /opt/dockermanger-terminal && node -e "require(\"node-pty\"); require(\"ws\"); console.log(\"PTY modules OK\")"'
done
```

After validation, log in to Docker Hub, tag and push **the tested images**,
and create the combined `latest` index without rebuilding:

```bash
docker login -u bmmbmm01
docker tag dockermanger:test-amd64 bmmbmm01/dockermanger:amd64
docker tag dockermanger:test-arm64 bmmbmm01/dockermanger:arm64
docker push bmmbmm01/dockermanger:amd64
docker push bmmbmm01/dockermanger:arm64
docker buildx imagetools create -t bmmbmm01/dockermanger:latest \
  bmmbmm01/dockermanger:amd64 bmmbmm01/dockermanger:arm64
docker buildx imagetools inspect bmmbmm01/dockermanger:latest
```

Verify that the final index lists both `linux/amd64` and `linux/arm64`.
Keep the Docker Hub repository to the three user-facing tags; registry digest
records may still be visible separately.

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

See [docs/HOST-CONSOLE.md](https://github.com/bmartino1/DockerManger/blob/main/docs/HOST-CONSOLE.md) for the container
host-console model and
[docs/LOCAL-HOST-INSTALL.md](https://github.com/bmartino1/DockerManger/blob/main/docs/LOCAL-HOST-INSTALL.md) for native
behavior.

## Docker socket and native-host security

Docker socket access is effectively administrative access to the host
Docker Engine and can lead to host-level control. A root-backed native
browser PTY is similarly powerful.

State-changing web actions use CSRF tokens and explicit named
operations. The application includes administrator login and session checks;
this is a single-administrator system, not a multiuser role/permission model.

Keep DockerManger on a trusted LAN/VPN and do not expose the management
interface directly to the public Internet.

## Updating

Source-built container deployment (repository `compose.yaml`):

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

The current feature set includes first-run administrator authentication, Docker/Compose discovery,
controlled lifecycle operations, Compose creation/editing and
validation, `.env` handling, Composerize conversion, logs, runtime
inspection, and browser PTY support.

Native-host installation has been tested during development on both
`arm64` Raspberry Pi/Debian and `amd64` Debian/Proxmox LXC environments.

The goal is to keep DockerManger understandable and maintainable rather
than turning it into a large management framework.

## Release and support

This README is the canonical text for both GitHub and the Docker Hub repository overview. Docker Hub does not automatically update its overview when this GitHub file changes; copy the Markdown there after each documentation update. All source/documentation links use canonical GitHub URLs so they work on both sites.


- Source: https://github.com/bmartino1/DockerManger
- Published images: https://hub.docker.com/r/bmmbmm01/dockermanger
- Current published tags: `latest` (multi-platform), `amd64`, `arm64`
- Node.js terminal uses xterm.js, node-pty and WebSockets. Docker Run → Compose conversion uses Composerize; its current dependency tree includes deprecated `core-js@2`, which is tracked as a future dependency-maintenance task.

## License

See [LICENSE](https://github.com/bmartino1/DockerManger/blob/main/LICENSE).
