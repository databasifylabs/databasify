# 0035. The app drives engines through PHP drivers

- Status: Accepted
- Date: 2026-11-13

## Context

[0034](0034-a-frozen-engine-runtime.md) froze a generic runtime in the engine images so engine behaviour could move into the app. Until now the app still reached engines through `EngineClient`, which sent typed Go commands (`app/Engines/Requests`, `app/Engines/Responses`) and only routed some of them to PHP drivers behind the `php_engines` switch. Two paths for every call made changes slow and hid which code really ran. The owner chose to rebuild the engine half on `0.x`: the app talks to an engine through its PHP driver alone, and the Go command paths go. Operations (flows, `database_flows`, the `operation` lock) stay as [0028](0028-engine-commands-and-flows.md) describes and get their own design later.

## Decision

- **Calls.** `$database->driver()` builds the registered driver for the primary, or the seed when there is none; `$node->driver()` builds it for one node. Both resolve `Engine::$driver` through the container with the node, so tests can bind a stand-in. Through `Database::driver()`, a script that answers `not_primary` refreshes the database status and runs once more on the new primary; a second `not_primary` reaches the caller. `RunOnPrimary` is gone.
- **Contract.** `App\Engines\Contracts\EngineDriver` has a static `facts(string $line)` and seven groups: `nodes()`, `cluster()`, `config()`, `accounts()`, `archive()`, `migration()` and `pools()`. Group methods take plain arguments; changes return `void`, reads return the app's DTOs (`NodeStatus`, `QuorumCount`, `SourceReport`, `MigrationProgress`, `ArchiveStatus`) or `Engines\Data` objects. `AbstractEngineDriver`'s `accounts()`, `archive()`, `migration()` and `pools()` throw `EngineUnsupported` ("this engine does not implement \"<command>\"").
- **Facts.** `EngineFacts` (features, password encryptions, reserved users and databases, ports, host profile) are static per driver class and line. `Database::facts()` and `Database::offers(Feature)` read them; the `engine capabilities` command and the `engine_capabilities` column are gone.
- **Scripts.** A driver runs Blade scripts by their Laravel view name (`engines.<engine>.<group>.<action>`, or `<namespace>::…` for a plugin's own views) with `run()` (a change), `read()` (one JSON object) and `task()` (a long step under a caller-chosen key). A change script prints a plain sentence, which the driver writes to a server log named after the script (`server-01: create user`); a failure calls `databasify_fail <code> <message>`, which becomes the typed exception. A read script ends with `result '<json>'`, whose `DATABASIFY_RESULT` marker keeps tool output apart from the reply; reads leave no log.
- **Files.** Text config files are Blade views under `resources/views/engines/<engine>/files/`, written with `{!! !!}` through each format's quoting helper. The process manifest (`processes.json`) and `mongod.conf` stay PHP arrays encoded by `IndentedJson`.
- **Jobs.** Backups, the MySQL family binlog stream and ship, the MariaDB cluster watch and the migration copies are Blade-rendered bash scripts that `configure` writes to `/srv/<engine>/conf/<job>.sh` (mode `0700`); the manifest runs `bash <job>.sh` with storage and secrets in the entry's `env`. Nothing calls the Go `engine-task` command.
- **Runtime.** `App\Engines\Runtime\Runtime` is the only class that builds a `| databasify ` command line: `runtime run`, the task, file, log and `engine info` commands, `host deploy` (with the driver's host profile) and `host remove`. A deploy refuses an image whose engine label is not the database's engine.
- **SDK range.** The app accepts `engine-sdk` `^0.2.2 || ^1.0` ([0031](0031-one-sdk-range-per-app-release.md)); images built on an older SDK run read-only ([0032](0032-databases-on-a-dropped-sdk-run-read-only.md)).

## Consequences

- An engine behaviour change is an app change: a script, a view or a driver method, tested with `fakeScripts()`/`localScripts()` and, for jobs, local bash runs.
- Flow and action tests bind a recording driver for the built-in engines (`useRecordingDrivers()`, `recordedCalls()`, `answerRead()`) instead of answering Go commands.
- A plugin engine registers a driver class; a plugin on the Sentinel family can extend `SentinelDriver` with its own `SentinelFamilyEngine` flavour.
- Server logs name the script that ran, not a hand-written step title; host commands (`host deploy`, `host remove`) write no server log.
- `EngineModuleTest` fails when any file under `app/` other than `Runtime.php` builds a `| databasify ` command, or when `app/` or the engine views call `engine-task`, `operation-run` or `databasify engine capabilities`.

## Alternatives considered

- **Keep the commits of `feat/engine-sdk-1`:** rejected by the owner; that branch also carried an operations refactor with too many actions and jobs.
- **Keep `EngineClient` and route per command:** rejected; two paths per call is what this change removes.
- **Response classes per command:** rejected; change scripts print sentences and reads parse into the app's own DTOs.
- **Drop the `DATABASIFY_RESULT` marker:** kept for now; read scripts can print tool output before their JSON and the marker is the only separator that has run live.

## Evidence

- `app/Engines/Contracts/EngineDriver.php`
- `app/Engines/Drivers/AbstractEngineDriver.php`
- `app/Engines/Runtime/Runtime.php`
- `app/Engines/Data/EngineFacts.php`
- `app/Models/Database.php`
- `app/Models/DatabaseNode.php`
- `app/Engines/Drivers/Postgres/PostgresDriver.php`
- `app/Engines/Drivers/MySqlFamily/MySqlFamilyDriver.php`
- `app/Engines/Drivers/MongoDb/MongoDbDriver.php`
- `app/Engines/Drivers/Sentinel/SentinelDriver.php`
- `app/Engines/Drivers/Support/IndentedJson.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `resources/views/engines/`
- `config/databasify.php`
- `tests/Support/RecordingDriver.php`
- `tests/Feature/Engines/EngineModuleTest.php`
