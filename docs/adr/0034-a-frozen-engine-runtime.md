# 0034. A frozen engine runtime

- Status: Accepted
- Date: 2026-11-12

## Context

Every engine behaviour lives in Go inside the engine images ([0028](0028-engine-commands-and-flows.md), [0029](0029-engines-ship-only-as-images.md)). A small behaviour change therefore needs:

- an SDK release;
- changes in several engine repositories;
- image rebuilds;
- an app release;
- a wait for the maintenance window.

Most contributors are Laravel developers. The owner decided that engine behaviour moves into the app as hardened Blade scripts, and that images carry only the database, its tools and one generic runtime that rarely changes.

## Decision

- **Engine-neutral commands.** Every engine image answers them through the SDK (`databasifylabs/engine-sdk` `v0.2.2`), next to `engine info`:
    - `runtime info`, which reports protocol `1`;
    - `runtime run`;
    - `task start`, `task status`, `task log` and `task stop`;
    - `file put` and `file get`;
    - `service start`, `service stop`, `service restart`, `service reload` and `service status`.
- **Scripts:**
    - a script runs as `bash -euo pipefail -s` with the script on stdin, never in argv;
    - `runtime run` caps stdout and stderr at 1 MiB each and kills the whole process group at its timeout, replying with exit `124`.
- **Tasks** are operations under a caller-chosen key, so starting a key again returns the existing task. A non-zero exit fails the task with `script exited <code>: <last stderr line>`.
- **Files:** paths are relative to the engine home. Absolute paths, `..`, and escapes through symlinks are refused. Writes are atomic.
- **Services:** `service reload` re-reads `conf/processes.json`, so the app, writing it with `file put`, defines the database's processes.
- **Versioning:** the protocol only ever gains commands. The app checks `runtime info` before relying on a newer protocol.
- **Today's engine commands stay** in the images until the app runs every engine through the runtime. App releases already installed keep working on the new images.

## Consequences

- After the cutover, engine behaviour changes ship as app releases, and images change only when a database or tool version moves.
- Every need the runtime cannot cover means an image change again, so new runtime commands must stay rare and engine-neutral.
- Task records keep their env at mode `0600` under the engine's `state/operations`.
- [0035](0035-engine-api.md) records the cutover: the app drives every engine through the runtime and PHP drivers.

## Alternatives considered

- **Keep engine logic in Go:** rejected by the owner; a behaviour change needs the SDK, every engine repository and the images.
- **Unhardened Blade scripts over SSH (the setup before 0028, and Vito's style):** rejected. Values were not escaped, secrets appeared in argv, and long work held queue workers. The hardened scripts keep Vito's one-file-per-action readability without those problems.
- **Generic argv-only runtime commands with no shell:** rejected for scripts. Engine actions read better as one reviewed script than as PHP-built argv lists.

## Evidence

- `databasifylabs/engine-sdk`: `command/runtime.go`, `command/task.go`, `command/file.go`, `command/service.go`, `command/neutral.go`, `conformance/suite.go`, `README.md` (Runtime)
