# Phase 1: the engine driver (this repo)

All engine logic lives in the app as a PHP driver and hardened Blade scripts (ADR 0034, 0035). Before writing any, read:
- `docs/adr/0035-engine-api.md` (the contract, scripts, files, jobs and the runtime);
- the closest built-in driver under `app/Engines/Drivers/` and its scripts under `resources/views/engines/`;
- `.claude` memories and `rules/lessons.md` for traps that only showed live.

## Files

| Path                                                   | Purpose                                                                                                                                                                                            |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Engines/Drivers/<Engine>/<Engine>Driver.php`      | `final readonly class <Engine>Driver extends AbstractEngineDriver`. A static `facts(string $line): EngineFacts` and one method per group it offers, each building the group with `$this`.            |
| `app/Engines/Drivers/<Engine>/<Engine><Group>.php`     | One class per group (`Nodes`, `Cluster`, `Config`, and when offered `Accounts`, `Archive`, `Migration`, `Pools`), implementing `App\Engines\Contracts\<Group>`, taking the driver in its constructor. |
| `app/Engines/Drivers/<Engine>/<Engine>Configuration.php` | Shared configure logic: the saved request (`state/node.json`), tuning, the config files and the manifest.                                                                                          |
| `app/Engines/Drivers/<Engine>/<Engine>Settings.php`    | The settings catalog (`SettingsCatalog` of `Setting::integer`, `choice`, `text`, `restart:`, `tuned:`). Offer exactly what DigitalOcean offers.                                                     |
| `resources/views/engines/<engine>/<group>/<action>.blade.php` | One hardened bash script per action. Shared helpers are `_partials`.                                                                                                                       |
| `resources/views/engines/<engine>/files/<file>.blade.php` | Text config files, values written with `{!! !!}` through the file format's quoting.                                                                                                              |
| `resources/views/engines/<engine>/jobs/<job>.blade.php` | Scheduled or long-running jobs (backups, binlog or WAL shipping, migration copies, watchers), written by `configure` to `/srv/<engine>/conf/<job>.sh` with mode `0700`.                          |

## Driver and groups

- **Facts are static:** features (`Feature::Accounts`, `Archive`, `Migration`, `Pools`), password encryptions and the default, reserved users and databases, ports (client, pool, peers) and the host profile (sysctl settings, floors, transparent hugepages). `Database::facts()` and `offers()` read them; a feature the driver does not list is hidden in the app.
- **Nodes, Cluster and Config are required.** The flows call them on every engine. `nodes()->ready()`, `cluster()->enable()`, `cluster()->rejoin()` and `archive()->restore()` start tasks with `$this->driver->task($key, …)` and report phases with `phase '<text>'` lines; the flow polls them.
- **Optional groups** are left to `AbstractEngineDriver`, which throws `EngineUnsupported`. A method a group cannot do throws `EngineUnsupported::command('<command>')`.
- **Calls:** `$this->driver->run('<engine>/<group>/<action>', $data, $env)` for a change, `read()` for one JSON object, `task()` for a long step. Values reach scripts only in `$env` (never interpolated) or through the view's quoting helpers.
- Every action is safe to repeat: a flow retries a lost task under a new key, and a crashed worker resends the step.
- Clusters manage their own writer: the engine's HA mechanism picks and fences the primary; `cluster()->failover()` and `cluster()->forceQuorum()` ask it to.

## Scripts

- Start with the engine's `_partial` that includes `engines._runtime`. Scripts run as `bash -euo pipefail -s` inside the container, with only the env the driver passes (plus `DATABASIFY_ENGINE`, `DATABASIFY_LINE`, `HOME`).
- A **change** script prints one plain sentence ("Created user app.") and calls `databasify_fail <code> <message>` on failure (`not_primary`, `invalid_request`, `not_found`, `already_exists`, `busy`, `unavailable`, `internal`). The driver logs the sentence under the script's name.
- A **read** script ends with `result '<json object>'`; tool output before it is ignored.
- Run engine services with `databasify ctl` (`unit_do`, `unit_active`), never `systemctl` or `service`.

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

Driver and script tests use `fakeScripts()` (records each script with its env and answers reads) and `localScripts()` (runs the real script against stubbed clients on this Mac's bash). Jobs are run locally in `tests/Feature/Engines/EngineJobsTest.php`. Every script must render to valid bash (`bashErrorsIn()`).
