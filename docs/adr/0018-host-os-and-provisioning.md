# 0018. Supported host operating systems and provisioning baseline

- Status: Accepted
- Date: 2026-11-04

## Context

Databasify runs on servers the user brings or orders. Every host script has to work on each supported operating system, so the list must be short and each entry tested.

## Decision

- Supported systems are Ubuntu 24.04 and 26.04, Debian 12 and 13, Rocky Linux 9 and AlmaLinux 9 (`OperatingSystem`), in two families (`OsFamily`): Debian and RHEL. The system is read from `/etc/os-release`; anything else is refused with `UnsupportedOperatingSystem`.
- Host scripts branch on the family into `ssh/servers/os/{debian,rhel}`.
- Provisioning runs a fixed sequence: package update, base tools, pinned Docker ([0002](0002-container-per-node.md)), then swap. A 2 GB swap file is added only when the server has less than 4 GB of RAM and at least 6 GB of free disk.
- SELinux stays enforcing on RHEL hosts; scripts relabel files instead of disabling it.
- At least 1 GB of RAM per node is suggested in the UI but not enforced: servers of any size can be primaries, standbys or read-only nodes.
- Only IPv4 is supported: public and private addresses and network ranges are validated as IPv4.

## Consequences

- Adding an operating system means a new enum case, family scripts if it is a new family, and an image entry in every server provider's registration ([0019](0019-providers-order-vm-only.md)).
- Every host script must work with SELinux enforcing.
- RHEL-family provisioning is noticeably slower than Debian-family.

## Alternatives considered

- **Supporting any Linux distribution:** rejected, because host scripts depend on the package manager, firewall tool and SELinux behaviour of each family.

## Evidence

- `app/Enums/Servers/OperatingSystem.php`
- `app/DTOs/Servers/ServerFacts.php`
- `resources/views/ssh/servers/provision/provision.blade.php`
- `resources/views/ssh/servers/os/swap.blade.php`
- `app/Models/Database.php`
