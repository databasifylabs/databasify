# 0008. One driver contract and a fluent engine registration

- Status: Superseded by 0025
- Date: 2026-11-04

## Context

Six engines share the same lifecycle (create, add nodes, fail over, upgrade, back up, migrate) but differ in configuration, roles, HA and tools. Plugins must be able to add engines without changing core code.

## Decision

Each engine is registered with a `RegisterDatabaseProvider::make(id)` chain in `DatabaseProviderServiceProvider`, which writes `config('database-provider.providers.<id>')`. The chain declares label, handler, category, stop seconds, scripts, ports, peer ports, certificates, version lines (with a default and per-line flags such as `'recovery' => false`) and a failover hint.

The handler implements `DatabaseDriver` through `AbstractDatabaseDriver`, or `AbstractSentinelDriver` for Valkey and Redis. A driver is built around its `Database` (`Database::provider()`). Its main methods:

- `fields()`: configuration fields
- `tune(NodeResources)`: settings from the host's real CPU and RAM, never from plan sizes
- `files(DatabaseNode $node)`: config files rendered from `ssh/engines/<dir>/files/*.blade.php`
- `processes()`: the manifest ([0003](0003-go-wrapper-pid1.md))
- `parseRole()`, `membership()`, `generateSecrets()`
- `recovery(): ?Archive` and `migrator(): Migrator`

Upgrades pull the new image on every node first, then roll out read-only nodes, then standbys, then fail over, then upgrade the old primary (`RollOutNodes`). Without standbys, the primary is redeployed in place with no failover. The new image is stored only after every node succeeds.

## Consequences

- Adding an engine means one registration, one driver and its Blade scripts. The `adding-database-engine` skill documents the steps. Registering an id that already exists throws `DuplicateProvider`.
- Configuration fields offer exactly the settings DigitalOcean offers for each engine; tuned values (memory, connections) are not fields.
- A database is tuned for its smallest-memory node.
- `Database.php` carries much of the shared logic and is large.

## Alternatives considered

- **One class per engine with no shared base:** rejected, because lifecycle code would be duplicated six times.

## Evidence

- `app/DatabaseProviders/Contracts/DatabaseDriver.php`
- `app/DatabaseProviders/Drivers/AbstractDatabaseDriver.php`
- `app/DatabaseProviders/Drivers/AbstractSentinelDriver.php`
- `app/Providers/DatabaseProviderServiceProvider.php`
- `app/Plugins/RegisterDatabaseProvider.php`
- `app/Actions/Databases/Upgrades/RollOutNodes.php`
- `.claude/skills/adding-database-engine/SKILL.md`
- `.claude/skills/adding-database-engine/rules/driver.md`
