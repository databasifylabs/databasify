# 0025. The database driver is one contract that runs its own server steps

- Status: Superseded by 0026
- Date: 2026-11-04

## Context

An engine was described by three interfaces (`DatabaseDriver` with 44 methods, `Archive`, `Migrator`) whose names did not say what touches a server. Account methods ran SQL through `PrimarySqlRunner`, migrators called SSH themselves, and about 17 actions asked the driver for a view name with `script('cluster.failover')` and ran it. The server and DNS drivers already had a clearer shape: a small contract, one remote call per operational method, verb names for changes and noun names for reads.

## Decision

- `DatabaseDriver` is the only engine contract. `Archive` and `Migrator` are gone; archives and migrators are internal helpers (`Shared\EngineArchive`, `Shared\EngineMigrator`) returned by the protected `archive()` and `migration()`, and callers use the driver's `supportsArchive()`, `archiveFiles()`, `restoreArchive()`, `archiveStatus()`, `supportsMigration()`, `migrationForm()`, `startMigration()` and the like.
- Names follow `ServerDriver` and `DnsDriver`: changes are verbs (`startNode`, `failoverTo`, `applyConfig`, `createUser`), reads are nouns even when they hit the server (`status`, `quorum`, `metrics`, `recoveryPosition`), pure data are nouns (`configFiles($node)`, `processes($node)`, `tunedSettings($resources)`, `role($output)`). `id`, `label`, `icon`, `config`, `toArray` match the other drivers.
- A server step is one step on one node. `AbstractDatabaseDriver::runStep()` resolves the engine's script, runs it as one named server log and returns its output; a step whose script the engine lacks runs nothing and writes no log. Steps that wrap engine views in a shared host script (`reloadCertificates`, `metrics`) run that host script themselves. The deploy builder is the only caller of the public `scriptView()`.
- Actions keep everything across nodes and over time: loops, order, the operation lock, status refresh and retries. Account SQL is the exception: it is one statement on one node during a web request, so the account actions call the driver's account methods directly and `EngineAccounts::runSql()` checks the database is idle, takes the lock, runs on the current primary (without refreshing the status first), turns failures into validation errors and releases the lock.
- `connection()` stays on each engine's driver: it is display data but depends on database state for Valkey/Redis (public certificate) and MongoDB (`mongodb+srv` with a DNS zone).
- Node limits live on the driver: `maxStandbys()` is 2 by default, and `maxReadOnlyNodes()` is each engine's real limit (PostgreSQL: the primary's WAL senders and slots minus the standbys and 2 spare; MongoDB: 50 replica set members; MySQL, MariaDB, Valkey and Redis: the primary's connection limit).

## Consequences

- The interface is large (about 70 methods), but the base class implements every server step, so a new engine writes its Blade scripts plus `configFiles`, `processes`, `fields`, `tunedSettings`, `role`, `generateSecrets`, `connection`, `environment` and `maxReadOnlyNodes`.
- Per-node steps let actions run a pass over many nodes in parallel without touching any engine.
- Engines registered by plugins must implement the new names; there is no compatibility layer.

## Alternatives considered

- **Keep three interfaces:** rejected, callers had to know which helper to ask and the names hid server access.
- **Drivers own multi-node flows (`clusterNodes()`, rollouts):** rejected, the same orchestration would be copied into six engines and the lock, logs and stop handling would scatter.
- **Connection profile in the registration:** rejected, more registration code for display data that two engines vary with database state.

## Evidence

- `app/DatabaseProviders/Contracts/DatabaseDriver.php`
- `app/DatabaseProviders/Drivers/AbstractDatabaseDriver.php`
- `app/DatabaseProviders/Drivers/Shared/EngineArchive.php`
- `app/DatabaseProviders/Drivers/Shared/EngineMigrator.php`
- `app/DatabaseProviders/Groups/EngineAccounts.php`
- `.claude/skills/adding-database-engine/rules/driver.md`
