# DockerManger Host and Container Console

## Current status

The initial browser terminal is implemented with xterm.js plus a small Node/node-pty WebSocket helper. The helper listens only on container loopback; Nginx proxies the same-origin `/terminal-ws` endpoint.

The service accepts named targets only: the DockerManger shell, a validated Docker container name, or optional host SSH. It does not provide an arbitrary command HTTP endpoint.

PHP remains the application/control plane. Authentication and per-session terminal authorization are still future hardening work, so console-enabled deployments should remain on a trusted LAN/VPN.

---

## Console targets

DockerManger is intended to support three related terminal targets.

### 1. DockerManger local console

```text
Browser
   |
   v
xterm.js
   |
   v
PTY/WebSocket helper
   |
   v
shell inside the DockerManger container
```

This is useful for DockerManger diagnostics and administration from inside its own container.

---

### 2. Managed-container console

```text
Browser
   |
   v
xterm.js
   |
   v
PTY/WebSocket helper
   |
   v
docker exec -it <container> <shell>
   |
   v
managed container
```

A container console should use Docker's exec mechanism rather than SSH.

The terminal helper should not assume every managed image contains Bash.

A practical shell-selection order is expected to be similar to:

```text
/bin/bash
/bin/sh
```

with graceful failure when the target container has no suitable interactive shell.

The current helper probes the running container for Bash or `sh`, then launches the selected shell through `docker exec -it`.

---

### 3. Docker host console

DockerManger is an SSH **client**, not an SSH server.

The host-console path is outbound:

```text
Browser
   |
   v
xterm.js
   |
   v
PTY/WebSocket helper
   |
   v
ssh <user>@host.docker.internal -p <port>
   |
   v
Docker / Linux / Proxmox host
```

The target host must provide its own SSH server.

DockerManger does not install, configure, expose, or secure an SSH daemon on the host.

---

## Default host SSH configuration

Current defaults in `dockerenvironment.env` are:

```env
DOCKERMANGER_HOST_SHELL_ENABLED=false
DOCKERMANGER_HOST_SSH_HOST=host.docker.internal
DOCKERMANGER_HOST_SSH_PORT=22
DOCKERMANGER_HOST_SSH_USER=root
DOCKERMANGER_HOST_SSH_KEY=
```

Host-console access is disabled by default.

`compose.yaml` provides:

```yaml
extra_hosts:
  - "host.docker.internal:host-gateway"
```

so the container has a stable name for reaching the Docker host where Docker supports the host-gateway mapping.

---

## SSH client persistence

Persistent SSH client files live beneath:

```text
/data/ssh
```

At startup DockerManger attempts to expose that directory to OpenSSH as:

```text
/root/.ssh
```

Expected files may include:

```text
/data/ssh/
├── config
├── known_hosts
├── id_ed25519
├── id_ed25519.pub
├── id_rsa
├── id_rsa.pub
└── optional dedicated keys
```

DockerManger does not automatically generate host-console SSH keys.

That remains an administrator/deployment choice.

---

## Key authentication

OpenSSH automatically checks standard key paths such as:

```text
/root/.ssh/id_ed25519
/root/.ssh/id_rsa
```

Because `/root/.ssh` is linked to persistent `/data/ssh`, standard key names normally do not require an explicit environment variable.

A dedicated key can be selected with:

```env
DOCKERMANGER_HOST_SSH_KEY=/root/.ssh/dockermanger_host
```

SSH private keys should use restrictive permissions.

For example:

```bash
chmod 700 data/ssh
chmod 600 data/ssh/id_ed25519
```

Do not commit private keys to the repository.

---

## Password authentication

DockerManger does not store an SSH password in an environment variable and does not install `sshpass`. If the target host allows password authentication, the normal OpenSSH password prompt is presented through the interactive PTY. Key authentication is preferred for unattended and repeatable administration.

---

## Testing host SSH manually

Host reachability can also be tested manually from inside DockerManger when diagnosing the browser console.

Open a shell:

```bash
docker compose exec dockermanger bash
```

Check host resolution:

```bash
getent hosts host.docker.internal
```

Check the configured SSH port:

```bash
nc -z -w 3 host.docker.internal 22
```

Test OpenSSH interactively:

```bash
ssh root@host.docker.internal
```

If the host uses another SSH port:

```bash
ssh -p 2222 root@host.docker.internal
```

A failed SSH test should be debugged as host SSH/network/authentication configuration rather than by adding an SSH server to DockerManger.

---

## Testing managed-container shells manually

The same Docker CLI behavior can be tested directly from the DockerManger container when diagnosing a container-console failure.

Enter DockerManger:

```bash
docker compose exec dockermanger bash
```

List containers:

```bash
docker ps -a
```

Test a target that contains Bash:

```bash
docker exec -it CONTAINER_NAME /bin/bash
```

If Bash is unavailable:

```bash
docker exec -it CONTAINER_NAME /bin/sh
```

These manual tests are useful for validating Docker socket access and verifying the same SSH behavior used by the PTY service.

---

## Security boundary

A browser terminal is more sensitive than the current read-only dashboard.

Before terminal sessions are exposed through the UI, DockerManger needs an authorization/session boundary appropriate to the target:

- local DockerManger shell;
- managed-container shell;
- Docker host SSH shell.

The PTY/WebSocket service should not become a generic unauthenticated command socket.

The PHP application should authorize session creation, while the terminal helper should focus on interactive I/O.

---

## Intended use

The host console is intended for trusted administrator tasks such as:

- host file edits;
- project scripts;
- network diagnostics;
- storage diagnostics;
- Docker/Compose troubleshooting;
- Proxmox/Linux host administration where the administrator has intentionally enabled SSH access.

It is not required for normal DockerManger stack/container management through the Docker socket.
