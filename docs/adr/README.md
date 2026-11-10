# Architecture Decision Records

An ADR records one decision that shapes Databasify beyond a single change: what was decided, why, and what it costs. Product requirements, bugs and plans live in GitHub issues.

## Rules

- One decision per file, named `NNNN-kebab-slug.md`, numbered in order.
- Status is one of `Proposed`, `Accepted`, `Superseded by NNNN` or `Deprecated`.
- An accepted decision is not rewritten. To change a decision, write a new ADR and mark the old one `Superseded by NNNN`.
- Facts in an ADR (names, numbers, paths, behaviour) are kept in sync with the code in the same change that alters them.
- Write an ADR only for a decision that outlives the change that introduced it. Details of a single change belong in its pull request or GitHub issue.
- Evidence cites repository paths only. Git history before 2026-11-04 was squashed, so older commit hashes no longer resolve.

## Template

```markdown
# NNNN. Title

- Status: Proposed
- Date: YYYY-MM-DD

## Context

What forces are at play and what problem needs a decision.

## Decision

What we do, stated plainly.

## Consequences

What becomes easier, what becomes harder, and which constraints follow.

## Alternatives considered

Options that were tried or rejected, and why.

## Evidence

- `path/to/file`
```

## Index

| #                                                           | Decision                                                           | Status             |
| ----------------------------------------------------------- | ------------------------------------------------------------------ | ------------------ |
| [0001](0001-ssh-control-plane.md)                           | Laravel over SSH is the only control plane                         | Accepted           |
| [0002](0002-container-per-node.md)                          | One engine container per node                                      | Accepted           |
| [0003](0003-go-wrapper-pid1.md)                             | Go wrapper is PID 1 and reads a process manifest                   | Accepted           |
| [0004](0004-image-digest-pinning.md)                        | Engine images pinned by digest per database                        | Accepted           |
| [0005](0005-engine-uid-64999.md)                            | Engine user is uid/gid 64999, data under `/srv/<engine>`           | Accepted           |
| [0007](0007-operations-and-server-logs.md)                  | One operation per database, one server log per SSH call            | Accepted           |
| [0009](0009-ha-per-engine.md)                               | Each engine keeps its own HA mechanism                             | Accepted           |
| [0010](0010-private-networking.md)                          | Provider VPC, custom network or WireGuard mesh                     | Accepted           |
| [0011](0011-nftables-firewall.md)                           | One nftables table per database                                    | Accepted           |
| [0012](0012-tls-private-ca.md)                              | Private CA per database; public wildcard for key-value             | Accepted           |
| [0013](0013-secrets-and-ssh-keys.md)                        | Encrypted secrets, one app SSH key, pinned host keys               | Accepted           |
| [0014](0014-dns-hostnames.md)                               | DNS names follow the primary                                       | Accepted           |
| [0015](0015-data-protection-and-migration.md)               | Engine-native PITR and migrations over plain S3                    | Accepted           |
| [0016](0016-app-architecture.md)                            | Application architecture and distribution                          | Accepted           |
| [0017](0017-browser-terminal.md)                            | Browser terminal as a queued SSH shell                             | Accepted           |
| [0018](0018-host-os-and-provisioning.md)                    | Supported host operating systems and provisioning                  | Accepted           |
| [0019](0019-providers-order-vm-only.md)                     | Cloud providers only order the server                              | Accepted           |
| [0020](0020-monitoring-by-ssh-polling.md)                   | Monitoring is the app polling over SSH                             | Accepted           |
| [0021](0021-app-state-sqlite-and-redis.md)                  | App state in SQLite, queues in Redis                               | Accepted           |
| [0023](0023-live-ui-by-polling.md)                          | Live UI by polling, Reverb only for streams                        | Accepted           |
| [0024](0024-testing-with-boundary-fakes.md)                 | Tests fake the boundaries; real servers tested by hand             | Accepted           |
| [0028](0028-wireguard-address-blocks.md)                    | WireGuard address blocks of a chosen size, CGNAT only              | Accepted           |
| [0030](0030-provider-http-client-with-dns-write-retries.md) | One provider HTTP client; DNS record writes retry too              | Accepted           |
| [0031](0031-projects-as-a-global-scope.md)                  | Projects as a global scope                                         | Accepted           |
| [0032](0032-delete-rules-in-model-observers.md)             | Delete guards in actions, cascades in model observers              | Accepted           |
| [0033](0033-app-settings-in-env-file.md)                    | App settings live in `/opt/databasify/.env`                        | Accepted           |
| [0034](0034-cache-and-sessions-in-redis.md)                 | Cache, sessions and locks in Redis                                 | Accepted           |
