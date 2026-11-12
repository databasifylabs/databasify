---
name: adding-database-engine
description: 'Add a new database engine (or a new version line of one) to Databasify end to end: the engine''s Go package and images in its own repository on the public engine SDK (databasifylabs/engine-<name>, started from databasifylabs/engine-template), then the app side (catalog registration, connection profile, migration source, icon, tests), and finally a live e2e run on three provider servers. Use when the user asks to add, support, port or integrate an engine such as ClickHouse, KeyDB or a new PostgreSQL/MySQL/MongoDB major, or to add a version line to an existing engine. Do not use for fixing one command of an existing engine or for routine image pin bumps (the weekly refresh workflow handles those).'
license: Apache-2.0
metadata:
    author: databasify
---

# Adding a database engine

A Databasify engine is two halves that ship separately:

1. **The engine** in its own repository, `databasifylabs/engine-<engine>`.
   - A new one is created from [`databasifylabs/engine-template`](https://github.com/databasifylabs/engine-template); the six built-in engines are [`engine-postgres`](https://github.com/databasifylabs/engine-postgres), `engine-mysql`, `engine-mariadb`, `engine-mongodb`, `engine-valkey` and `engine-redis`.
   - It holds a Go package implementing the public [engine SDK](https://github.com/databasifylabs/engine-sdk)'s `contract.Engine`, and `cmd/databasify/main.go` calling `sdk.Run(<engine>.New())`.
   - Its images are the official engine image with that binary as PID 1, published as `ghcr.io/databasifylabs/databasify-<engine>`.
   - All engine logic lives there: config files, tuning, settings, processes, HA, accounts, backups, migrations.
   - Clone it on its own. The rules for phases 1 and 2 name paths from the root of that clone. The image tools (`tools/images.sh`) live in a clone of [`databasifylabs/engine-sdk`](https://github.com/databasifylabs/engine-sdk) at the version the engine's `go.mod` requires, and the [engine-template README](https://github.com/databasifylabs/engine-template) is the public guide from the first commit to a published image.
   - A change to the command contract itself (a new command, request or reply field) is a pull request to `engine-sdk`.
2. **The app** in this repo: one `RegisterEngine` entry in `app/Providers/EngineServiceProvider.php` (label, category, lines, connection profile, migration source, flags) and an icon. The app drives every engine with the same typed commands and flows (`docs/adr/0028-engine-commands-and-flows.md`); it has no per-engine driver, scripts or flows.

What a database can do comes from the engine at runtime: `engine capabilities` (commands, password encryptions, reserved names, ports) and `config fields` are read from the seed after its first deploy and stored on the database. A command the engine doesn't offer answers `unsupported`, and the app hides that feature.

## How a contribution lands

- **Open an issue first.** New engines and version lines need an agreed approach before code (`CONTRIBUTING.md`).
- **One pull request per repository.** Branch from `main` in the engine repository (and in `engine-sdk` when the contract changes) and from `0.x` here, commit per phase, and open each pull request against that branch. Never push to `main` or `0.x` yourself.
- **The engine pull request lands first.** The app can only create databases from published images, and only a merge to the engine repository's `main` publishes release tags (`<line>.x`). An `engine-sdk` change lands and is tagged before the engine that needs it. Link those pull requests from the app's.
- **Say how you tested.** Name the engine, line and OS, which checks ran, and whether the live e2e ran.

## Order of work

Do the phases in order. Read the rule file for a phase before starting it, and [rules/lessons.md](rules/lessons.md) before the e2e.

| Phase | What                                                                       | Rules                                          |
| ----- | -------------------------------------------------------------------------- | ---------------------------------------------- |
| 1     | Engine package in the engine repository: groups, settings, processes, Go tests | [rules/engine.md](rules/engine.md)             |
| 2     | Images in the engine repository, built, smoke-tested with conformance, scanned | [rules/image.md](rules/image.md)               |
| 3     | App registration: catalog entry, connection profile, icon                  | [rules/registration.md](rules/registration.md) |
| 4     | Pest tests, PHPStan, Pint, frontend checks, then a live e2e on three servers | [rules/testing.md](rules/testing.md)         |

A new **version line** of an existing engine is phase 2 (a `versions.json` entry), any line-specific branch in the engine package (`DATABASIFY_LINE`), and one more line in the app's `versions()` registration.

## Use the closest engine as the template

Pick the engine package whose replication model matches and copy its shape, not its text:

| New engine replicates like                                              | Template repository and package                                 |
| ----------------------------------------------------------------------- | --------------------------------------------------------------- |
| Built-in elections, the engine picks the primary (replica sets, Galera) | `engine-mongodb` (non-SQL), `engine-mariadb` (SQL, on `engine-mysql-family`) |
| External HA manager plus a DCS (Patroni + etcd)                         | `engine-postgres`                                               |
| Async replicas watched by a sentinel process                            | `engine-sentinel-family` (Valkey and Redis are two flavors of one package) |
| Group replication with a shell (InnoDB Cluster)                         | `engine-mysql` (on `engine-mysql-family`)                       |

## Non-negotiables

- Every node of a database runs the exact same image: the database stores it in its `image` column (`EngineImage`: repository, digest, release, SDK) on create (`ImageResolver` picks the newest exact `<line>.x` tag), and `host deploy` runs every node from that digest. Upgrades roll node by node and store the new image only after the rollout succeeds.
- The image's `io.databasify.engine` label names the container (`databasify-<engine>`) and its data root (`/srv/<engine>`, bind-mounted at the same path). The engine user is UID/GID **64999**.
- Updates ship only as images. The binary inside the image is the one that runs, and the app moves each database to the newest image of its line in its weekly maintenance window. Every image carries `io.databasify.sdk`, and the app refuses images built on an SDK outside `databasify.sdk.range` (ADR 0031); databases on a dropped SDK run read-only (ADR 0032). On the server, only the official host binary that the app pins runs (`databasify.sdk.host`).
- Every feature is its own typed command; there is no raw SQL or script endpoint. New request or reply fields go in the SDK's `requests` and `responses` packages (an `engine-sdk` pull request) and are mirrored name for name in `app/Engines/Requests`, `app/Engines/Responses` and `app/Engines/Data`.
- The user-editable settings mirror what DigitalOcean offers for that engine, no more and no fewer. Tuned values (memory, connections) stay automatic.
- Follow `~/.claude/CLAUDE.md` and `AGENTS.md`: Action pattern, final readonly DTOs with named constructors, collections first, early returns, no comments except PHPStan types, PHPStan max with no ignores.
- Drive e2e only through the app's actions; never repair a server by hand. Use your own provider account and domain for it, destroy test servers afterwards, and get the go-ahead of whoever runs the session before creating public DNS records or ordering or destroying servers.
