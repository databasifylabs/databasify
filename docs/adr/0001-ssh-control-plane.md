# 0001. Laravel over SSH is the only control plane

- Status: Accepted
- Date: 2026-11-04

## Context

Databasify manages database clusters on servers the user owns. Something has to install software, write configuration and run operations on each node. A long-running agent per node would need its own release, upgrade and security story.

## Decision

The Laravel app is the only control plane. It renders bash scripts from Blade views, uploads them over SFTP to `~/.databasify` (mode 0700) on the server and runs them with `bash`, prefixed with `sudo -n` when the SSH user is not root. The uploaded script and its pid file are deleted after each run. No Databasify agent runs on the host. On a node, only the engine's own HA layer (see [0009](0009-ha-per-engine.md)) and the in-container supervisor ([0003](0003-go-wrapper-pid1.md)), which restarts processes and runs manifest schedules such as backups, act without the app.

## Consequences

- Every script starts with `set -euo pipefail`. Its `apt-get` wrapper waits up to 600 seconds for the dpkg/apt locks (`APT_LOCK_TIMEOUT_SECONDS`); `dnf` is not wrapped.
- Timeouts: 3600 seconds per step, 21600 seconds for long steps (`LONG_STEP_TIMEOUT_SECONDS`), 90 seconds for probes. SSH connects time out after 10 seconds and are tried 3 times, 3 seconds apart, except when access is denied. A connect or upload that still fails (refused, closed or timed out) raises `SshConnectionFailed`; a script that has started is never run again by the connection.
- A changed host key refuses the connection ([0013](0013-secrets-and-ssh-keys.md)).
- Scripts must be idempotent, because a failed operation is retried by running the same steps again.
- When the app is down, failover still works, but provisioning, status refresh and alerts stop.
- One SSH round trip per step. Throughput is bounded by SSH, not by the engines.

## Alternatives considered

- **A node agent** (a daemon that pulls work from the app): rejected, because it adds a second deployable to version, secure and upgrade on every server.

## Evidence

- `app/Services/Ssh/PhpseclibConnection.php`
- `app/Services/Ssh/Ssh.php`
- `resources/views/ssh/`
