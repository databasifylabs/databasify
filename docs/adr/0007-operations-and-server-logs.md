# 0007. One operation per database, one server log per SSH call

- Status: Accepted
- Date: 2026-11-04

## Context

Cluster operations take seconds to hours and touch several nodes. Two operations on the same database at once (for example a failover during an upgrade) can corrupt a cluster. Users need to see what ran on each server and its live output.

## Decision

- Each SSH call names what it does: `$server->ssh()->exec($view, $data, 'db-1: starting')`. A named call creates its own `ServerLog` row (server, optional database, name, status, output, exit code), streams its output into it and over Reverb (`ServerLogOutputReceived`, channel `server-logs.{log}`), and marks it succeeded or failed. An unnamed call is a probe and leaves no log. `secret: true` keeps the log's name and status but never stores or broadcasts its output (certificate keys). Output is broadcast per chunk and saved in batches (16 KB or 1 s), because saving every chunk rewrote the whole row and took 146 s for 5 MB of 256-byte chunks on SQLite. Failures that do not come from SSH (S3, Cloudflare) are written as failed logs with `Server::logFailure()`. Ordering a server from a cloud provider writes three named logs before SSH exists (`OrderProviderInstance`, `AwaitProviderInstance` and `AwaitServerSsh`, named in `LaunchProviderServer::logNamesFor()`), each with one progress line per provider call or poll.
- `DatabaseNode::ssh()` links its logs to the node's database, so database pages list the logs of every server that ran something for it.
- Server logs are kept for 30 days (`ServerLog::RETENTION_DAYS`). The daily `model:prune` deletes older logs in bulk (`MassPrunable`), except a log that is still running or belongs to a database whose operation is still in progress.
- A database runs at most one operation at a time. `databases.operation` holds its title; `Database::startOperation()` claims it with a conditional update and turns a lost race into a "busy" error, `ensureIdle()` checks before starting (it also refuses while a migration is running), and `Database::finishOperation()` releases it. Queued jobs release it in the `ReleasesDatabaseOperation` job middleware, which the `FinishesOperation` trait attaches, once the job's `handle()` returns or throws, so actions hold no release logic; work outside a job (`EngineAccounts::runSql()` in a web request, `DeleteRecoveryBackups`, which claims its own operation inside its job) releases it in a `finally`. Servers have no lock.
- Jobs that run an operation run once (`#[Tries(1)]`); the `FinishesOperation` trait fails the running logs of the job's database and releases its operation, and the `FailsRunningLogs` and `FailsNetworkLogs` traits fail only the running logs named in the job's `logNames()` (on its server, or on the servers of its network), when a job fails or times out. `servers:release-abandoned-work` (every five minutes) does the same for logs and operations older than eight hours, in case the worker died without running `failed()`. Polling jobs retry on their own schedule. Every job runs on one of six queue groups: `default`, `monitoring`, `provisioning`, `databases`, `migrations`, `terminals`. Horizon has one supervisor per group. Their worker counts are fixed defaults in `config/horizon.php` sized for the 2 GB minimum host (default 1, monitoring 2, provisioning 1, databases 3, migrations 1, terminals 1); a bigger host raises them with `HORIZON_<GROUP>_MAX_PROCESSES` in `.env` and recreates the containers. The Horizon container gets `stop_grace_period: 30m` in `docker/compose.yaml`, so `docker stop` lets running jobs finish before it kills the workers.
- Stop is "stop after the current step": `Database::requestStop()` sets a cache flag, and the next named call for that database writes a failed "Stopped by you" log and throws `OperationStopped` instead of running. The running script is not killed; a hung script ends at its timeout. Compensating steps run inside `Database::ignoringStop()` (a second cache flag that `isStopRequested()` honours), so a Stop during an add halts the add and its rollback (`SettleFailedJoin`, `DetachNode`) still erases the new node; `DetachNode` never tolerates `OperationStopped`, even when forced, and runs the peer steps that follow forgetting the node (membership, peer ports, sentinel reset, uncluster) inside `ignoringStop()`, so a Stop during a removal never leaves the peers pointing at a forgotten node. Best-effort steps that log their own failure (the default user's replication and migrated-database grants) rethrow `OperationStopped` instead of logging it.

## Consequences

- Steps that touch every node run in parallel through `NodeRunner` (at most 50 at once), on the `fork` concurrency driver in CLI processes and the `process` driver in web requests. Forked children drop inherited SSH sessions and reconnect to the database and Redis. The `fork` driver is `WorkerSafeForkDriver`, registered in `AppServiceProvider`: it keeps the queue worker's own SIGTERM, SIGINT and SIGQUIT handlers while it waits for the children. spatie/fork's default handler SIGKILLs the worker and its children, so a Horizon scale-down (`balance` `auto`) or `horizon:terminate` that arrived during a parallel step killed the job mid-run, leaving it reserved, its logs running and its unique lock held. Now the worker finishes the job and then stops, so supervisors may scale between `HORIZON_<GROUP>_MIN_PROCESSES` and `HORIZON_<GROUP>_MAX_PROCESSES`. The logs of one pass share a group (`server_logs.group_id`, `group_name`) and every node finishes before the first failure fails the operation. Reads from every node (`NodeRunner::map()`: node status, metrics, and the last committed positions that Recover compares in `PromoteSurvivor`) run in parallel the same way and also rethrow the first failed read once every node has answered.
- Long jobs cannot starve status probes, because they run on separate queues.
- A failed job is never retried automatically. Users retry from the UI, which relies on idempotent scripts ([0001](0001-ssh-control-plane.md)).
- The `server-logs.{log}` channel authorizes any signed-in user. That is safe with a single owner ([0016](0016-app-architecture.md)) and must change if multiple users are added.

## Alternatives considered

- **A Task model per operation with step markers and a stop button:** replaced, because every caller had to create, pass and finish the task by hand.
- **Application-level locks without a conditional update:** rejected, because a race between two requests could start two operations.

## Evidence

- `app/Services/Ssh/Ssh.php`
- `app/Services/Databases/NodeRunner.php`
- `app/Support/Concurrency/WorkerSafeForkDriver.php`
- `app/Support/Concurrency/WorkerSafeFork.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/ServerLog.php`
- `app/Actions/Providers/LaunchProviderServer.php`
- `app/Actions/Providers/OrderProviderInstance.php`
- `app/Actions/Providers/AwaitProviderInstance.php`
- `app/Actions/Providers/AwaitServerSsh.php`
- `app/Models/Database.php`
- `database/migrations/2026_11_04_000001_create_server_logs_table.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
- `app/Jobs/Concerns/FinishesOperation.php`
- `app/Actions/Databases/Nodes/SettleFailedJoin.php`
- `app/Actions/Databases/Nodes/DetachNode.php`
- `app/Jobs/Middleware/ReleasesDatabaseOperation.php`
- `app/Jobs/Concerns/FailsRunningLogs.php`
- `app/Jobs/Concerns/FailsNetworkLogs.php`
- `app/Actions/Servers/ReleaseAbandonedWork.php`
- `app/Enums/Queues/QueueGroup.php`
- `config/horizon.php`
- `docker/.env.example`
- `docker/compose.yaml`
