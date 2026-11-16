# Phase 1: the engine driver (this repo)

All engine logic lives in the app as a PHP driver and flat Blade scripts (ADR 0003, 0004). The driver decides; a script only says what runs on the server. Before writing any, read:
- `docs/adr/0004-engine-drivers-and-blade-scripts.md` (the contract, scripts, files and jobs) and `docs/adr/0003-engine-images-and-frozen-runtime.md` (the runtime);
- the closest built-in driver under `app/Engines/Drivers/` and its scripts under `resources/views/engines/`;
- `.claude` memories and `rules/lessons.md` for traps that only showed live.

## Files

| Path                                                   | Purpose                                                                                                                                                                                            |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Engines/Drivers/<Engine>/<Engine>Driver.php`      | `final readonly class <Engine>Driver extends AbstractEngineDriver`. A static `facts(string $line): EngineFacts` and one method per group it offers, each building the group with `$this`.            |
| `app/Engines/Drivers/<Engine>/<Engine><Group>.php`     | One class per group (`Nodes`, `Cluster`, `Config`, and when offered `Accounts`, `Archive`, `Migration`, `Pools`), implementing `App\Engines\Contracts\<Group>`, taking the driver in its constructor. |
| `app/Engines/Drivers/<Engine>/<Engine>Configuration.php` | Shared configure logic: the saved request (`state/node.json`), tuning, the config files and the manifest.                                                                                          |
| `app/Engines/Drivers/<Engine>/<Engine>Settings.php`    | The settings catalog (`SettingsCatalog` of `Setting::integer`, `choice`, `text`, `restart:`, `tuned:`). Offer exactly what DigitalOcean offers.                                                     |
| `resources/views/engines/<engine>/<group>/<action>.blade.php` | One view per action: a query view (only SQL, or only a JavaScript body) or a short bash view. The engine's client wrapper and runner are `_partials`.                                    |
| `resources/views/engines/<engine>/files/<file>.blade.php` | Text config files, values written with `{!! !!}` through the file format's quoting.                                                                                                              |
| `resources/views/engines/<engine>/jobs/<job>.blade.php` | Scheduled or long-running jobs (backups, binlog or WAL shipping, migration copies, watchers), written by `configure` to `/srv/<engine>/conf/<job>.sh` with mode `0700`.                          |

## Driver and groups

- **Facts are static:** features (`Feature::Accounts`, `Archive`, `Migration`, `Pools`), password encryptions and the default, reserved users and databases, ports (client, pool, peers) and the host profile (sysctl settings, floors, transparent hugepages). `Database::facts()` and `offers()` read them; a feature the driver does not list is hidden in the app.
- **Nodes, Cluster and Config are required.** The flows call them on every engine. `nodes()->ready()`, `cluster()->enable()`, `cluster()->rejoin()` and `archive()->restore()` start tasks with `$this->driver->task($key, …)`; the flow polls them.
- **Optional groups** are left to `AbstractEngineDriver`, which throws `EngineUnsupported`. A method a group cannot do throws `EngineUnsupported::command('<command>')`.
- **Calls:** `$this->driver->query('engines.<engine>.<group>.<action>', $data)` runs a query view for a change and `queryJson()` for a read that returns one JSON value; `run()`, `read()` and `task()` run bash views (a change, one JSON object, a long step). A view name is a Laravel view name; a plugin loads its own views with `loadViewsFrom()` and runs `'<namespace>::<group>.<action>'`. Override `queryRunner()` to name the engine's runner view.
- **Decide in PHP.** Existence checks, grant diffs, reserved names, saved state (`savedRequestOf()`) and JSON reshaping belong in the group class. Pass the result to the view as data. Don't re-check in a script what the app's records or the HTTP layer already settled.
- Every action is safe to repeat: a flow retries a lost task under a new key, and a crashed worker resends the step.
- Clusters manage their own writer: the engine's HA mechanism picks and fences the primary; `cluster()->failover()` and `cluster()->forceQuorum()` ask it to.

## Scripts

A **query view** holds only the statement, with every value rendered through the view's quoting helpers. `mysql-family/accounts/set-password.blade.php` is the whole file:

```blade
ALTER USER {!! $sql->string($name) !!}@'%' {!! $identified !!};
```

`MySqlFamilyAccounts::setPassword()` decides the rest and runs it:

```php
$identified = $this->identifiedBy($password, $encryption);
$this->refuseReserved($this->driver->engine()->reservedUsers, $name);
$this->scripts->query($this->driver, 'engines.mysql-family.accounts.set-password', ['name' => $name, 'identified' => $identified]);
```

`query()` renders the view, base64-encodes it and pipes it through the engine's runner (`mysql-family/_query`, `postgres/_query`, `mongodb/_query`), which holds the client call, login, timeout and the mapping of client errors to `not_primary`, `already_exists`, `not_found` and `unavailable`. A new SQL or JavaScript engine adds one runner on its client partial; its views stay pure statements.

A **bash view** is for what a statement alone can't do: waits (`wait_until 60 2 <check> || databasify_fail unavailable '<message>'`, so the wait stays on the server), files, units, background jobs, and statements that must tolerate an error (`mysql-family/accounts/create-user` revokes grants that may not exist). Use one only for those, and for engines whose client can't stop on the first error from stdin (Redis and Valkey run every command through `cli`).

- Render values with `$sql->string()`, `$sql->name()`, `$sh->arg()` and `@json`; `$line` is the version line. Env carries only secrets for tasks and migration sources and configuration a tool reads from its environment (rclone). No value goes in argv.
- Scripts run as `bash -euo pipefail -s` inside the container, with only the env the driver passes plus `DATABASIFY_ENGINE`, `DATABASIFY_LINE` and `HOME`. A bash view starts with the engine's client partial, which includes `engines._runtime`.
- A **change** prints one plain sentence ("Saved the user and its grants.") and calls `databasify_fail <code> <message>` on failure (`not_primary`, `invalid_request`, `not_found`, `already_exists`, `busy`, `unavailable`, `internal`). The server log is named after the view.
- A **read** ends with `result '<json object>'` on one line; tool output before it is ignored.
- Run engine services with `databasify ctl` (`unit_do`, `unit_active`), never `systemctl` or `service`.
- Blade turns `@@` into `@`: wrap SQL system variables (`@@version`) in `@verbatim`.
- `result "$(…)"` hides a failing command under `bash -e`; assign to a variable first.

## Files, processes and jobs

- `configure` writes the config files, the job scripts and `/srv/<engine>/conf/processes.json` (a PHP array encoded with `IndentedJson`). HA processes appear only once the database has a peer; backup jobs only while point-in-time recovery is on.
- A job runs as `bash /srv/<engine>/conf/<job>.sh` with its storage and secrets in the manifest entry's `env`. No password ever goes in argv.
- Guard the server process on its data directory, so an erase or a lost disk doesn't leave it in a restart loop.
- Don't set environment the image already sets: a process's `env` overrides the container's.

## Accounts, backups and migrations

- **Accounts:** users, databases and grants (`read`, `write`) through the engine's own client. Refuse reserved names with `InvalidEngineRequest` before running anything, and never echo a password back.
- **Archive:** the backup tool's config, scheduled backups gated on the primary, `archive()->status()` with the earliest and latest restorable time, and `archive()->restore()`. When a bundled tool doesn't support a line yet, ship the line with `'recovery' => false`.
- **Migration:** `check()` returns a `SourceReport` with facts and blockers, `start()` runs the copy job in a transient unit, and `progress()` reports lag as the unapplied backlog, so an idle source reads 0.
- Read third-party tools' source for how they build connection strings before relying on query options, and check which engine versions each tool supports.

## Verify

Actions that call the driver are tested against `RecordingDriver::fake()` ([testing.md](testing.md)). Scripts are checked on live servers in the e2e run.
