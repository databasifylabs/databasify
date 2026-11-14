# 0036. A database operation is one action and one job

- Status: Accepted
- Date: 2026-11-14

## Context

[0028](0028-engine-commands-and-flows.md) ran every multi-step change as a `database_flows` row: a step index, a stored context, operation keys and a job that advanced the flow every 10 seconds. The lock and the title lived in `databases.operation`, and `databases.status` held the health of the nodes. Each operation needed a flow class, a flow record and the advancing machinery, so an action was hard to read. Vito and Coolify, which the owner compared, run each operation as one queued job and mark the resource with a status that names the work.

## Decision

- **Status is the operation.** Read-only nodes carry their own status ([0038](0038-read-only-nodes-managed-on-their-own.md)). `DatabaseStatus` is `pending`, `creating`, `updating`, `upgrading`, `rebooting`, `redeploying`, `scaling`, `recovering`, `migrating`, `deleting`, `ready` or `failed`. `isBusy()` is every status except `ready` and `failed`; `isSettingUp()` is `pending` and `creating`. `databases.operation`, `databases.operation_started_at` and `database_flows` are gone.
- **Health comes from the nodes.** Nothing stores it. `DatabaseNodeCollection::isHealthy()` (exactly one primary and every node serves), `anyServes()` and `health()` (`healthy`, `degraded`, `offline`) read the node roles the status poll records ([0018](0018-monitoring-by-ssh-polling.md)). `RefreshNodeStatuses` compares health before and after a poll and raises the same "is degraded", "is offline" and "is healthy again" alerts; it never writes `databases.status`.
- **Action, then job.** A controller validates and calls one action. The action runs its checks, then `Database::markBusy($status, $changes)`: inside `DB::transaction` it reloads the row with `refreshForUpdate()`, refuses with "{name} is busy {status}, try again when it finishes." when the status is busy, refuses everything but a delete on a database whose setup failed, applies the record changes and writes the new status. `markBusyWith()` returns what the changes return. It then dispatches one job. A create saves the row as `pending`. SQLite ignores `FOR UPDATE`, so its transactions start in `IMMEDIATE` mode ([0019](0019-app-state-sqlite-and-redis.md)).
- **One job runs the whole operation through one action.** Each job runs on the `databases` queue (operation jobs that wait on engine tasks release themselves, [0037](0037-engine-tasks-off-the-worker.md); the others are `#[Tries(1)]`) and its `handle()` calls exactly one action, which holds the business logic: `CreateDatabaseJob` → `SetUpDatabase`, `UpgradeDatabaseJob` → `RollOutImage`, `ApplySettingsJob` → `RollOutSettings`, `RetuneDatabaseJob` → `RollOutTuning`, `RebootDatabaseJob` → `RebootServers`, `AddNodeJob` → `JoinNode`, `RemoveNodeJob` → `TakeNodeOut`, `RedeployNodeJob` → `RedeployNode`, `RecoverClusterJob` → `RecoverCluster`, `RejoinMembersJob` → `RejoinMembers`, and the older jobs (`FailoverDatabaseJob`, `DeleteDatabaseJob`, `RunMigrationJob`, `StopMigrationJob`, `ApplyTrustedSourcesJob`, `ApplyConnectionPoolsJob`, `RenewCertificatesJob`) likewise. Failure cleanups are actions too (`EraseDatabaseSetup`, `UndoNodeJoin`). The actions run in the engine order 0028 documents. `RollingNodes` walks read-only nodes, standbys, a `cluster failover`, then the former primary. `EngineTasks::await()` starts a long engine task and the job gives the worker back while it runs ([0037](0037-engine-tasks-off-the-worker.md)).
- **Failure cleanup.** A job's native `failed()` calls `EndFailedOperation`, which does nothing once the database has left the busy status, so a job that comes back late never touches a newer operation; otherwise it fails the database's running server logs, raises a `DatabaseAlertRaised` "{name}: {operation} failed" alert (not for a Stop) and ends the operation: a create first erases what it set up (`EraseDatabaseSetup`) and marks the database `failed`, an add removes its new node (`UndoNodeJoin`), and every other operation refreshes the node status and marks the database `ready`. The database page shows the newest server log when it failed (`LastFailure`) until a newer log succeeds.
- **Stop** stays a cache flag checked before every named SSH call ([0006](0006-operations-and-server-logs.md)); `EngineTasks` also stops the running engine task.
- **Restart cleanup.** Superseded by [0037](0037-engine-tasks-off-the-worker.md): there is none; interrupted jobs resume.

## Consequences

- An operation is an action and a job a reader can follow top to bottom; there is no step table, context or advancing command.
- Long engine tasks no longer hold a worker, and a restart resumes an operation instead of ending it ([0037](0037-engine-tasks-off-the-worker.md)).
- A failed operation other than a create leaves the database `ready`; its health and the failed log show what is wrong, and the owner runs the action again.
- The operation title shows the status word (`Upgrading`) instead of the step and its progress.

## Alternatives considered

- **Keep the flows:** rejected by the owner; too much machinery for each operation.
- **A job that releases itself while an engine task runs:** rejected here because it seemed to need a step index; adopted in [0037](0037-engine-tasks-off-the-worker.md) with repeatable task keys instead.
- **One job per step, chained:** rejected, many more classes for the same work.
- **A separate `health` column:** rejected, the node roles already hold it.
- **A conditional `update … where status = ready` (a claim):** replaced by `refreshForUpdate()` inside a transaction.
- **A cache lock inside the job (Vito, Coolify):** rejected, the status would show work that is only waiting.

## Evidence

- `app/Enums/Databases/DatabaseStatus.php`
- `app/Models/Database.php`
- `app/Models/Collections/DatabaseNodeCollection.php`
- `app/Actions/Databases/Lifecycle/EndFailedOperation.php`
- `app/Services/Databases/EngineTasks.php`
- `app/Services/Databases/RollingNodes.php`
- `app/Jobs/Databases/CreateDatabaseJob.php`
- `app/Actions/Databases/Lifecycle/SetUpDatabase.php`
- `app/Jobs/Databases/UpgradeDatabaseJob.php`
- `app/Actions/Databases/Nodes/TakeNodeOut.php`
- `app/Actions/Databases/Lifecycle/RefreshNodeStatuses.php`
- `app/DTOs/Databases/LastFailure.php`
- `database/migrations/2026_11_14_041055_drop_database_columns.php`
