---
name: adding-database-engine
description: 'Add a new database engine (or a new version line of one) to Databasify end to end: the engine''s images in its own repository on the public engine SDK runtime (databasifylabs/engine-<name>, started from databasifylabs/engine-template), then the app side (the PHP driver with its Blade scripts, files and jobs, catalog registration, connection profile, migration source, icon, tests), and finally a live e2e run on three provider servers. Use when the user asks to add, support, port or integrate an engine such as ClickHouse, KeyDB or a new PostgreSQL/MySQL/MongoDB major, or to add a version line to an existing engine. Do not use for fixing one command of an existing engine or for routine image pin bumps (the weekly refresh workflow handles those).'
license: Apache-2.0
metadata:
    author: databasify
---

# Adding a database engine

A Databasify engine is two halves that ship separately:

1. **The image** in its own repository, `databasifylabs/engine-<engine>`.
   - A new one is created from [`databasifylabs/engine-template`](https://github.com/databasifylabs/engine-template); the six built-in engines are [`engine-postgres`](https://github.com/databasifylabs/engine-postgres), `engine-mysql`, `engine-mariadb`, `engine-mongodb`, `engine-valkey` and `engine-redis`.
   - Its images are the official engine image plus the engine's tools and the frozen [engine SDK](https://github.com/databasifylabs/engine-sdk) runtime as PID 1 (ADR 0034), published as `ghcr.io/databasifylabs/databasify-<engine>`. They carry no engine logic.
   - The image tools (`tools/images.sh`) live in a clone of `engine-sdk` at the version the image pins, and the engine-template README is the public guide to a published image.
2. **The app** in this repo: the engine's PHP driver and Blade scripts (`app/Engines/Drivers/<Engine>/`, `resources/views/engines/<engine>/`), one `RegisterEngine` entry in `app/Providers/EngineServiceProvider.php` (label, category, lines, connection profile, migration source, flags, `->driver(...)`) and an icon. All engine logic lives here: config files, tuning, settings, processes, HA, accounts, backups, migrations (ADR 0035).

What a database can do comes from the driver's static `facts()` (features, password encryptions, reserved names, ports, host profile); a feature the driver does not offer is hidden in the app.

## How a contribution lands

- **Open an issue first.** New engines and version lines need an agreed approach before code (`CONTRIBUTING.md`).
- **One pull request per repository.** Branch from `main` in the engine repository (and in `engine-sdk` when the contract changes) and from `0.x` here, commit per phase, and open each pull request against that branch. Never push to `main` or `0.x` yourself.
- **The engine pull request lands first.** The app can only create databases from published images, and only a merge to the engine repository's `main` publishes release tags (`<line>.x`). An `engine-sdk` change lands and is tagged before the engine that needs it. Link those pull requests from the app's.
- **Say how you tested.** Name the engine, line and OS, which checks ran, and whether the live e2e ran.

## Order of work

Do the phases in order. Read the rule file for a phase before starting it, and [rules/lessons.md](rules/lessons.md) before the e2e.

| Phase | What                                                                       | Rules                                          |
| ----- | -------------------------------------------------------------------------- | ---------------------------------------------- |
| 1     | Engine driver in this repo: facts, groups, settings, Blade scripts, files and jobs | [rules/engine.md](rules/engine.md)             |
| 2     | Images in the engine repository: official image, tools and the SDK runtime, built and scanned | [rules/image.md](rules/image.md)               |
| 3     | App registration: catalog entry, connection profile, icon                  | [rules/registration.md](rules/registration.md) |
| 4     | Pest tests, PHPStan, Pint, frontend checks, then a live e2e on three servers | [rules/testing.md](rules/testing.md)         |

A new **version line** of an existing engine is phase 2 (a `versions.json` entry), any line-specific branch in the driver or its scripts (`$line` in views, `DATABASIFY_LINE` in scripts), and one more line in the app's `versions()` registration.

## Use the closest engine as the template

Pick the driver whose replication model matches and copy its shape, not its text:

| New engine replicates like                                              | Template driver                                                  |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------- |
| Built-in elections, the engine picks the primary (replica sets, Galera) | `MongoDb` (non-SQL), `MariaDb` (SQL, on `MySqlFamily`)           |
| External HA manager plus a DCS (Patroni + etcd)                         | `Postgres`                                                       |
| Async replicas watched by a sentinel process                            | `Sentinel` (`Valkey` and `Redis` are two flavours of one driver) |
| Group replication with a shell (InnoDB Cluster)                         | `MySql` (on `MySqlFamily`)                                       |

## Non-negotiables

- Every node of a database runs the exact same image: the database stores it in its `image` column (`EngineImage`: repository, digest, release, SDK) on create (`ImageResolver` picks the newest exact `<line>.x` tag), and `host deploy` runs every node from that digest. Upgrades roll node by node and store the new image only after the rollout succeeds.
- The image's `io.databasify.engine` label names the container (`databasify-<engine>`) and its data root (`/srv/<engine>`, bind-mounted at the same path). The engine user is UID/GID **64999**.
- Updates ship only as images. The binary inside the image is the one that runs, and the app moves each database to the newest image of its line in its weekly maintenance window. Every image carries `io.databasify.sdk`, and the app refuses images built on an SDK outside `databasify.require.engine-sdk` (ADR 0031); databases on a dropped SDK run read-only (ADR 0032). On the server, only the official host binary that the app pins runs (`databasify.sdk.host`).
- Every feature is a driver group method backed by one reviewed Blade script; values reach scripts only through env or the view's quoting helpers, never in argv. Only `App\Engines\Runtime\Runtime` builds `databasify` command lines (`EngineModuleTest` enforces it).
- The user-editable settings mirror what DigitalOcean offers for that engine, no more and no fewer. Tuned values (memory, connections) stay automatic.
- Follow `~/.claude/CLAUDE.md` and `AGENTS.md`: Action pattern, final readonly DTOs with named constructors, collections first, early returns, no comments except PHPStan types, PHPStan max with no ignores.
- Drive e2e only through the app's actions; never repair a server by hand. Use your own provider account and domain for it, destroy test servers afterwards, and get the go-ahead of whoever runs the session before creating public DNS records or ordering or destroying servers.
