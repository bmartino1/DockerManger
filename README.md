# DockerManger

[![Docker Hub](https://img.shields.io/badge/Docker%20Hub-bmmbmm01%2Fdockermanger-2496ED?logo=docker&logoColor=white)](https://hub.docker.com/r/bmmbmm01/dockermanger)
[![GitHub](https://img.shields.io/badge/Source-bmartino1%2FDockerManger-181717?logo=github&logoColor=white)](https://github.com/bmartino1/DockerManger)

**DockerManger** is a lightweight web interface for managing Docker containers, Compose stacks, images, volumes, logs, and host resources in homelabs. It supports `linux/amd64` and `linux/arm64` (including 64-bit Raspberry Pi systems), with either a Docker container deployment or a native Debian-family host installation.

**Security:** Deploy only on a trusted LAN/VPN. Docker socket access effectively grants administrative control over the host Docker Engine; an enabled native browser console can have host-level access. Do not expose this management UI directly to the public Internet.

## Screenshots

<img width="1708" height="998" alt="Screenshot 2026-11-01 150627" src="https://github.com/user-attachments/assets/1958d81e-1c6d-4db3-a154-6a52ce06b8fa" />

<img width="1712" height="959" alt="Screenshot 2026-11-01 150646" src="https://github.com/user-attachments/assets/d7ea34da-8aca-4b3c-a7d7-7feb93c8760f" />

<img width="1706" height="877" alt="image" src="https://github.com/user-attachments/assets/e4819e10-a517-4eb9-b35d-ac9aee01c5fe" />

<img width="1703" height="1051" alt="image" src="https://github.com/user-attachments/assets/f487ea66-8471-420a-9fc7-496b358e3edc" />

<img width="1709" height="636" alt="image" src="https://github.com/user-attachments/assets/4c4ea066-9d1d-4cb1-b430-a9345768a436" />
<img width="1704" height="648" alt="image" src="https://github.com/user-attachments/assets/310f68a1-d3db-4f35-a11a-fdd9bd2556f9" />


<img width="1704" height="440" alt="image" src="https://github.com/user-attachments/assets/2f63d346-0940-47bd-9554-01439588be91" />

<img width="1702" height="1156" alt="image" src="https://github.com/user-attachments/assets/d58dde63-ac57-4260-a7ec-c67052441014" />

<img width="1697" height="1091" alt="image" src="https://github.com/user-attachments/assets/a86b4cef-b9fa-494c-a1cd-df9d704221f6" />

<img width="1693" height="392" alt="image" src="https://github.com/user-attachments/assets/d5fb9788-ec21-4090-9343-69e6111d7fd0" />

## Docker Hub images

| Tag | Platforms | Usage |
| --- | --- | --- |
| `bmmbmm01/dockermanger:latest` | `linux/amd64`, `linux/arm64` | **Recommended**; Docker selects the host architecture |
| `bmmbmm01/dockermanger:amd64` | `linux/amd64` | Explicit x86-64 tag |
| `bmmbmm01/dockermanger:arm64` | `linux/arm64` | Explicit ARM64 tag |

```bash
docker pull bmmbmm01/dockermanger:latest
```

All three tags were published and verified as OCI indexes on November 7, 2026. **Tags can change.** Version-specific SHA256 digests, image manifests, attestation details, Buildx/QEMU setup, validation, and publication commands live in the [multi-architecture build and release guide](https://github.com/bmartino1/DockerManger/blob/main/docs/MULTIARCH-BUILD-AND-RELEASE.md), not in this overview.

[View published Docker Hub tags](https://hub.docker.com/r/bmmbmm01/dockermanger/tags).

## Get started

### Option A: Docker Compose from source

The repository's `compose.yaml` **builds** the application as `dockermanger:dev` from the local Dockerfile. It is **not** a pull-only Docker Hub Compose example.

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

mkdir -p data/certs data/compose_stacks data/ssh
chmod 700 data/ssh

docker compose config
docker compose up -d --build
```

Defaults for the source-built Compose deployment:

- HTTPS: `https://HOST-IP:5443`
- HTTP: `http://HOST-IP:5001` (health/ACME and redirect to HTTPS)
- Runtime data: `./data:/data`
- Compose stacks: `./data/compose_stacks:/opt/stacks` unless `HOST_STACKS_DIR` is configured

See the [deployment guide](https://github.com/bmartino1/DockerManger/blob/main/docs/DEPLOYMENT.md) for Docker Hub image deployments, mounts, ports, Compose interpolation, and host SSH options.

### Option B: Native Debian-family host install

DockerManger can be installed without running the web UI inside a Docker container. The installer supports suitable Debian/Proxmox environments and 64-bit Raspberry Pi/Debian hosts.

```bash
apt update && apt install -y git
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger
bash -n host_installer.sh
./host_installer.sh
```

The installer asks for HTTPS/HTTP ports, stack location, password policy, Docker and SSH setup, and optional browser console access. It preserves `/data` and the configured stacks directory during repair. Saved settings live at `/etc/dockermanger/installer.env`.

```bash
# Unattended update/repair using saved settings, after a successful git pull:
DOCKERMANGER_RECONFIGURE=0 ./host_installer.sh
```

Read the [native host installation guide](https://github.com/bmartino1/DockerManger/blob/main/docs/LOCAL-HOST-INSTALL.md) before enabling host-level browser console access.

## First login

Open the configured HTTPS address. A new installation redirects to `/setup.php` to create the administrator account; **there is no default password**. The default minimum password length is eight characters and can be configured (8–128). Existing accounts are preserved under `/data/auth/admin.json`; do not lose or expose persistent `/data`.

DockerManger uses a self-signed bootstrap TLS certificate if no certificate pair has been provided, so a browser trust warning may appear until you install a trusted certificate.

## Features

- **Dashboard:** Engine versions, containers, mounts, storage, and operational information.
- **Apps:** One entry per container, grouped by its Compose association, with search/filtering and an optional **Open WebUI** button.
- **Stacks:** Compose discovery, creation, validation/editing, per-stack `.env`, lifecycle operations, image pulls/updates, and container logs.
- **WebUI links:** One optional URL per app from the `com.dockermanger.webui` Compose service label or a manual setting. Labeled URLs are edited through Compose; unlabeled ones use **Edit / Save WebUI / Remove WebUI**.
- **Containers:** Runtime details and Console / Logs / Open WebUI (when configured).
- **Images and volumes:** Inventory and guarded cleanup, including separately confirmed destructive actions.
- **Resources:** On-demand CPU and memory sampling.
- **Console:** xterm.js/node-pty terminal, local/container shell, and optional outbound SSH. Browser console access can be disabled.
- **Docker Run → Compose:** Converts a command to Compose text without executing the supplied Docker Run command.

The web API uses named operations and authentication; it is not an unrestricted shell-command endpoint.

## WebUI label example

Give each Compose service its own optional browser URL:

```yaml
services:
  sonarr:
    image: lscr.io/linuxserver/sonarr:latest
    labels:
      com.dockermanger.webui: "http://192.168.1.50:8989"
  radarr:
    image: lscr.io/linuxserver/radarr:latest
    labels:
      com.dockermanger.webui: "http://192.168.1.50:7878"
```

These URLs are only shortcuts; the app, published ports, routing, and browser access must be configured separately. If a service has no WebUI, leave the label unset. A valid label in saved Compose takes precedence over the app's manually saved URL. Read the [complete WebUI configuration and editing guide](https://github.com/bmartino1/DockerManger/blob/main/docs/WEBUI-CONFIGURATION.md) for removal, source-of-truth behavior, and tests.

## Documentation

The detailed implementation, release, and troubleshooting information is maintained in GitHub Docs so that **this README can be copied unchanged to Docker Hub**:

| Guide | Covers |
| --- | --- |
| [Docker/Compose deployment](https://github.com/bmartino1/DockerManger/blob/main/docs/DEPLOYMENT.md) | Ports, volumes, mounts, environment variables, upgrades |
| [Native host installation](https://github.com/bmartino1/DockerManger/blob/main/docs/LOCAL-HOST-INSTALL.md) | Debian/Proxmox/Pi installation, repair, permissions |
| [Per-app WebUI configuration](https://github.com/bmartino1/DockerManger/blob/main/docs/WEBUI-CONFIGURATION.md) | Compose labels, manual URLs, Edit/Remove and testing |
| [Multi-arch build & release](https://github.com/bmartino1/DockerManger/blob/main/docs/MULTIARCH-BUILD-AND-RELEASE.md) | AMD64/ARM64, QEMU, Buildx, Docker Hub tags, OCI SHA digests |
| [Release checklist](https://github.com/bmartino1/DockerManger/blob/main/docs/RELEASE-CHECKLIST.md) | Regression checks and security review |
| [Architecture](https://github.com/bmartino1/DockerManger/blob/main/docs/ARCHITECTURE.md) | Application design and services |
| [Host console](https://github.com/bmartino1/DockerManger/blob/main/docs/HOST-CONSOLE.md) | SSH and terminal model |

## Updating

For the repository **source-built** Compose deployment:

```bash
git pull --ff-only
docker compose up -d --build
docker compose ps
```

For an existing **Docker Hub image** deployment using `image: bmmbmm01/dockermanger:latest`:

```bash
docker compose pull dockermanger
docker compose up -d --no-deps --force-recreate dockermanger
```

Keep `/data`, Compose stacks, certificates, and configuration persistent. For native installation, run `git pull --ff-only` and rerun `./host_installer.sh`. See the linked deployment guides for backups and safe upgrades.

## Support and license

- [GitHub source and issues](https://github.com/bmartino1/DockerManger)
- [Docker Hub repository](https://hub.docker.com/r/bmmbmm01/dockermanger)
- [LICENSE](https://github.com/bmartino1/DockerManger/blob/main/LICENSE)

This GitHub README is also the canonical Docker Hub repository overview. Docker Hub does **not** automatically synchronize its overview text: copy the Markdown from GitHub after README changes.
