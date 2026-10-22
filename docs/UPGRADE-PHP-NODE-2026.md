# DockerManger dependency upgrade (testing candidate)

- Container base: `phusion/baseimage:noble-1.0.3` (verify published image availability before building).
- PHP: 8.5 from Ondrej Ubuntu PPA in the container; host installer uses Ondrej PPA on Ubuntu and Sury on Debian.
- Node.js: 26.x from NodeSource.
- Docker CLI / Compose: official Docker Ubuntu repository in container.
- Nginx / SSH / OS utilities: latest packages available in configured apt repositories at build time.

**This source has not been built or runtime tested on AMD64 or ARM64.** Do not publish as production `latest` before validating both platforms.

## Validation

```bash
docker compose build --no-cache
docker compose up -d
docker exec dockermanger php -v
docker exec dockermanger node -v
docker exec dockermanger php-fpm8.5 -t
docker exec dockermanger nginx -t
docker exec dockermanger dockermanger-diagnostics
```

Test first-run setup, logout/login, unauthenticated API and terminal WebSocket rejection, SSH host console, and HTTPS redirects.

Native host installer modifies system repositories and services; test only on a disposable Debian/Ubuntu host first.
