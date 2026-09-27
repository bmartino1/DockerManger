# DockerManger architecture

## Control plane

Nginx serves the application and forwards PHP requests to PHP-FPM. PHP owns the
DockerManger application logic, authentication, stack discovery, Compose
operations, and API.

DockerManger does not run `dockerd`. The Docker CLI and Compose v2 plugin connect
to the host daemon through `/var/run/docker.sock`.

## Compose projects

Compose projects remain ordinary directories beneath `STACKS_DIR`. DockerManger
must not require proprietary metadata for a Compose project to remain usable.

## Terminal

The planned browser terminal is deliberately isolated from the main PHP control
plane. A small Node.js PTY/WebSocket service may be introduced for terminal I/O.
PHP remains responsible for authorization/session creation.

## Persistence

`/data` contains DockerManger application data. SQLite is the default planned
database. Compose project files remain under `STACKS_DIR`, not in the database.

## Architecture targets

The primary build targets are `linux/amd64` and `linux/arm64`.
