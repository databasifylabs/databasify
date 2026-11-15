# Architecture Decision Records

An ADR records one decision that shapes Databasify beyond a single change: what was decided, why, and what it costs. Product requirements, bugs and plans live in GitHub issues.

## Rules

- One decision per file, named `NNNN-kebab-slug.md`, numbered in order.
- Status is one of `Proposed`, `Accepted`, `Superseded by NNNN` or `Deprecated`.
- An accepted decision is not rewritten. To change a decision, write a new ADR and mark the old one `Superseded by NNNN`.
- Facts in an ADR (names, numbers, paths, behaviour) are kept in sync with the code in the same change that alters them.
- Write an ADR only for a decision that outlives the change that introduced it. Details of a single change belong in its pull request or GitHub issue.
- An ADR records the decision and its lasting constraints, not implementation detail. Class lists, step-by-step mechanics and other facts the code already shows belong in the code and its pull request.
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

| #                                                | Decision                                                                                | Status   |
| ------------------------------------------------ | --------------------------------------------------------------------------------------- | -------- |
| [0001](0001-ssh-control-plane.md)                | Laravel over SSH is the only control plane                                              | Accepted |
| [0002](0002-hosts-and-container-per-node.md)     | Supported hosts run Docker and one engine container per node                            | Accepted |
| [0003](0003-engine-images-and-frozen-runtime.md) | Engines ship as pinned images on a frozen runtime                                       | Accepted |
| [0004](0004-engine-drivers-and-blade-scripts.md) | The app drives engines through PHP drivers and hardened Blade scripts                   | Accepted |
| [0005](0005-operations-as-one-job.md)            | A database operation is one action and one job, and engine tasks run off the worker     | Accepted |
| [0006](0006-ha-per-engine-and-dns.md)            | Each engine keeps its own HA, and DNS follows the primary                               | Accepted |
| [0007](0007-networking-and-firewall.md)          | Private networking and a firewall table per database                                    | Accepted |
| [0008](0008-tls-private-ca.md)                   | TLS on every engine: a private CA per database, a public wildcard for key-value engines | Accepted |
| [0009](0009-secrets-and-ssh-keys.md)             | Encrypted secrets, one app SSH key, pinned host keys                                    | Accepted |
| [0010](0010-data-protection-and-migration.md)    | Engine-native backups, restores and migrations over plain S3                            | Accepted |
| [0011](0011-app-code-structure.md)               | Application code structure                                                              | Accepted |
| [0012](0012-projects-as-a-global-scope.md)       | Projects as a global scope                                                              | Accepted |
| [0013](0013-install-state-and-updates.md)        | One-container install, app state, settings and self-update                              | Accepted |

On 2026-11-15, before 1.0, the 39 ADRs were consolidated into 0001 to 0013: superseded decisions were removed, related decisions were merged, and every fact was checked against the code, which won wherever the two differed. This was a one-time exception to the rule that accepted ADRs are not rewritten; from now on a changed decision gets a new ADR that supersedes the old one. Commits, pull requests and notes from before that date use the earlier numbers, and the old texts live in the git history of `docs/adr/`.
