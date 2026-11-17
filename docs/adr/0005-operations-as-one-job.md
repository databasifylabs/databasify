# 0005. A database operation is one action and one job, and engine tasks run off the worker

- Status: Accepted
- Date: 2026-11-15

## Context

Cluster operations take seconds to days and touch several nodes. Two operations on one database at once can corrupt a cluster. A copy of a large database outlives any reasonable job timeout, and an app restart must not end or repeat an operation that the engine is still running. Users need to see what ran on each server.

## Decision

- **Status is the operation.** `DatabaseStatus` names the running work (`creating`, `upgrading`, `scaling`…) or `ready`/`failed`. Health is not stored; it is read from the node roles the status poll records (`DatabaseNodeCollection::health()`).
- **Action, then job.** A controller validates and calls one action. The action checks, then claims the database with `markBusy()`, which reloads the row with `refreshForUpdate()` inside a transaction and refuses while another operation runs, and dispatches one job on the `databases` queue. The job's `handle()` calls exactly one action. Stop cancels the running engine task (`StopOperation`); the job then fails with "Stopped by you." and its normal cleanup runs. Failover, recovery, migrations and delete cannot be stopped.
- **Read-only nodes run their own operations.** `Database` and `DatabaseNode` share `RunsOperations`. Upgrade, redeploy-all, delete and scaling claim the database and every read-only node at once (`markBusyWithReadOnly()`); failover and recovery claim the members only; a read-only node's own config, pools, trusted sources, reboot and redeploy claim that node only.
- **Engine tasks run off the worker.** Each operation job carries a run id; every engine task key is `{run}.{node_id}.{step}`, saved in `database_nodes.task_key`. `EngineTasks::await()` waits up to 60 s, then the job releases itself (`release(30)`). Operation jobs are `#[Tries(0)]`, `#[MaxExceptions(1)]`, `#[Timeout(3600)]`, with `retryUntil()` seven days out. A resumed job runs its action again from the top; started tasks return at once and steps that must not repeat are skipped (`EngineTasks::started()`, `RollingNodes`).
- **Failure and restart.** A job's `failed()` calls `EndFailedOperation` (or `EndFailedNodeOperation`), which stops running tasks first, fails running logs, raises an alert and ends the operation: a failed create erases its setup and marks the database `failed`, a failed add removes its node, anything else returns to `ready`. When Horizon starts, `ResumeJobsWhenHorizonStarts` puts every reserved job back on its queue, so operations resume after a restart.
- **Rolling order.** Rolling changes walk read-only nodes, standbys, a `cluster failover`, then the former primary (`RollingNodes`).
- **Server logs.** Each named SSH call or change script writes one `ServerLog` (server, optional database, status, output, exit code) and streams its output over Reverb; `secret: true` keeps the output out. Logs are kept 30 days.
- **Queues.** Six queue groups served by three Horizon supervisors: `default` (monitoring, default), `ssh` (databases, migrations, provisioning) and `terminals` on its own Redis connection. Each supervisor's timeout sits above its longest job and each connection's `retry_after` above that.

## Consequences

- An operation is one action and one job a reader can follow top to bottom; there is no step table.
- No worker waits on a copy, and an app restart or update resumes operations instead of ending them.
- A failed job is never retried automatically; users retry from the UI, relying on idempotent scripts ([0001](0001-ssh-control-plane.md)).
- Long jobs cannot starve status probes, because they run on separate queues.
- The server log channel authorizes any signed-in user, which is safe only with a single owner.

## Alternatives considered

- **A flow table with step indexes and an advancing job:** removed, too much machinery per operation.
- **Waiting inside the job for the whole copy:** rejected, it holds a worker for hours.
- **Application locks without a row lock, or a cache lock inside the job:** rejected, two requests could start two operations, or the status would show work that is only waiting.
- **One Horizon supervisor per queue:** replaced, it needed more memory than a 1 GB server has.

## Evidence

- `app/Actions/Databases/Lifecycle/StopOperation.php`
- `app/Enums/Databases/DatabaseStatus.php`
- `app/Models/Concerns/RunsOperations.php`
- `app/Models/Database.php`
- `app/Models/Collections/DatabaseNodeCollection.php`
- `app/Services/Databases/EngineTasks.php`
- `app/Services/Databases/RollingNodes.php`
- `app/Actions/Databases/Lifecycle/EndFailedOperation.php`
- `app/Actions/Databases/Lifecycle/EndFailedNodeOperation.php`
- `app/Jobs/Databases/CreateDatabaseJob.php`
- `app/Listeners/ResumeJobsWhenHorizonStarts.php`
- `app/Models/ServerLog.php`
- `app/Enums/Queues/QueueGroup.php`
- `config/horizon.php`
- `config/queue.php`
