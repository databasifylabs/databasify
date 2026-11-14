# 0036. A database operation is one action and one job

- Status: Accepted
- Date: 2026-11-14

## Context

[0028](0028-engine-commands-and-flows.md) ran every multi-step change as a `database_flows` row: a step index, a stored context, operation keys and a job that advanced the flow every 10 seconds. The lock and the title lived in `databases.operation`, and `databases.status` held the health of the nodes. Each operation needed a flow class, a flow record and the advancing machinery, so an action was hard to read. Vito and Coolify, which the owner compared, run each operation as one queued job and mark the resource with a status that names the work.

## Decision

- **Status is the operation.** `DatabaseStatus` is `pending`, `creating`, `updating`, `upgrading`, `rebooting`, `redeploying`, `scaling`, `recovering`, `migrating`, `deleting`, `ready` or `failed`. `isBusy()` is every status except `ready` and `failed`; `isSettingUp()` is `pending` and `creating`. `databases.operation`, `databases.operation_started_at` and `database_flows` are gone.
- **Health comes from the nodes.** Nothing stores it. `DatabaseNodeCollection::isHealthy()` (exactly one primary and every node serves), `anyServes()` and `health()` (`healthy`, `degraded`, `offline`) read the node roles the status poll records ([0018](0018-monitoring-by-ssh-polling.md)). `RefreshNodeStatuses` compares health before and after a poll and raises the same "is degraded", "is offline" and "is healthy again" alerts; it never writes `databases.status`.
- **Action, then job.** A controller validates and calls one action. The action runs its checks, then `Database::markBusy($status, $changes)`: inside `DB::transaction` it reloads the row with `refreshForUpdate()`, refuses with "{name} is busy {status}, try again when it finishes." when the status is busy, refuses everything but a delete on a database whose setup failed, applies the record changes and writes the new status. `markBusyWith()` returns what the changes return. It then dispatches one job. A create saves the row as `pending`. SQLite ignores `FOR UPDATE`, so its transactions start in `IMMEDIATE` mode ([0019](0019-app-state-sqlite-and-redis.md)).
- **One job runs the whole operation through one action.** Each job is `#[Tries(1)]` on the `databases` queue and its `handle()` calls exactly one action, which holds the business logic: `CreateDatabaseJob` → `SetUpDatabase`, `UpgradeDatabaseJob` → `RollOutImage`, `ApplySettingsJob` → `RollOutSettings`, `RetuneDatabaseJob` → `RollOutTuning`, `RebootDatabaseJob` → `RebootServers`, `AddNodeJob` → `JoinNode`, `RemoveNodeJob` → `TakeNodeOut`, `RedeployNodeJob` → `RedeployNode`, `RecoverClusterJob` → `RecoverCluster`, `RejoinMembersJob` → `RejoinMembers`, and the older jobs (`FailoverDatabaseJob`, `DeleteDatabaseJob`, `RunMigrationJob`, `StopMigrationJob`, `ApplyTrustedSourcesJob`, `ApplyConnectionPoolsJob`, `RenewCertificatesJob`) likewise. Failure cleanups are actions too (`EraseDatabaseSetup`, `UndoNodeJoin`). The actions run in the engine order 0028 documents. `RollingNodes` walks read-only nodes, standbys, a `cluster failover`, then the former primary. `EngineTasks::await()` starts a long engine task under a fresh key and checks its status every 5 seconds inside the job until it ends, with a named server log that closes with the result. Operation jobs time out after 7200 s.
- **Shared job behaviour.** The `FinishesOperation` trait adds the `RunsDatabaseOperation` middleware and a `failed()` method. The middleware reloads the database and ends the job without touching a server when the database is gone (`#[DeleteWhenMissingModels]`), is no longer in the job's status, or the owner pressed Stop while the job was queued; after a successful run it marks the database `ready`; a Stop while queued runs the job's `abandon()` (an add removes its new node, a delete of a failed database keeps it `failed`). `failed()` does nothing once the database has left the job's status, so a job that comes back late never touches a newer operation; otherwise it fails the database's running server logs, raises a `DatabaseAlertRaised` "{name}: {operation} failed" alert (not for a Stop) and ends the operation: a create erases what it set up and marks the database `failed`; every other operation refreshes the node status and marks the database `ready`. The database page shows the newest server log when it failed (`LastFailure`) until a newer log succeeds.
- **Stop** stays a cache flag checked before every named SSH call ([0006](0006-operations-and-server-logs.md)); `EngineTasks` also stops the running engine task.
- **Restart cleanup.** `databasify:init` runs every time supervisord starts Horizon (`docker/supervisord.conf`), so after the bundled Redis is up and again after Horizon itself restarts, and before any worker takes a job. Each step reports and carries on, as Coolify's `app:init` does; the Redis step retries for 30 seconds while Redis starts. It removes every job left in a `queues:<name>:reserved` set and marks its Horizon record failed ("Interrupted by an app restart."), so a redelivered job can never run `failed()` over a newer operation; pending and delayed jobs stay. It deletes `WithoutOverlapping` and `ShouldBeUnique` locks that have no expiry. It ends busy databases (`pending`/`creating` become `failed`, the rest `ready`), fails servers still setting up and fails running server logs. It never runs SSH and leaves data migrations and a running self-update alone.

## Consequences

- An operation is an action and a job a reader can follow top to bottom; there is no step table, context or advancing command.
- A worker is busy for the whole operation, including a long restore or join; a job that outlives its timeout fails and the owner runs it again.
- A worker crash no longer resumes an operation. When the app or Horizon restarts, `databasify:init` ends it, operations still waiting in the queue included; when one worker dies while the app keeps running, Redis hands the job out again after `retry_after` and `#[Tries(1)]` fails it.
- A failed operation other than a create leaves the database `ready`; its health and the failed log show what is wrong, and the owner runs the action again.
- The operation title shows the status word (`Upgrading`) instead of the step and its progress.

## Alternatives considered

- **Keep the flows:** rejected by the owner; too much machinery for each operation.
- **A job that releases itself while an engine task runs:** rejected, it needs the step index back on the database.
- **One job per step, chained:** rejected, many more classes for the same work.
- **A separate `health` column:** rejected, the node roles already hold it.
- **A conditional `update … where status = ready` (a claim):** replaced by `refreshForUpdate()` inside a transaction.
- **A cache lock inside the job (Vito, Coolify):** rejected, the status would show work that is only waiting.

## Evidence

- `app/Enums/Databases/DatabaseStatus.php`
- `app/Models/Database.php`
- `app/Models/Collections/DatabaseNodeCollection.php`
- `app/Jobs/Concerns/FinishesOperation.php`
- `app/Jobs/Middleware/RunsDatabaseOperation.php`
- `app/Services/Databases/EngineTasks.php`
- `app/Services/Databases/RollingNodes.php`
- `app/Jobs/Databases/CreateDatabaseJob.php`
- `app/Actions/Databases/Lifecycle/SetUpDatabase.php`
- `app/Jobs/Databases/UpgradeDatabaseJob.php`
- `app/Actions/Databases/Nodes/TakeNodeOut.php`
- `app/Actions/Databases/Lifecycle/RefreshNodeStatuses.php`
- `app/DTOs/Databases/LastFailure.php`
- `app/Console/Commands/InitCommand.php`
- `app/Actions/Setup/ReleaseInterruptedJobs.php`
- `app/Actions/Setup/EndInterruptedWork.php`
- `docker/supervisord.conf`
- `database/migrations/2026_11_14_041055_drop_database_columns.php`
