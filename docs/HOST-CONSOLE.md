# Host Console

DockerManger is an SSH client, not an SSH server.

A future browser terminal can launch either a local PTY shell or the OpenSSH client. Host-console support requires the target Docker/LXC/PVE host to provide its own SSH service.

Default path:

`Browser -> xterm.js -> PTY helper -> ssh root@host.docker.internal -p 2222 -> host`

The host console is intended for host file edits, scripts, network/storage diagnostics and project tools outside the Docker API.
