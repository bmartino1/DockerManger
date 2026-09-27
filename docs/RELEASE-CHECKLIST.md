# DockerManger Release Checklist

This checklist is for the transition from the verified local-build development image to the first published multi-architecture Docker Hub image.

## Source freeze

- Confirm the Git working tree contains only the intended final application/documentation changes.
- Run `docker compose config` successfully from a fresh clone.
- Keep repository defaults deployment-neutral: `/opt/stacks` internally, `./data/compose_stacks` by default on the host, and Host Console disabled by default.
- Do not commit runtime TLS private keys, SSH private keys, `known_hosts`, or user-created stacks. Confirm `data/ssh/config` and `data/ssh/known_hosts` are not directories; the repository should keep only `data/ssh/.gitkeep`.
- Confirm `.gitignore` and `.dockerignore` exclude persistent runtime material from Git and the Docker build context.

## amd64 regression

From a clean amd64 clone/build, verify:

```bash
docker compose build --no-cache
docker compose up -d
docker compose ps
curl -ks https://127.0.0.1:5443/health
docker compose logs --tail=100 dockermanger
```

Then verify in the UI: dashboard/container inspection, Compose creation, `.env` save/edit, Composerize conversion (conversion only; pasted Docker commands are never executed), stack lifecycle, logs, DockerManger console, container console, and Down & Delete. For a deployment with Host Console enabled, verify interactive SSH to the configured host.

## arm64 / Raspberry Pi regression

Repeat the clean build/runtime checks on a 64-bit Raspberry Pi 4/5. Confirm the image reports `arm64`, the container becomes healthy, `/health` returns `ok`, Docker socket access succeeds, stack storage is writable, and the UI loads over HTTPS.

A memory soft-limit/cgroup warning can occur on some Pi hosts; treat it as non-fatal only when the container otherwise starts and becomes healthy.

## Multi-architecture image publication

Publish only `linux/amd64` and `linux/arm64` for the initial release. `arm/v7` is not a current primary target.

Before adding Docker Hub pull commands to README/DEPLOYMENT, verify the actual repository name and tags and inspect the published manifest to confirm both target platforms are present. Then pull and run the published image independently on amd64 and arm64 rather than relying only on local source builds.

## Security/release notes

DockerManger mounts `/var/run/docker.sock`, which is effectively administrative access to the Docker host. State-changing HTTP actions use CSRF protection and explicit named operations, but user authentication/authorization is not yet implemented. The initial release should therefore be documented for trusted LAN/VPN administration only, not direct public-Internet exposure.

Host Console is outbound SSH-client functionality only. DockerManger does not run an SSH server; the target host owns its SSH-server configuration and security.

## Documentation after publication

Once Docker Hub publication is verified, update `README.md` and `docs/DEPLOYMENT.md` with the real image name, supported tags, `docker pull`/Compose image example, supported architectures, and upgrade procedure. Do not document placeholder registry names.
