# 0026. Database driver groups

- Status: Accepted
- Date: 2026-11-05

## Context

ADR 0025 made `DatabaseDriver` the only engine contract and let it run its own server steps. It grew to 88 methods and `AbstractDatabaseDriver` to 802 lines: node steps, cluster steps, accounts, archives and migrations all lived in one class although they change for different reasons (a new backup tool, a new migration source, a new HA mode). Callers asked `supportsAccounts()`, `supportsArchive()` or `supportsMigration()` and then called methods that threw when the capability was missing. The server and DNS drivers have small contracts and the project rule is one class per concern.

## Decision

- The driver describes the engine: identity, config, ports and TLS, versions, connection, environment, limits, node files, processes, fields and tuning, roles, script views and the engine-specific `scriptVariables()` and `membershipVariables()`.
- What you do with the engine lives in groups in `app/DatabaseProviders/Groups/`, reached from the driver: `nodes(): EngineNodes`, `cluster(): EngineCluster`, `accounts(): ?EngineAccounts`, `archive(): ?EngineArchive`, `migration(): ?EngineMigration`. Method names drop the group word (`nodes()->start()`, `cluster()->failoverTo()`, `accounts()->createUser()`, `archive()->restore()`, `migration()->start()`).
- The data handed to step scripts is built by the always-present `scriptData(): EngineScriptData` group: `for()`, `versions()`, `starting()`, `readiness()`, `waking()`, `clustering()`, `admission()`, `stopping()`, `deploy()`, `archiving()`, `restoring()`, `liveConfig()`, `workspace()`, `certificates()`, `tlsFiles()`. It reads the engine through the driver and merges the driver's `scriptVariables()` and `membershipVariables()`.
- A missing capability is a `null` group. Presentation and validation code branches on `null` (`DatabaseResource`, `MigrationSourceRequest`, `Database::archiveFiles()`, `Database::archivesForRecovery()`, the account actions' "has no users or databases to manage" validation error from `MissingCapability::rejectionOf()`). Actions reach a group they need through `Database::engineAccounts()` (the account actions; throws the `MissingCapability::rejectionOf()` validation error), `Database::engineMigration()` (the migration actions) and `Database::engineArchive()` (`RefreshRecoveryStatus`), which throw `MissingCapability::of()` when the group is `null`.
- Every group extends `EngineGroup`, built with the driver and its database, which owns the step plumbing (`runStep()`, and `scriptData()` as a shortcut to the driver's script data): a step resolves the engine's script, runs it as one named server log and returns its output. Scripts, variables, log names and timeouts are those of ADR 0025.
- Skipped steps are explicit in code, never implied by a missing view. `scriptView()` is a plain lookup in the database line's folder ([0029](0029-engine-scripts-per-version-line.md)) and throws `MissingEngineScript` when the view does not exist, so a typo or a forgotten script fails loudly. A base group method always runs its script, except a step only one engine has: its base method is a no-op and the owner overrides it (`nodes()->reset()` in `SentinelNodes`; `nodes()->syncConfig()` and `nodes()->applyPools()` in `PostgresNodes`; `nodes()->skipsRestartOnSameConfig()` and `nodes()->runsCurrentConfig()` in `MysqlNodes`, `MariadbNodes` and `MongodbNodes` through the shared `SkipsRestartOnSameConfig` trait, which both return `false` in the base). An engine without a step overrides it as an empty method in its group subclass: `SentinelNodes::finishBootstrap()`, `MariadbNodes::wake()`, `MongodbCluster::cluster()`, `admit()` in `MysqlCluster`, `MongodbCluster` and `SentinelCluster`, `adopt()` in `MariadbCluster` and `SentinelCluster`, `forget()` in `MariadbCluster` and `SentinelCluster`, and `updateMembership()`, `rejoin()` and `recoveryPosition()` (returns `null`) in `MysqlCluster`.
- An engine's own script folder holds a file for every step it has; nothing is borrowed from another engine's or the shared folder through the registration's `scripts()` overrides. A step that reuses shared logic is a one-line file in the engine's folder that `@include`s it (for example `mariadb/accounts/create-user` includes `mysql/accounts/create-user`, `postgres/cluster/rejoin` includes `shared/wake`), so the rendered script stays the same. Each version line runs from its own folder under the engine's (`<engine>/lines/<key>/`), and Valkey and Redis each have their own folder; see [0029](0029-engine-scripts-per-version-line.md). The per-line override map stays in `RegisterDatabaseProvider` for plugins.
- `AbstractDatabaseDriver` keeps what defines an engine (constructor, everything read from the registration config such as `label()`, `ports()` (peer ports may differ per version line, [0029](0029-engine-scripts-per-version-line.md)), `tls()`, `versions()` and `imageRepository()`, `clientAddress()`, `canFailover()`, `scriptVariables()`, tuned-settings helpers and the abstract methods) and takes the shared parts from two traits in `app/DatabaseProviders/Concerns/`: `RendersScripts` (`scriptView()`, `versionLine()`, `render()`, `shell()`) and `ProvidesGroups` (the final group accessors and their factories).
- `ProvidesGroups` builds the groups from protected factories (`nodeSteps()`, `clusterSteps()`, `accountSteps()`, `archiveSteps()`, `migrationSteps()`, `scriptDataSteps()`). Defaults: generic nodes, cluster and script data, no accounts, no archive, no migration. An engine overrides a factory to return a `final readonly` subclass from its own folder: `<Name>Archive`, `<Name>Migration` (`MysqlMigration` and `MariadbMigration` extend the shared `Drivers/Shared/BinlogMigration`), `<Name>Accounts` (reserved names, password encryption; returned by every engine with accounts), `MysqlCluster`, `MariadbCluster`, `PostgresCluster` (clone donor, membership changes), `MongodbCluster`, `PostgresNodes`, `MariadbNodes`, `MysqlNodes`, and `SentinelNodes` / `SentinelCluster` in `Drivers/Shared` for Valkey and Redis (the explicit steps above).
- A rollout (`RollOutNodes`: upgrade, retune, settings redeploy, maintenance) redeploys read-only nodes, then standbys, then the primary. It fails the primary over first unless the primary keeps running: its engine skips restarts on an unchanged config (`nodes()->skipsRestartOnSameConfig()`, MySQL, MariaDB and MongoDB), its container already runs the image being deployed (`nodes()->runsImage()`, a read-only check of `DATABASIFY_IMAGE` before anything is written), and after its new config is written `nodes()->runsCurrentConfig()` reports the same config fingerprint. A primary on another image always fails over first, since the deploy recreates its container. Without standbys the primary is redeployed in place.
- The driver may use its own groups (`processes()` asks the archive for its schedules). Groups use each other through the driver (`scriptData()->starting()` asks `cluster()->cloneDonorFor()`). A group reaches another group only through the driver (`nodes()->finishBootstrap()` uses `accounts()?->defaultAccountSql()`); no group stores another group or calls an action.

## Consequences

- The contract drops to 37 methods and `AbstractDatabaseDriver` to the engine description and its registration reads; each group is small and changes for one reason.
- A capability check is a `null` check on the group, and the methods that only threw are gone. The account actions call `$database->engineAccounts()` and then the account method, which finds the primary itself.
- Engines customise by subclassing the one group that differs; there is no per-engine copy of the step plumbing.
- Groups are created per call (readonly drivers cannot cache lazily); they hold only the driver and the database.
- Engines registered by plugins must move overrides into group subclasses; there is no compatibility layer.
- Reading a group subclass shows every step the engine skips; a missing script is a `MissingEngineScript` error instead of a silent no-op, and a plugin engine must override every step it has no script for.
- Each engine folder lists every step it runs, so the step name the container shim receives is the engine's own (`postgres.cluster.rejoin` instead of `shared.wake`); the shim only uses it to name its temporary script file.

## Alternatives considered

- **Keep one large contract (ADR 0025):** rejected, unrelated concerns kept growing in one class and capability flags let callers reach methods that throw.
- **Groups as separate interfaces implemented by the driver:** rejected, the driver would still be one class with every concern.
- **Groups holding references to each other:** rejected, construction would become cyclic; going through the driver keeps it acyclic.

## Evidence

- `app/DatabaseProviders/Contracts/DatabaseDriver.php`
- `app/DatabaseProviders/Drivers/AbstractDatabaseDriver.php`
- `app/DatabaseProviders/Concerns/RendersScripts.php`
- `app/DatabaseProviders/Concerns/ProvidesGroups.php`
- `app/DatabaseProviders/Groups/EngineGroup.php`
- `app/DatabaseProviders/Groups/EngineNodes.php`
- `app/DatabaseProviders/Groups/EngineCluster.php`
- `app/DatabaseProviders/Groups/EngineAccounts.php`
- `app/DatabaseProviders/Groups/EngineArchive.php`
- `app/DatabaseProviders/Groups/EngineMigration.php`
- `app/DatabaseProviders/Groups/EngineScriptData.php`
- `app/DatabaseProviders/Drivers/Shared/SentinelNodes.php`
- `app/DatabaseProviders/Drivers/Shared/SentinelCluster.php`
- `app/DatabaseProviders/Drivers/Postgres/PostgresNodes.php`
- `app/DatabaseProviders/Drivers/Mysql/MysqlNodes.php`
- `app/Actions/Databases/Upgrades/RollOutNodes.php`
- `app/DatabaseProviders/Drivers/Mariadb/MariadbNodes.php`
- `app/DatabaseProviders/Drivers/Mongodb/MongodbNodes.php`
- `app/DatabaseProviders/Concerns/SkipsRestartOnSameConfig.php`
- `app/DatabaseProviders/Drivers/Mongodb/MongodbCluster.php`
- `app/Exceptions/Databases/MissingEngineScript.php`
- `app/Providers/DatabaseProviderServiceProvider.php`
- `app/Exceptions/Databases/MissingCapability.php`
- `app/Models/Database.php`
- `app/DatabaseProviders/Drivers/Shared/BinlogMigration.php`
- `app/DatabaseProviders/Groups/EngineAccounts.php`
- `.claude/skills/adding-database-engine/rules/driver.md`
- `.claude/skills/adding-database-engine/rules/scripts.md`
- `tests/Feature/DatabaseProviders/SkippedStepsTest.php`
