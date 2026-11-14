# 0003. The engine binary is PID 1 and reads a process manifest

- Status: Accepted
- Date: 2026-11-04

## Context

An engine node runs several processes: the engine, its HA agent (for example Patroni and etcd), a pooler, backup schedules. Inside a container there is no systemd, and the official images run one process each.

## Decision

Each engine image extends the official image with a Go binary, `databasify`, built from the public engine SDK (`databasifylabs/engine-sdk`) and the engine's own repository (`databasifylabs/engine-<name>`). It runs as PID 1 (`databasify serve`) and supervises the processes and schedules listed in `/srv/<engine>/conf/processes.json`, which the engine writes itself when a node is configured; the app does not render it. The binary also offers `ctl` (systemctl-like verbs), `step` and `step-kill`.

## Consequences

- A process in the manifest starts only when the engine enables it.
- The manifest never overrides environment the image already sets.
- Server processes start only when their data directory exists, so a lost or erased disk does not leave them in a restart loop.
- Every image must carry the `io.databasify.sdk` label, which the host binary uses to find the engine container. The binary in the image is the one that runs ([0029](0029-engines-ship-only-as-images.md)).

## Alternatives considered

- **systemd inside the container:** rejected, because it needs a privileged container.
- **A separate container per process:** rejected, because the HA agent and the engine share files and lifecycle.

## Evidence

- `app/Engines/Runtime/Runtime.php`
- `app/Actions/Databases/Nodes/InstallHostBinary.php`
