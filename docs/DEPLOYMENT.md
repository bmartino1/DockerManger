# Deployment paths

DockerManger's default stack directory inside the container is:

```text
/opt/stacks
```

The Docker image must not assume a particular host filesystem, storage pool, NAS mount, or homelab layout.

The example Compose file therefore separates:

- `HOST_STACKS_DIR` — host-side directory
- `STACKS_DIR` — container-side directory, default `/opt/stacks`

Generic example:

```yaml
volumes:
  - ${HOST_STACKS_DIR:-/opt/stacks}:${STACKS_DIR:-/opt/stacks}
```

Example custom deployment:

```env
HOST_STACKS_DIR=/some/local/docker/path
STACKS_DIR=/opt/stacks
```

Site-specific paths should stay in the user's deployment configuration and should not become Docker image defaults.
