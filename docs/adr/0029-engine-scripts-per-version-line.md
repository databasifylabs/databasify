# 0029. Engine scripts per version line

- Status: Accepted
- Date: 2026-11-05

## Context

Each engine had one script tree (`ssh.engines.<engine>`) shared by all of its version lines. Lines drift apart: MySQL 9.0 removed the `mysql_native_password` server option that our MySQL 8.4 `my.cnf` sets, so the same file cannot serve 8.4 and 9.7. Branching on `$line` inside shared files hides which line runs what, and a line that silently borrowed the shared file would never show that it had been checked. Valkey and Redis also shared one `ssh.engines.sentinel` tree, so their lines could not be told apart by folder.

## Decision

- Every engine has its own folder, `resources/views/ssh/engines/<engine>/`. Valkey and Redis each have their own copy (`valkey/`, `redis/`) of the former `sentinel/` tree, which is gone. They may duplicate each other; the PHP classes (`AbstractSentinelDriver`, `SentinelNodes`, `SentinelCluster`) stay shared because Sentinel is the HA mechanism.
- Every registered line has a full folder, `<engine>/lines/<key>/`, mirroring every view of the engine's base tree: steps, `files/*`, `processes/*`, `helpers` and the partials. The key is the line with dots replaced by underscores so it is safe in Blade dot notation (`8.4` → `8_4`, `11.8` → `11_8`, `7.0` → `7_0`, `18` → `18`, `9` → `9`).
- The base tree (`<engine>/…`, plus `ssh/engines/shared/`) holds the shared code. A line file starts as one line, `@include('ssh.engines.<engine>.<name>')`, which inherits the caller's variables, so the rendered script is the base script unchanged.
- `RendersScripts::scriptView($name)` resolves `<scripts>.lines.<key>.<name>` for the database's `version_line` (a driver built without a database line, such as `Database::providerFor()`, uses the registered default line). PHP code keyed by the line reads it through the same resolver, the public `versionLine()` on the driver contract, never `version_line` directly: drivers and their groups alike (the MySQL change buffer default and password encryptions, the PostgreSQL binary path, Patroni file and autovacuum restart, the `line` script variable, the peer ports). Every engine view the app runs, including migrations, archive scripts and `processes.*`, goes through `scriptView()`; no driver or group names an `ssh.engines.*` view directly.
- There is no fallback: a missing line file throws `MissingEngineScript` naming the line view, even when the base file exists. A missing line view is a bug.
- Peer ports can differ per line: `peerPorts($ports, lines: ['<line>' => [...]])` replaces the engine's peer ports for one line, and `ports()` resolves them for the driver's line.
- Overrides are per line. `scripts($path, ['<line>' => ['<step>' => '<view>']])` replaces one step of one line with an explicit view (for plugins); other lines of the engine still resolve their line folder.
- The step name sent to the container wrapper is the view path after `ssh.engines.`, so it includes the line (`mysql.lines.9_7.lifecycle.start`).

## Consequences

- Adding a line: register it in `versions()`, create `<engine>/lines/<key>/` with an `@include` wrapper for every base view (a few lines of shell generate them; the generator is not kept), then diverge what differs. `EngineScriptLinesTest` walks every registered engine × line and fails for every base view the line folder lacks, so adding a base view forces a file in every line.
- Diverging a step: replace the line's wrapper with a full copy and change it there; the base and the other lines stay untouched. Base files include partials by their base name, so when a line diverges a partial, the line's copies of the files that include it become full copies too, pointing at the line's partial. MySQL 9.7 diverges `files.server` (no `mysql_native_password = ON`, no `group_replication_ip_allowlist`) and runs Group Replication on the MYSQL communication stack over the client port, because the allowlist does not apply to that stack and MySQL 9.7.2 deprecates the `group_replication_communication_stack` choice ([variables deprecated in 9.7](https://dev.mysql.com/doc/refman/9.7/en/added-deprecated-removed.html); [connection security](https://dev.mysql.com/doc/refman/9.7/en/group-replication-connection-security.html): with the MySQL stack "the `group_replication_ip_allowlist` system variable is ignored"). The release notes deprecate neither XCom by name nor the allowlist variable: `cluster.create-cluster`, `cluster.adopt` and `cluster.reclone` diverge, so `cluster.cluster`, `cluster.reprovision`, `cluster.rejoin-empty` and `lifecycle.start` are full copies pointing at the line's partials.
- MySQL 9.7 Group Replication traffic runs on 25060, the client port; nothing listens on the 8.4 group port 33061, so only 8.4 opens it to peers (`peerPorts([Mysql::GROUP_PORT], lines: ['9.7' => []])`). On 9.7 the group is protected by the AdminAPI recovery account plus required TLS (`memberSslMode: REQUIRED`), with no network allowlist, unlike 8.4's peer-only group port. A later live test may add `replicationAllowedHost` to narrow the recovery account's host.
- Version-specific behaviour outside the scripts stays in PHP, keyed by the line (`MysqlAccounts::passwordEncryptions()` offers no Legacy encryption from MySQL 9.0 on).
- The tree grows by one folder of small wrapper files per line (489 files for 12 lines). `NativeToolsGateTest` and `ScriptSyntaxTest` cover the line folders too.
- Retiring a line deletes its folder; the base keeps whatever the remaining lines include.

## Alternatives considered

- **Fall back to the base file when a line file is missing:** rejected; a forgotten line file would pass unnoticed and a line would run a script nobody checked for it.
- **Branch on `$line` inside shared files:** rejected; every engine file would need to be read for every line to know what runs.
- **Copy only the steps that differ:** rejected for the same reason as the fallback; with full folders, a line folder lists exactly what that line runs.
- **Keep `sentinel/` shared with flavour-prefixed line keys (`sentinel/lines/valkey_9`):** rejected so every engine has the same `<engine>/lines/<key>` layout.

## Evidence

- `app/DatabaseProviders/Concerns/RendersScripts.php`
- `app/Plugins/RegisterDatabaseProvider.php`
- `app/Providers/DatabaseProviderServiceProvider.php`
- `app/DatabaseProviders/Drivers/Mysql/MysqlAccounts.php`
- `app/DatabaseProviders/Drivers/AbstractDatabaseDriver.php`
- `tests/Feature/Databases/Network/FirewallTest.php`
- `resources/views/ssh/engines/mysql/lines/9_7/files/server.blade.php`
- `resources/views/ssh/engines/mysql/lines/9_7/cluster/create-cluster.blade.php`
- `tests/Feature/DatabaseProviders/EngineScriptLinesTest.php`
