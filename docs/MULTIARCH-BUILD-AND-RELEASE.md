# DockerManger multi-architecture image builds and publication

This is the build and release reference for `bmmbmm01/dockermanger`. The short public-facing overview is in the [README](https://github.com/bmartino1/DockerManger/blob/main/README.md); use the [release checklist](https://github.com/bmartino1/DockerManger/blob/main/docs/RELEASE-CHECKLIST.md) for runtime and UI regression checks.

## Published image tags

| Docker Hub tag | Intended platform | Notes |
| --- | --- | --- |
| `bmmbmm01/dockermanger:latest` | `linux/amd64` and `linux/arm64` | Combined OCI index; Docker selects architecture automatically |
| `bmmbmm01/dockermanger:amd64` | `linux/amd64` | Architecture-specific index containing the AMD64 image and its attestation |
| `bmmbmm01/dockermanger:arm64` | `linux/arm64` | Architecture-specific index containing the ARM64 image and its attestation |

These **tags are mutable**. The digests below identify one verified publication; they are not permanent values for future releases. Never overwrite tags or `latest` without checking both architectures and the current source revision.

## Verified November 7, 2026 publication

Built from GitHub `main` commit `9828376` on a Debian 13 amd64 unprivileged Proxmox LXC (CT 101), with Docker Engine 29.9.3, Buildx 0.38.1, Docker Compose 5.7.0, BuildKit 0.34.2 and PVE kernel `7.0.14-28-pve`.

The host's `qemu-aarch64` `binfmt_misc` entry was enabled with `flags: POF`; inside the LXC, `docker run --rm --platform linux/arm64 alpine:3.22 uname -m` returned `aarch64`. Builds and local `docker image inspect` architecture checks succeeded on both targets; both Docker Hub pushes succeeded, and `imagetools inspect` confirmed the combined manifest. **These checks are not a full Raspberry Pi/ARM application integration test.**

| Artifact | Verified OCI index digest |
| --- | --- |
| `latest` | `sha256:99d86f4cf08838b05a64b2e7ca70ba3e3e134c723816bb02dc3cb2a6ffe46668` |
| `amd64` | `sha256:2ee35343e60a15d73ed0d06e2366392f241fcba01ffde2e4b9887760dc6fd966` |
| `arm64` | `sha256:f809726d60371fc33bed356428d7c3bcb5f2d58e76ad4d4e0e429ea409e48fb5` |

The underlying runnable image manifests are:

| Platform | Image manifest digest |
| --- | --- |
| `linux/amd64` | `sha256:82cd94ac782e4cd7a358863f97f9599b8783af68f6ada11ded874ad997384914` |
| `linux/arm64` | `sha256:397b86ceb443575188aad6dbd5525442d36d5e123c87fc716c814ff9ea2b6133` |

Docker Hub lists seven records for this publication: three tagged OCI indices, two platform image manifests, and two `unknown/unknown` attestation manifests. Attestations are **not** broken or additional executable architectures, and need not be individually tagged or deleted.

An image index's digest is **not** the same thing as a runnable image manifest's digest. For reproducible multi-platform deployments pin `bmmbmm01/dockermanger@sha256:99d86f4cf08838b05a64b2e7ca70ba3e3e134c723816bb02dc3cb2a6ffe46668` for **this publication only**. Later releases must record new digests.

## Proxmox LXC preparation

The tested builder ran on an amd64, Debian 13 **unprivileged** LXC with `features: nesting=1,keyctl=1`. It had 2 cores, 4 GiB memory, 20 GiB root storage, and Docker overlayfs. Approximately 13 GiB remained free after these two builds, but future builds/caches might require more disk.

The emulation handler belongs to the **PVE host kernel**, not an ARM kernel inside LXC:

```bash
# On PVE host
pct config 101
cat /proc/sys/fs/binfmt_misc/qemu-aarch64
# Should show enabled and flags including F, e.g. POF.
```

Do not switch to a privileged LXC or disable confinement just to build images. On a restored system, check existing packages and handlers **before** reinstalling or changing the host.

Inside the LXC:

```bash
docker version
docker buildx version
docker compose version
systemctl is-active docker
docker buildx ls
docker buildx inspect --bootstrap
docker run --rm --platform linux/arm64 alpine:3.22 uname -m
df -h /
```

The last runtime test should print `aarch64`. A platform merely appearing in `docker buildx ls` is not sufficient proof of working emulation. If Buildx's default `docker` driver works, reuse it; alternatively create a `docker-container` builder:

```bash
docker buildx create --name dockermanger-builder --driver docker-container --use --bootstrap
docker buildx inspect --bootstrap
```

## Build both platforms from one source revision

```bash
mkdir -p /root/git
cd /root/git

if [ -d DockerManger/.git ]; then
  cd DockerManger
  git fetch origin
  git switch main
  git pull --ff-only origin main
else
  git clone --branch main https://github.com/bmartino1/DockerManger.git
  cd DockerManger
fi

git rev-parse HEAD
set -o pipefail

docker buildx build --platform linux/amd64 \
  -t bmmbmm01/dockermanger:amd64 --load --progress=plain . \
  2>&1 | tee /root/dockermanger-amd64.log

docker buildx build --platform linux/arm64 \
  -t bmmbmm01/dockermanger:arm64 --load --progress=plain . \
  2>&1 | tee /root/dockermanger-arm64.log

docker image inspect bmmbmm01/dockermanger:amd64 --format '{{.Os}}/{{.Architecture}}'
docker image inspect bmmbmm01/dockermanger:arm64 --format '{{.Os}}/{{.Architecture}}'
docker system df
```

Verify the reported platforms are `linux/amd64` and `linux/arm64`. Do not publish if either build or inspection fails.

### Optional smoke tests before publishing

```bash
for arch in amd64 arm64; do
  docker run --rm --platform "linux/$arch" --entrypoint /bin/bash \
    "bmmbmm01/dockermanger:$arch" -c \
    'php -v | head -1; node -v; npm -v; docker --version; docker compose version; cd /opt/dockermanger-terminal && node -e "require(\"node-pty\"); require(\"ws\"); console.log(\"PTY modules OK\")"'
done
```

Also run application tests on a real Docker host following [RELEASE-CHECKLIST.md](https://github.com/bmartino1/DockerManger/blob/main/docs/RELEASE-CHECKLIST.md). A build alone cannot prove network, UI, Docker socket, or SSH behavior.

## Push the three tags

Only after both platform builds and tests pass, and the image version is approved for publication:

```bash
docker login -u bmmbmm01

docker push bmmbmm01/dockermanger:amd64
docker push bmmbmm01/dockermanger:arm64

# Combines the two already published platform-specific indexes, no rebuild:
docker buildx imagetools create \
  -t bmmbmm01/dockermanger:latest \
  bmmbmm01/dockermanger:amd64 \
  bmmbmm01/dockermanger:arm64

docker buildx imagetools inspect bmmbmm01/dockermanger:amd64
docker buildx imagetools inspect bmmbmm01/dockermanger:arm64
docker buildx imagetools inspect bmmbmm01/dockermanger:latest
```

Confirm `latest` contains both `linux/amd64` and `linux/arm64`. The published images are listed on the [Docker Hub tags page](https://hub.docker.com/r/bmmbmm01/dockermanger/tags).

To record new index digests at release time:

```bash
docker buildx imagetools inspect bmmbmm01/dockermanger:latest
```

The command reports the digest and each child manifest. Record the source commit, build environment, resulting digests, and verification date for each release. Do not copy older digests into a new release entry.

## Update an existing Docker deployment

For a deployment with `image: bmmbmm01/dockermanger:latest` (not the repository's source-build `compose.yaml`):

```bash
cd /path/to/deployment
docker compose config --quiet
docker compose pull dockermanger
docker compose up -d --no-deps --force-recreate dockermanger
docker compose ps
```

Preserve the existing `/data` and stack mounts and back up runtime configuration before major upgrades.

## Related guides

- [Deployment](https://github.com/bmartino1/DockerManger/blob/main/docs/DEPLOYMENT.md)
- [Native host installation](https://github.com/bmartino1/DockerManger/blob/main/docs/LOCAL-HOST-INSTALL.md)
- [WebUI service labels and manual links](https://github.com/bmartino1/DockerManger/blob/main/docs/WEBUI-CONFIGURATION.md)
- [Release checklist](https://github.com/bmartino1/DockerManger/blob/main/docs/RELEASE-CHECKLIST.md)
