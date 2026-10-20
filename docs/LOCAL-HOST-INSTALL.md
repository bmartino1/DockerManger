# DockerManger Native / Local Host Installation

DockerManger can be installed directly on a Debian-family host instead
of running the DockerManger web application inside its own container.

This installation path is intended for trusted homelab systems such as:

-   Debian 13 / Debian-family hosts
-   Proxmox VE hosts and suitable Debian LXC guests
-   64-bit Raspberry Pi OS / Debian on Raspberry Pi
-   `amd64` / `x86_64`
-   `arm64` / `aarch64`

The native installer is additive to the Docker deployment. It does not
replace `Dockerfile`, `compose.yaml`, or the normal container
deployment.

> DockerManger currently has no application authentication/authorization
> layer. Docker control and the optional browser PTY provide powerful
> host access. Keep the UI on a trusted LAN/VPN and do not expose it
> directly to the public Internet.

## Quick install

Start with a Debian-family host. `git` is the only package normally
needed before cloning; the installer handles the DockerManger host
dependencies.

``` bash
apt update
apt install -y git

git clone https://github.com/bmartino1/DockerManger.git
cd DockerManger

chmod +x host_installer.sh
bash -n host_installer.sh
./host_installer.sh
```

Run the installer as `root`. If cloning as a normal administrator
account, invoke it with `sudo ./host_installer.sh`.

## Installer prompts

The installer asks for:

-   HTTP port --- default `80`
-   HTTPS port --- default `443`
-   Docker Compose stacks directory --- default `/opt/stacks`
-   timezone
-   whether Docker Engine + Compose v2 should be installed/repaired
-   whether OpenSSH server should be installed/enabled
-   whether the DockerManger browser PTY console should be enabled
-   whether `www-data` should receive read/write ACL access to the stack
    directory

The answers are saved in:

``` text
/etc/dockermanger/installer.env
```

On later interactive runs, the saved values become the prompt defaults.
Press Enter to keep an existing value.

For an unattended repair/update using the saved settings:

``` bash
DOCKERMANGER_RECONFIGURE=0 ./host_installer.sh
```

## What the installer installs

The native installation uses the host's normal services rather than
DockerManger's container init system.

Important paths are:

``` text
Repository checkout                  source files only
/var/www/dockermanger                installed PHP/web application
/opt/dockermanger-terminal           installed Node/node-pty helper
/etc/dockermanger/installer.env      saved installer answers
/etc/dockermanger/dockermanger.env   native runtime environment
/data                                persistent runtime data and TLS
/opt/stacks                          default native Compose stack directory
/etc/nginx/sites-available/
  dockermanger.conf                  generated Nginx configuration
/etc/systemd/system/
  dockermanger-terminal.service      browser terminal service
```

The installer detects the installed PHP-FPM version rather than assuming
a particular PHP release.

It also installs the useful host-side utilities expected on a
Docker/homelab management system, including `mc`, `ping`, `nc`,
`nslookup`, `dig`, `traceroute`, `curl`, `wget`, `jq`, `rsync`, `lsof`,
`ss`, `ip`, `socat`, `tree`, `htop`, and Python 3.

## Persistent data and reruns

The installer is designed to be rerun after a `git pull` or to repair an
existing native installation.

The following are preserved:

``` text
/data
configured STACKS_DIR
/etc/dockermanger/installer.env
existing complete TLS certificate/key pair
```

The application copy beneath `/var/www/dockermanger` is refreshed from
the repository, while runtime and stack data remain outside that
application copy.

A normal update is:

``` bash
cd ~/DockerManger
git pull
./host_installer.sh
```

Or, using the previously saved answers without prompts:

``` bash
cd ~/DockerManger
git pull
DOCKERMANGER_RECONFIGURE=0 ./host_installer.sh
```

## Stack directory permissions

DockerManger's PHP-FPM workers run as `www-data`, so that account must
be able to traverse the complete path to `STACKS_DIR` and read/write the
stack directory.

When stack permission management is enabled, the installer applies ACLs
to the selected stack directory and the required execute/traverse ACL to
parent directories. This matters particularly for paths beneath `/root`,
for example:

``` text
/root/DockerManger/data/compose_stacks
```

The installer verifies the final permissions as `www-data` before
declaring the installation successful.

Using `/opt/stacks` or another dedicated stack directory is simpler for
a normal deployment, but custom absolute paths are supported.

## HTTPS

HTTPS is part of the native installation.

The default native ports are:

``` text
HTTP  -> 80
HTTPS -> 443
```

HTTP retains the health endpoint and redirects normal browser traffic to
HTTPS.

Native TLS files are stored at:

``` text
/data/certs/dockermanger.crt
/data/certs/dockermanger.key
```

If neither file exists, the installer creates a self-signed bootstrap
certificate. A browser warning is therefore expected initially.

If a complete certificate/key pair already exists, it is preserved. An
incomplete pair causes installation to fail instead of silently
replacing certificate material.

## Browser console behavior

The native browser console deserves special attention.

In the Docker deployment, the `local` terminal target is a shell inside
the DockerManger container, while the optional Host Console uses
outbound SSH to reach the Docker host.

In a native installation, DockerManger itself is already running on the
host. Therefore a native `local` PTY is a shell on the actual machine.

When the native browser PTY is enabled:

``` text
Browser
   |
   +--> Nginx /terminal-ws
             |
             +--> Node/node-pty on 127.0.0.1:3000
                       |
                       +--> native DockerManger/host shell
                       +--> docker exec PTY for containers
```

The native installer removes the container-oriented Host Console
selector from the installed copy of `console.php`; it does not modify
the repository copy.

When the PTY console option is disabled, `dockermanger-terminal.service`
is disabled/inactive and port `3000` should not be listening. The UI
displays the normal "Console is disabled" notice if a console URL is
opened.

To change the choice later, simply rerun:

``` bash
./host_installer.sh
```

The saved value is shown as the default and can be changed at the
prompt.

## Native services

Typical native services are:

``` text
nginx
phpX.Y-fpm
docker
dockermanger-terminal
```

The terminal service is expected to be enabled only when the browser PTY
option is enabled.

Check them with:

``` bash
systemctl --no-pager --full status nginx docker dockermanger-terminal || true
systemctl --no-pager --full status 'php*-fpm' || true
```

Check listening ports with:

``` bash
ss -lntup | grep -E ':(80|443|3000)\b' || true
```

With the console enabled, `127.0.0.1:3000` is expected. It intentionally
binds to loopback; Nginx provides the same-origin WebSocket endpoint.

With the console disabled, port `3000` should not be listening.

## Health checks

Default-port native installation:

``` bash
curl -fsS http://127.0.0.1/health
curl -kfsS https://127.0.0.1/health
```

Both should return:

``` text
ok
```

Docker checks:

``` bash
docker version
docker compose version
docker ps
```

## Verified development environments

The native installer has been exercised successfully in the project's
development testing on:

``` text
64-bit Raspberry Pi / Debian 13   arm64/aarch64
Debian 13 Proxmox LXC             amd64/x86_64
```

The Raspberry Pi test included the enabled native browser PTY. The
Debian LXC test included the disabled-console path and verified that the
terminal service remained inactive and port `3000` was not listening.

These are development validation environments, not a promise that every
Debian derivative or LXC configuration will behave identically.

## Proxmox / LXC note

Docker inside an LXC depends on the LXC/Proxmox configuration allowing
the kernel/container features Docker requires. `host_installer.sh` can
install and configure Docker packages, but it cannot override
restrictions imposed by the outer LXC configuration.

If Docker itself cannot start in an LXC, diagnose the LXC configuration
before treating the DockerManger web application as the source of the
failure.

## Troubleshooting

Useful checks:

``` bash
nginx -t
systemctl --no-pager --full status nginx
systemctl --no-pager --full status docker
systemctl --no-pager --full status dockermanger-terminal || true
journalctl -u nginx -n 100 --no-pager
journalctl -u docker -n 100 --no-pager
journalctl -u dockermanger-terminal -n 100 --no-pager
ss -lntup
```

Verify stack access:

``` bash
namei -l /path/to/your/stacks
getfacl -p /path/to/your/stacks

sudo -u www-data test -x /path/to/your/stacks && echo "TRAVERSE OK"
sudo -u www-data test -r /path/to/your/stacks && echo "READ OK"
sudo -u www-data test -w /path/to/your/stacks && echo "WRITE OK"
```

If an installer run fails, fix the installer or underlying host problem
and rerun it. The native installation path is intentionally designed as
an install/repair workflow.

## Security

Docker Engine access is effectively administrative access to the Docker
host. A root-backed native PTY is likewise administrative host access.

Use DockerManger only in an environment where that trust is appropriate.
Until application authentication/authorization is implemented, keep
DockerManger behind a trusted LAN/VPN and appropriate network controls.
