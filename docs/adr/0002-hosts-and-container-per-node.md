# 0002. Supported hosts run Docker and one engine container per node

- Status: Accepted
- Date: 2026-11-15

## Context

Six engines must run on several Linux distributions. Installing each engine from distribution packages means a different package source, path layout and service manager for every engine and operating system pair, and every host script has to work on each supported system.

## Decision

- **Hosts.** Supported systems are Ubuntu 24.04 and 26.04, Debian 12 and 13, Rocky Linux 9 and AlmaLinux 9 (`OperatingSystem`), in two families: Debian and RHEL. Anything else is refused. Host scripts branch on the family into `ssh/servers/os/{debian,rhel}`. SELinux stays enforcing on RHEL hosts. Only IPv4 is supported.
- **The host runs only Docker**, pinned to the Docker and containerd versions in `config/databasify.php` and held against upgrades. Our Docker daemon settings are merged into any existing `daemon.json`, ours winning: `live-restore`, `iptables: false`, `ip6tables: false`, `bridge: none` and JSON logs capped at 5 files of 50 MB. Provisioning adds a 2 GB swap file only when the server has less than 4 GB of RAM and at least 6 GB of free disk.
- **One container per node**, `databasify-<engine>`, created by the engine SDK host binary on `host deploy` ([0003](0003-engine-images-and-frozen-runtime.md)) with host networking and IPC, SELinux labels disabled, `--restart unless-stopped`, a raised open-files limit, `/srv/<engine>` bind-mounted at the same path, and the labels `io.databasify.database` and `io.databasify.node`. Old images are pruned.
- A server belongs to at most one database (`AvailableServer`). A deploy refuses a non-empty data directory whose database marker names another database.

## Consequences

- Adding an operating system means a new enum case, family scripts for a new family, and an image entry in every server provider.
- Every host script must work with SELinux enforcing.
- `docker kill` counts as a manual stop, so the container stays down until the node is redeployed; real crashes restart.
- Docker blocks `io_uring`, so MariaDB falls back to `libaio`. The cost against native installs has not been measured.

## Alternatives considered

- **Native installs from distribution packages:** dropped, each engine and OS pair needed its own install path and upgrades followed distribution cadence.
- **Docker bridge networking:** rejected, HA stacks need real host addresses and ports for peers.
- **Any Linux distribution:** rejected, host scripts depend on each family's package manager, firewall tool and SELinux behaviour.

## Evidence

- `app/Enums/Servers/OperatingSystem.php`
- `app/Actions/Servers/ProvisionServer.php`
- `resources/views/ssh/servers/os/`
- `resources/views/ssh/servers/docker/daemon.blade.php`
- `config/databasify.php`
- `app/Engines/Runtime/Runtime.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Rules/AvailableServer.php`
