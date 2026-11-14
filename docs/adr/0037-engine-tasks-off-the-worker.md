# 0037. Engine tasks run off the worker

- Status: Accepted
- Date: 2026-11-14

## Context

[0036](0036-operations-as-one-job.md) waited for every long engine task inside the job: `EngineTasks::await()` checked `task status` every 5 seconds, and operation jobs timed out after 7200 s. At 1 TB a copy takes about three hours, so it outlived the job, `failed()` erased a node the engine was still copying to, and one of the four `ssh` workers was held the whole time. `databasify:init` also ended every busy operation on a restart while the copy carried on. engine-sdk 1.0 already runs each task as its own supervised unit that survives SSH drops and app restarts, keeps its record in `state/operations/<key>.json`, and returns the existing record when a key is started again.

## Decision

- **A run id per operation.** Each operation job makes `public string $run` (a lowercase ULID) in its constructor. It travels in the queue payload, so it stays the same when the job releases itself and when Redis hands it out again. Every engine task key is `{run}.{node_id}.{step}`, with the step one of `ready`, `enable`, `copy`, `restore`, `join`, `rejoin`.
- **`database_nodes.task_key`** holds the key of the node's running engine task. It is the only state the app keeps about a task.
- **Short wait, then give the worker back.** `EngineTasks::await()` starts the task, saves its key in `task_key` at once and checks it for up to 60 seconds. A finished task clears `task_key` and closes its server log; a failed one fails the log and throws `EngineError`; one still running throws `EngineTaskRunning`. Status reads count any transport failure (no reply, a dropped connection, an engine that is not running) as "still running" and use a 60-second SSH timeout (`Runtime::TASK_TIMEOUT`), so a network blip never fails a copy. A task the engine lost fails the operation rather than starting the work again.
- **The job releases itself.** Operation jobs (create, add node, settings, retune, upgrade, reboot, redeploy, recover, rejoin) are `#[Tries(0)]`, `#[MaxExceptions(1)]`, `#[FailOnTimeout]`, `#[Timeout(3600)]`, because one run still does every quick step up to the first long wait (deploys with image pulls, failovers, reboot waits of up to 15 minutes per node), with `retryUntil()` seven days out. `handle()` first calls `EngineTasks::stillRunning()`, which reads `task status` for each node with a `task_key`, counts an unreachable server as still running, clears the keys of finished tasks and fails the job for a task that failed; while any task runs, or when the action throws `EngineTaskRunning`, the job calls `release(30)`. A real error fails the job on the first throw.
- **Actions run again from the top.** After a task ends, the next run repeats the action; tasks that already started or finished return at once. Steps that must not repeat are skipped when this run's next task on that node exists (`EngineTasks::started()`, which counts a server with no engine binary or no running engine as "not started"): `RollingNodes::each()` skips the failover and the work of a node whose `ready` task exists and only brings it back online, and fails a primary over only while the engine still reports it as primary, so a crash between the failover and the restart does not fail over twice; `RollOutSettings` and `RollOutTuning` keep restarting while `RollingNodes::begun()`; `SetUpDatabase` deploys, configures and starts a node only before its `ready` task and bootstraps only before the restore or the first join; `JoinNode` redeploys the joiner only before its `ready` task; `RejoinMembers` redeploys a member only before its `ready` task. `RecoverCluster` starts every rejoin at once through `EngineTasks::awaitEach()`, which saves each key as it starts, so a later run knows the non-survivors by their `rejoin` tasks and does not pick a survivor or force quorum again; when only some rejoins started (a crash in between), it refuses and asks the owner to check the members and recover again. A crash between `forceQuorum` and the first rejoin start still repeats the survivor choice.
- **Cleanup never races a running task.** `EndFailedOperation`, `UndoNodeJoin` and `EraseDatabaseSetup` call `EngineTasks::stopRunning()` first, which stops each node's running task and clears its key.
- **Ending an operation closes its logs.** `Database::markReady()` and `markFailed()` fail the database's server logs that are still running with "Interrupted.".
- **Resume on Horizon start, no other restart cleanup.** `databasify:init`, `ReleaseInterruptedJobs` and `EndInterruptedWork` are gone, and supervisord starts `php artisan horizon` directly. When Horizon starts, the `ResumeJobsWhenHorizonStarts` listener (on Horizon's `MasterSupervisorDeployed`, which fires before any worker runs) calls `ResumeInterruptedJobs`: every job left in a `queues:<name>:reserved` set of each supervisor's queues goes straight back onto its queue, because at that moment every worker is new and those jobs belong to workers that are gone. An operation job then resumes from its engine tasks, and a `#[Tries(1)]` job reaches `failed()`, as before. Only a single worker that dies while Horizon keeps running still waits for `retry_after`; the database stays busy meanwhile, so no other operation can start. Every unique job sets `#[UniqueFor]` and the one `WithoutOverlapping` sets `expireAfter`, so no lock outlives a restart. Redis keeps its data with `appendonly yes`.
- **Queue settings.** `RunTerminalSessionJob` (7260 s) runs on its own `terminals` Redis connection (`retry_after` 7800 s) under its own `terminals` supervisor (timeout 7300 s). The `redis` connection's `retry_after` is 3900 s, above the longest job timeout on it (3600 s), and the `ssh` supervisor's timeout is 3700 s. `RemoveNodeJob` runs up to 3600 s.

## Consequences

- No worker waits on a copy; a copy of any size finishes within the seven-day limit.
- An app restart or update resumes operations instead of ending them, as soon as Horizon starts again.
- Every engine wait longer than 60 seconds adds up to 30 seconds before the operation moves on.
- Each guard costs one `task status` call per node per run.
- Rejoins during a recovery copy from the survivor side by side.

## Alternatives considered

- **Wait longer inside the job:** rejected by the owner for the load it puts on the Databasify server; Coolify works this way, holding a worker over SSH for up to 11 hours.
- **A `database_operations` queue table like Coolify's:** rejected, it brings back much of `database_flows` for history and queuing nobody asked for.
- **A scheduled command that watches tasks and dispatches the next job:** replaced by the job releasing itself, which already carries its own arguments.

## Evidence

- `app/Services/Databases/EngineTasks.php`
- `app/Services/Databases/RollingNodes.php`
- `app/Exceptions/Engines/EngineTaskRunning.php`
- `app/Models/DatabaseNode.php`
- `app/Models/Database.php`
- `database/migrations/2026_11_14_*_add_task_key_to_database_nodes.php`
- `app/Jobs/Databases/CreateDatabaseJob.php`
- `app/Jobs/Databases/AddNodeJob.php`
- `app/Jobs/Databases/RebootDatabaseJob.php`
- `app/Actions/Databases/Lifecycle/SetUpDatabase.php`
- `app/Actions/Databases/Nodes/JoinNode.php`
- `app/Actions/Databases/Failover/RecoverCluster.php`
- `app/Actions/Databases/Failover/RejoinMembers.php`
- `app/Actions/Databases/Configuration/RollOutSettings.php`
- `app/Actions/Databases/Configuration/RollOutTuning.php`
- `app/Actions/Databases/Lifecycle/EndFailedOperation.php`
- `app/Actions/Databases/Nodes/UndoNodeJoin.php`
- `app/Actions/Databases/Lifecycle/EraseDatabaseSetup.php`
- `app/Engines/Runtime/Runtime.php`
- `app/Jobs/Servers/RunTerminalSessionJob.php`
- `config/queue.php`
- `config/horizon.php`
- `docker/supervisord.conf`
- `app/Listeners/ResumeJobsWhenHorizonStarts.php`
- `app/Actions/Setup/ResumeInterruptedJobs.php`
