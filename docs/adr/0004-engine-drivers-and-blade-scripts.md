# 0004. The app drives engines through PHP drivers and hardened Blade scripts

- Status: Accepted
- Date: 2026-11-15

## Context

With a frozen runtime in the images ([0003](0003-engine-images-and-frozen-runtime.md)), all engine behaviour lives in the app. It must be readable by Laravel developers, safe against injection and secret leaks, and open to engines that plugins add. Plugins run arbitrary code, so an admin must know who stands behind a plugin engine before its code runs.

## Decision

- **One driver per engine.** `App\Engines\Contracts\EngineDriver` has a static `facts(string $line)` and seven groups: `nodes()`, `cluster()`, `config()`, `accounts()`, `archive()`, `migration()` and `pools()`. Changes return `void`; reads return the app's DTOs. Optional groups throw `EngineUnsupported` in `AbstractEngineDriver`. `$database->driver()` targets the primary (and runs once more on the new primary after a `not_primary` reply); `$node->driver()` targets one node. Drivers resolve through the container, so tests bind a recording stand-in.
- **Facts are static.** `EngineFacts` (features, password encryptions, reserved names, ports, host profile, cluster order) belong to the driver class and version line. `EngineCatalog` holds only registration data: label, icon, category, image repository, version lines, connection profile, migration source form, flags and the driver class.
- **Flat scripts, decisions in PHP.** A driver runs Blade views named `engines.<engine>.<group>.<action>`. The driver decides: what a step needs comes from the app's records, and scripts do not re-check what those records or the HTTP layer already settled. A script runs top to bottom without branching that PHP can decide.
- **Query views and one runner per engine.** For the MySQL family, PostgreSQL and MongoDB, a query view holds only SQL, or for MongoDB only the JavaScript body. `query()` (a change, logged to a server log named after the view) and `queryJson()` (one JSON value) render it, base64-encode it and pipe it through the engine's runner (`mysql-family/_query`, `postgres/_query`, `mongodb/_query`). The runner keeps the client call, login, timeout and the mapping of client errors to typed errors. The MySQL runner removes `NO_BACKSLASH_ESCAPES` from the session so backslash quoting holds; the PostgreSQL runner turns statement logging off and sets `standard_conforming_strings` on every connection, so a query view may `\connect` to other databases and secrets never reach the server log.
- **Bash views.** Waits, files, units, jobs and statements that must tolerate a server error (revoking a grant that may not exist) are short bash views run through `run()`, `read()` (one JSON object after a `DATABASIFY_RESULT` marker, no log) or `task()` (a long step under a key, [0005](0005-operations-as-one-job.md)). Waits stay on the server in one `wait_until` line, so a dropped SSH connection never leaves a step half done. Redis and Valkey (the Sentinel family) have only bash views: `redis-cli` reading commands from stdin keeps going after an error and exits 0, so their scripts call `cli` once per command, which fails on the first error reply, and the admin password reaches the client only through `REDISCLI_AUTH`.
- **Values and state.** Values are rendered with the view's quoting helpers (`$sql->string()`, `$sql->name()`, `$sh->arg()`, `@json`), never argv. `$sql` is the driver's `SqlQuoting` (`sqlQuoting()`), so a plugin SQL engine ships its own quoting class. Env carries only secrets for tasks and migration sources, and configuration a tool reads from its environment (rclone, pgBackRest). Scripts the app runs get saved node state rendered from the driver's `savedRequestOf()`, not `jq` on `state/node.json`; background jobs run without the app and keep reading that file. A failure calls `databasify_fail <code> <message>`, which becomes a typed exception.
- **Files and jobs.** Config files are Blade views under `resources/views/engines/<engine>/files/`, quoted per format; the process manifest and `mongod.conf` are PHP arrays encoded by `IndentedJson`. Backups, binlog streaming and shipping, the MariaDB cluster watch and migration copies are scripts written to `/srv/<engine>/conf/<job>.sh` and run by the manifest.
- **One command builder.** `App\Engines\Runtime\Runtime` is the only class that builds a `| databasify ` command line; `EngineModuleTest` fails otherwise.
- **Plugin engines are community engines.** An engine registered while a plugin loads is stamped with the plugin's id and author (`Engine::isCommunity()`), must be declared in the plugin's `composer.json` under `extra.databasify.engines`, and is shown with a "Community" badge. Installing a plugin that declares engines is refused until the admin accepts the warning, checked from the staged `composer.json` before any plugin code runs. An update that drops an engine a database still uses is refused.

## Consequences

- An engine behaviour change is an app change: a view or a driver method. Actions are tested with recording drivers; scripts are checked on live servers.
- A contributor reads one view to see what a command does, without learning a bash library first.
- Server logs name the script that ran.
- A plugin engine registers a driver class and may extend a built-in family driver.
- Plugin authors must keep `extra.databasify.engines` in step with what they register.

## Alternatives considered

- **Typed Go commands per operation through an engine client:** removed, two paths for every call hid which code really ran.
- **Unhardened Blade scripts over SSH:** rejected, values were not escaped and secrets appeared in argv.
- **Logic in bash helper libraries (pre-0.2.0 scripts):** replaced, contributors had to learn a large bash library before changing one command.
- **Detecting plugin engines by loading the plugin first:** rejected, the warning must come before any plugin code runs.

## Evidence

- `app/Engines/Contracts/EngineDriver.php`
- `app/Engines/Drivers/AbstractEngineDriver.php`
- `app/Engines/Data/EngineFacts.php`
- `app/Engines/Contracts/SqlQuoting.php`
- `app/Engines/EngineCatalog.php`
- `app/Engines/Runtime/Runtime.php`
- `app/Engines/Scripts/ScriptView.php`
- `app/Engines/Scripts/ScriptResult.php`
- `resources/views/engines/mysql-family/_query.blade.php`
- `resources/views/engines/postgres/_query.blade.php`
- `resources/views/engines/mongodb/_query.blade.php`
- `resources/views/engines/sentinel/_cli.blade.php`
- `app/Engines/Drivers/Support/IndentedJson.php`
- `resources/views/engines/`
- `app/Providers/EngineServiceProvider.php`
- `app/Services/Plugins/PluginLoader.php`
- `app/Actions/Plugins/ConfirmCommunityEngines.php`
- `tests/Support/RecordingDriver.php`
- `tests/Feature/Engines/EngineModuleTest.php`
