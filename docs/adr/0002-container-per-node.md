# 0002. One engine container per node

- Status: Accepted
- Date: 2026-11-04

## Context

Six engines must run on six operating systems (Debian and RHEL families). Installing each engine from distribution packages means a different package source, path layout and service manager for every engine and OS pair.

## Decision

Each database node runs exactly one container, `databasify-<engine>`, started by `ContainerSpec::toRunCommand()` with:

- `--network host` and `--ipc host`
- SELinux labels disabled
- `--restart unless-stopped`
- open-files limit 65536
- `/srv/<engine>` bind-mounted at the same path
- labels `io.databasify.database` and `io.databasify.node`

The host only runs Docker Engine, pinned to a fixed Docker and containerd version (`config/databasify.php`) and held with `apt-mark hold` or `dnf versionlock`. The Docker daemon settings are merged into any existing `daemon.json`, with ours winning: `live-restore`, `iptables: false`, `ip6tables: false`, `bridge: none` and JSON logs capped at 5 files of 50 MB.

## Consequences

- A server belongs to at most one database (`AvailableServer` rule).
- On a fresh deploy, when `data/` is not empty, deploy refuses a data directory whose `.database` key belongs to another database.
- The container is recreated when its image changes, and old images are pruned.
- The container stop timeout is 90 seconds for schedules, plus the sum of the process stop timeouts, plus 60 seconds.
- `docker kill` is treated by Docker as a manual stop, so the container stays down until the node is redeployed. Real crashes do restart.
- Docker blocks `io_uring`, so MariaDB falls back to `libaio`.
- The performance cost against native installs has not been measured yet.

## Alternatives considered

- **Native installs from distribution packages:** tried and dropped. Each engine and OS pair needed its own install path, and upgrades depended on distribution release cadence.
- **Docker bridge networking:** rejected. HA stacks need real host addresses and ports for peers.

## Evidence

- `app/DTOs/Databases/ContainerSpec.php`
- `resources/views/ssh/servers/docker/daemon.blade.php`
- `resources/views/ssh/databases/nodes/deploy.blade.php`
- `app/Rules/AvailableServer.php`
- `config/databasify.php`
