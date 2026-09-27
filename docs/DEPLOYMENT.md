# DockerManger Deployment

## Default paths

DockerManger uses two main persistent/external locations.

Application/runtime data:

```text
/data
```

Compose projects:

```text
/opt/stacks
```

`/opt/stacks` is the neutral default **inside the container**. The Docker image must not assume a particular host filesystem, ZFS pool, NAS mount, Unraid path, Proxmox layout, or personal homelab directory.

---

## Default Compose mappings

The repository Compose file maps:

```yaml
volumes:
  - /var/run/docker.sock:/var/run/docker.sock
  - ./data:/data
  - "${HOST_STACKS_DIR:-./data/compose_stacks}:${STACKS_DIR:-/opt/stacks}"
```

This separates:

- `HOST_STACKS_DIR` — optional deployment-specific host path; defaults to `./data/compose_stacks`.
- `STACKS_DIR` — path DockerManger sees inside the container.
- `/data` — DockerManger's own persistent application/runtime data.

The normal container-side stack path should remain:

```text
/opt/stacks
```

---

## Example stack paths

Generic host:

```bash
docker compose up -d --build
```

Host storing Compose projects under `/srv`:

```bash
HOST_STACKS_DIR=/srv/docker/stacks docker compose up -d --build
```

NAS or storage mount:

```bash
HOST_STACKS_DIR=/mnt/docker/stacks docker compose up -d --build
```

The exact host path belongs to the deployment and should not become a DockerManger image default.

---

## Compose interpolation versus container environment

Docker Compose interpolation and `env_file:` are related but different.

The repository uses:

```yaml
env_file:
  - ./dockerenvironment.env
```

That file supplies variables **inside the DockerManger container**.

Variables used directly in Compose expressions such as:

```yaml
"${WEB_HTTP_PORT:-5001}:80"
"${WEB_HTTPS_PORT:-5443}:443"
"${HOST_STACKS_DIR:-./data/compose_stacks}:${STACKS_DIR:-/opt/stacks}"
```

are resolved by Docker Compose from its interpolation environment, not merely because the same variable appears in `env_file:`.

For a one-off deployment, shell variables can be supplied directly:

```bash
HOST_STACKS_DIR=/srv/docker/stacks \
WEB_HTTP_PORT=5001 \
WEB_HTTPS_PORT=5443 \
docker compose up -d --build
```

A deployment may also use Docker Compose's normal `.env` mechanism if desired. `dockerenvironment.env` remains DockerManger's documented in-container runtime configuration file.

---

## HTTPS deployment

DockerManger requires HTTPS for the web application.

Default published ports:

```text
HTTP  5001 -> container 80
HTTPS 5443 -> container 443
```

Normal browser access is:

```text
https://HOST-IP:5443
```

Normal requests to:

```text
http://HOST-IP:5001
```

are redirected to the externally published HTTPS port.

HTTP remains available for:

- `/health`
- future ACME HTTP-01 challenges
- HTTP-to-HTTPS redirects

### First-start certificate

The container entrypoint expects:

```text
/data/certs/dockermanger.crt
/data/certs/dockermanger.key
```

If neither exists, it generates a self-signed bootstrap certificate before Nginx starts.

If both exist, they are preserved and reused.

If only one exists, startup fails because DockerManger will not silently replace an incomplete administrator-managed certificate pair.

A browser warning is normal while the bootstrap self-signed certificate is in use.

---

## Initial deployment

Clone the repository:

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger
```

Prepare persistent directories:

```bash
mkdir -p \
  data/certs \
  data/compose_stacks \
  data/ssh

chmod 700 data/ssh
```

Review the effective Compose configuration:

```bash
docker compose config
```

Build:

```bash
docker compose build --no-cache
```

Start:

```bash
docker compose up -d
```

Check status:

```bash
docker compose ps
```

Review startup:

```bash
docker compose logs --tail=100 dockermanger
```

Run DockerManger diagnostics:

```bash
docker compose exec dockermanger dockermanger-diagnostics
```

Then browse to:

```text
https://HOST-IP:5443
```

---

## Updating a Git deployment

Pull the repository changes:

```bash
cd DockerManger
git pull
```

Review Compose before replacing the container:

```bash
docker compose config
```

Rebuild and recreate:

```bash
docker compose build --no-cache
docker compose up -d
```

Verify:

```bash
docker compose ps
docker compose logs --tail=100 dockermanger
docker compose exec dockermanger dockermanger-diagnostics
```

The image/container can be replaced without intentionally deleting the persistent `./data` directory. If `HOST_STACKS_DIR` points outside `./data`, that external stack directory must also be retained.

Always review local Compose/environment changes before pulling or replacing files in an existing deployment.

---

## x86_64 / amd64 hosts

Check architecture:

```bash
uname -m
docker info --format '{{.Architecture}}'
```

A typical x86_64 host reports:

```text
x86_64
```

The intended Docker platform target is:

```text
linux/amd64
```

Build using the normal repository workflow:

```bash
docker compose build --no-cache
docker compose up -d
```

---

## Raspberry Pi / arm64 hosts

The intended Raspberry Pi target is a **64-bit Raspberry Pi 4/5 environment**.

Check architecture:

```bash
uname -m
docker info --format '{{.Architecture}}'
```

A 64-bit Pi normally reports:

```text
aarch64
```

The intended Docker platform target is:

```text
linux/arm64
```

Use the same Git/Compose workflow:

```bash
git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

docker compose config
docker compose build --no-cache
docker compose up -d

docker compose ps
docker compose logs --tail=100 dockermanger
```

`arm/v7` / 32-bit Raspberry Pi OS is not currently a primary DockerManger target.

> `linux/arm64` is a project target, but the complete image and every upstream image/package dependency still need successful real-world arm64 build testing before arm64 should be treated as release-verified.

---

## Persistent `/data`

The repository maps:

```text
./data -> /data
```

Expected contents include:

```text
data/
├── certs/
├── compose_stacks/
└── ssh/
```

The container entrypoint links `/root/.ssh` to `/data/ssh` when it is safe to do so.

Do not commit runtime TLS private keys, SSH private keys, or user-created Compose content.

---

## Docker socket

DockerManger requires:

```yaml
- /var/run/docker.sock:/var/run/docker.sock
```

The application can still start and report an unavailable Docker Engine if the socket is absent, but Docker management/discovery requires access to it.

The Docker socket grants highly privileged control over the host Docker Engine. Deploy DockerManger only where that trust is appropriate.

---

## Resource controls

The repository Compose file currently includes conservative resource controls intended to remain usable on smaller homelab systems:

```yaml
mem_reservation: 512m
mem_limit: 2g
memswap_limit: 3g
cpu_shares: 1024
pids_limit: 1024
```

These are deployment defaults, not hard architectural requirements. Administrators may adjust them for their host.

---

## Docker Hub / prebuilt images

A public Docker Hub and multi-architecture release workflow is still a work in progress.

Until that pipeline is finalized, the supported documentation path is:

```text
Git clone -> local docker compose build -> docker compose up
```

Future Docker Hub instructions should be added only when the image name, tags, supported architectures, and release process are finalized.
