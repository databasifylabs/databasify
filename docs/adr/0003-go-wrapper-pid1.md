# 0003. Go wrapper is PID 1 and reads a process manifest

- Status: Accepted
- Date: 2026-11-04

## Context

An engine node runs several processes: the engine, its HA agent (for example Patroni and etcd), a pooler, backup schedules. Inside a container there is no systemd, and the official images run one process each.

## Decision

Each engine image extends the official image with a Go binary, `databasify`, which runs as PID 1 (`databasify serve`). It supervises the processes and schedules listed in `/srv/<engine>/conf/processes.json`. The app renders that file from each driver's `processes()` as a `ProcessManifest`. The wrapper also offers `ctl` (systemctl-like verbs), `step` and `step-kill`.

The Go source lives in the separate `databasifylabs/engines` repository.

## Consequences

- Processes start disabled unless marked `enabledAtBoot()`; a script enables the rest.
- Manifest `env` overrides the image's `ENV`, so drivers must not set environment the image already sets.
- Server processes must guard on their data directory existing.
- Engine and database scripts (`ssh/engines`, `ssh/databases`) must use `databasify ctl`, never `systemctl`, `systemd-run`, `journalctl`, `apt`, `apt-mark`, `dpkg`, `dnf` or `runuser`. `NativeToolsGateTest` enforces this, with three documented exceptions. Host provisioning scripts (`ssh/servers`) use the native tools by design.
- Every image must carry the `io.databasify.wrapper` label. An image without it, or with a wrapper older than `images.minimum_wrapper`, is refused (see [0004](0004-image-digest-pinning.md)).

## Alternatives considered

- **systemd inside the container:** rejected, because it needs a privileged container.
- **A separate container per process:** rejected, because the HA agent and the engine share files and lifecycle.

## Evidence

- `app/Engines/EngineClient.php`
