# 0006. One operation per database, one server log per SSH call

- Status: Accepted
- Date: 2026-11-04

## Context

Cluster operations take seconds to hours and touch several nodes. Two operations on the same database at once (for example a failover during an upgrade) can corrupt a cluster. Users need to see what ran on each server and its live output.

## Decision

- Each SSH call names what it does: `$server->ssh()->exec($view, $data, 'db-1: starting')`. A named call creates its own `ServerLog` row (server, optional database, name, status, output, exit code), streams its output into it and over Reverb (`ServerLogOutputReceived`, channel `server-logs.{log}`), and marks it succeeded or failed. An engine reply of `unsupported`, which callers expect and answer with a fallback, marks the log succeeded with a `Skipped:` note instead. An unnamed call is a probe and leaves no log. `secret: true` keeps the log's name and status but never stores or broadcasts its output (certificate keys). Output is broadcast per chunk and saved in batches (16 KB or 1 s), because saving every chunk rewrote the whole row and took 146 s for 5 MB of 256-byte chunks on SQLite. Failures that do not come from SSH (S3, Cloudflare) are written as failed logs with `Server::logFailure()`. Ordering a server from a cloud provider writes three named logs before SSH exists (`OrderProviderInstance`, `AwaitProviderInstance` and `AwaitServerSsh`, named in `LaunchProviderServer::logNamesFor()`), each with one progress line per provider call or poll.
- `DatabaseNode::ssh()` links its logs to the node's database, so database pages list the logs of every server that ran something for it.
- Server logs are kept for 30 days (`ServerLog::RETENTION_DAYS`). The daily `model:prune` deletes older logs in bulk (`MassPrunable`), except a log that is still running or belongs to a database whose operation is still in progress.
- A database runs at most one operation at a time, and its `status` names it ([0036](0036-operations-as-one-job.md)). An action claims the database with `Database::markBusy()`, which reloads the row with `refreshForUpdate()` inside a transaction and turns a busy status into a "busy" error; `ensureIdle()` checks before starting (it also refuses while a migration is running). Each operation's action ends with `Database::markReady()`, and its job's native `failed()` ends the operation through `EndFailedOperation` when it throws; work outside a job (`DeleteRecoveryBackups`) marks the database `ready` in a `finally`. A restart does not end an operation: its job resumes ([0037](0037-engine-tasks-off-the-worker.md)). Servers have no lock.
- Operation jobs that wait on engine tasks release themselves while a task runs and resume after a restart ([0037](0037-engine-tasks-off-the-worker.md)); every other job that runs an operation runs once (`#[Tries(1)]`), and its native `failed()` fails the running logs of its database, or of its server (`Server::failRunningLogs()` with the step names the action lists), when it fails or times out. When a worker dies mid-job, Redis hands the job out again after `retry_after` (3900 s on the `redis` connection, above the longest remaining job timeout of 3600 s); a `#[Tries(1)]` job then exceeds its tries, so Laravel fails it and `failed()` runs the same cleanup. Each supervisor's timeout sits just above its longest job timeout: `RemoveNodeJob`, `DeleteDatabaseJob` and the other long database jobs run up to 3600 s under the `ssh` supervisor's 3700 s, and `RunTerminalSessionJob` runs up to 7260 s on its own `terminals` connection (`retry_after` 7800 s) under the `terminals` supervisor's 7300 s. Polling jobs retry on their own schedule. Every job runs on one of six queue groups: `default`, `monitoring`, `provisioning`, `databases`, `migrations`, `terminals`. Horizon serves them with three supervisors, all `balance: auto`: `default` runs `monitoring` and `default`, so status checks, metrics and alert emails never wait behind long work; `ssh` runs `databases`, `migrations` and `provisioning`; `terminals` runs only `terminals` on its own connection (`HORIZON_TERMINALS_MAX_PROCESSES`, default 2, not sized by the installer). Auto balancing keeps at least one worker per queue (`HORIZON_<GROUP>_MIN_PROCESSES`, default 1) and moves workers to the busiest queue up to `maxProcesses`. Their timeouts cover their longest job: `default` 900 s, `ssh` 3700 s, `terminals` 7300 s; every job also sets its own `#[Timeout]`. Three supervisors instead of one per queue save three supervisor processes (about 135 MB), so a 1 GB server can run the app; the updater removes the retired `HORIZON_{MONITORING,PROVISIONING,DATABASES,MIGRATIONS}_{MAX,MIN}_PROCESSES` settings. The installer writes `HORIZON_DEFAULT_MAX_PROCESSES` and `HORIZON_SSH_MAX_PROCESSES` into `/opt/databasify/.env`, sized by `scripts/horizon-workers.sh` from the server's CPU and memory (measured costs: about 45 MB per Horizon process, 90 MB budgeted per busy worker, at least one worker per queue: 2 and 3 on a 1 GB server, 6 and 18 with 2 CPUs and 4 GB); the updater recomputes them and asks before changing a value ([0026](0026-app-settings-in-env-file.md)). 1 GB is the minimum host and 2 GB is recommended; below that the installer's server check flags it. The Horizon container gets `stop_grace_period: 30m` in `scripts/compose.yaml`, so `docker stop` lets running jobs finish before it kills the workers.
- Stop is "stop after the current step": `Database::requestStop()` sets a cache flag, and the next named call for that database writes a failed "Stopped by you" log and throws `OperationStopped` instead of running. While a job waits on a long engine task, a Stop cancels the task and fails the job, which runs its cleanup; steps that must finish and cleanups run inside `Database::ignoringStop()` (a second cache flag that `isStopRequested()` honours), so a Stop never leaves peers pointing at a half-removed node.

## Consequences

- Steps that touch every node (trusted sources, connection pools, certificates, teardown) loop over the nodes one after another with `each()` and stop at the first node that fails; reads from every node (node status, metrics) use `map()` the same way.
- Long jobs cannot starve status probes, because they run on separate queues.
- A failed job is never retried automatically. Users retry from the UI, which relies on idempotent scripts ([0001](0001-ssh-control-plane.md)).
- The `server-logs.{log}` channel authorizes any signed-in user. That is safe with a single owner ([0014](0014-app-architecture.md)) and must change if multiple users are added.

## Alternatives considered

- **A Task model per operation with step markers and a stop button:** replaced, because every caller had to create, pass and finish the task by hand.
- **Application-level locks without a row lock:** rejected, because a race between two requests could start two operations.
- **One Horizon supervisor per queue:** replaced, because six supervisors and their idle workers needed about 850 MB before the rest of the app, more than a 1 GB server has.
- **`balance: false` for the supervisors:** keeps only one idle worker each, but a free worker always takes the first queue with work; the owner chose a ready worker per queue.

## Evidence

- `app/Services/Ssh/Ssh.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/ServerLog.php`
- `app/Actions/Providers/LaunchProviderServer.php`
- `app/Actions/Providers/OrderProviderInstance.php`
- `app/Actions/Providers/AwaitProviderInstance.php`
- `app/Actions/Providers/AwaitServerSsh.php`
- `app/Models/Database.php`
- `database/migrations/2026_11_04_000001_create_server_logs_table.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
- `database/migrations/2026_11_14_041055_drop_database_columns.php`
- `app/Actions/Databases/Lifecycle/EndFailedOperation.php`
- `app/Jobs/Servers/LaunchProviderServerJob.php`
- `app/Jobs/Servers/RunTerminalSessionJob.php`
- `config/queue.php`
- `app/Enums/Queues/QueueGroup.php`
- `config/horizon.php`
- `scripts/horizon-workers.sh`
- `scripts/compose.yaml`
