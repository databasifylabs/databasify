# 0028. The app drives engines through typed commands and flows

- Status: Accepted; engine calls and capabilities superseded by [0035](0035-engine-api.md)
- Date: 2026-11-10

## Context

All database logic now lives in the engine images: the `databasify` binary answers the same typed JSON commands for every engine and runs long work (`node ready`, `cluster enable`, `cluster rejoin`, `restore start`) as operations inside the container. The app still carried its own copy of that logic: per-engine drivers, about 800 Blade scripts with folders per version line, and jobs that held a queue worker for up to six hours, so a crashed worker left a database locked until the eight-hour sweep.

## Decision

- **One client.** `EngineClient::send($node, $request)` runs `printf '%s' '<base64 JSON>' | base64 -d | databasify <group> <verb>` over the existing SSH connection ([0001](0001-ssh-control-plane.md)) and decodes the reply into a typed DTO. Requests and replies (`app/Engines/Requests`, `app/Engines/Responses`, `app/Engines/Data`) mirror the engine's `internal/requests`, `internal/responses` and `internal/dtos` name for name. Engine error codes become typed exceptions (`NotPrimary`, `EngineBusy`, `EngineUnavailable`, `EngineNotFound`, `EngineAlreadyExists`, `EngineUnsupported`, `InvalidEngineRequest`, `EngineError`); exit 69 is `EngineNotRunning`. `RunOnPrimary` sends to the primary and retries once on the current primary after `not_primary`.
- **Flows instead of long jobs.** A multi-step change is a `database_flows` row: the flow class, the step index, a `FlowContext` (node order, target, failover target, flags) and the operation it waits on. `AdvanceDatabaseFlow` (one job per running flow, every 10 seconds, `WithoutOverlapping`, 120 s timeout) runs quick steps until a long step starts an engine operation, stores its key and returns; the next tick polls `operation status`. Steps are built from the stored context only, so a flow's step list never shifts when roles change. Step kinds set the failure policy:
    - `Prepare`: a failure fails the flow and runs its cleanup.
    - `CatchUp` (the engine copying data): a failed operation fails the flow and runs cleanup; an app outage only delays the next poll.
    - `Finish`: a failure is retried on the next tick and never cleans up.
    - An operation key is `{flow}.{step}.{attempt}` and is saved before the command is sent, so a worker that dies right after the engine accepted it finds the operation on the next tick. A lost operation (`not_found` or `interrupted`) starts the step again under the next attempt; the engine's commands are safe to repeat. An SSH, `not_running` or `unavailable` error while starting a catch-up or finish step waits for the next tick. `StepNotReady` waits too.
    - A finish step or a lost operation gives up after 30 attempts, so a deterministic error cannot hold the lock forever.
    - A failed flow's cleanup runs in its own `CleanUpDatabaseFlow` job (one hour), which releases the operation when it ends.
    - Recovery sends `cluster force-quorum` with `othersDown` only when the owner confirmed the other nodes are down, refuses when no member's quorum can be read, and refuses to pick a survivor when a member's position is unknown.
- **The flows follow the engine README.** Create (and restore), add, remove, redeploy, reboot, settings change, rolling restart, re-tune, upgrade, point-in-time recovery changes and recovery after an outage each follow the order the engine documents. Rolling changes (reboot, restart, upgrade) go read-only nodes, standbys, `cluster failover`, former primary.
- **A small catalog.** `EngineCatalog` holds only what the app needs per engine: label, icon, category, image repository, version lines, failover hint, SRV record, connection profile, Laravel environment, migration source form, persistence choice, whether read-only nodes vote and, for a community engine, its plugin and author ([0030](0030-community-engines.md)). Plugins register engines with `RegisterEngine` as data. What a database can do comes from the engine: `engine capabilities` (commands, password encryptions, reserved names, ports) and `config fields` are read from the seed after its first deploy and stored on the database.
- **The app's CA.** Certificates are issued in PHP ([0010](0010-tls-private-ca.md)) and installed with `tls install`.
- **Only `databasify` runs on a database server**, apart from the install of the pinned host binary (`install-host-binary.blade.php`), the host facts read for metrics, the firewall and the OS reboot.

## Consequences

- No worker waits on a long database operation; a worker crash loses at most one tick.
- The app has no version-line branches, no engine scripts and no engine-specific flows; a new engine or line is an engine image plus a catalog entry.
- Operation titles show the flow's phase and progress when the engine reports them.
- Engine-side validation is the authority: the app still validates forms for good messages, the engine refuses what is unsafe.

## Alternatives considered

- **Keeping the drivers and calling the engine from them:** rejected, a second layer that wraps the client again without adding anything.
- **Jobs that wait on operations with `sleep`:** rejected, they hold a worker for the whole copy.

## Evidence

- `app/Engines/EngineCatalog.php`
- `app/Providers/EngineServiceProvider.php`
- `app/Actions/Databases/Flows/AdvanceFlow.php`
- `app/Actions/Databases/Flows/StartFlow.php`
- `app/Engines/Flows/CreateDatabaseFlow.php`
- `app/Engines/Flows/RollingSteps.php`
- `app/Jobs/Databases/AdvanceDatabaseFlow.php`
- `app/Jobs/Databases/CleanUpDatabaseFlow.php`
- `database/migrations/2026_11_10_030902_create_database_flows_table.php`
