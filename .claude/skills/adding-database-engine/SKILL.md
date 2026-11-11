---
name: adding-database-engine
description: 'Add a new database engine (or a new version line of one) to Databasify end to end: the engine package in the Go wrapper and its image in the sibling engines repository, then the app side (catalog registration, connection profile, migration source, icon, tests), and finally a live e2e run on three provider servers. Use when the user asks to add, support, port or integrate an engine such as ClickHouse, KeyDB or a new PostgreSQL/MySQL/MongoDB major, or to add a version line to an existing engine. Do not use for fixing one command of an existing engine or for routine image pin bumps (the weekly refresh workflow handles those).'
license: Apache-2.0
metadata:
    author: databasify
---

# Adding a database engine

A Databasify engine is two halves that ship separately:

1. **The engine** in `../engines` (repo `databasifylabs/engines`): a Go package in the `databasify` wrapper that implements `contracts.Engine`, plus an image that is the official engine image with that wrapper as PID 1, published as `ghcr.io/databasifylabs/databasify-<engine>`. All engine logic lives there: config files, tuning, settings, processes, HA, accounts, backups, migrations. The engines repo has its own image skill, `.claude/skills/adding-engine-image`.
2. **The app** in this repo: one `RegisterEngine` entry in `app/Providers/EngineServiceProvider.php` (label, category, lines, connection profile, migration source, flags) and an icon. The app drives every engine with the same typed commands and flows (`docs/adr/0035-engine-commands-and-flows.md`); it has no per-engine driver, scripts or flows.

What a database can do comes from the engine at runtime: `engine capabilities` (commands, password encryptions, reserved names, ports) and `config fields` are read from the seed after its first deploy and stored on the database. A command the engine doesn't offer answers `unsupported`, and the app hides that feature.

## Order of work

Do the phases in order, commit per phase in each repo, and never push either repo unless the user says so. Pushing `../engines` publishes image digests and moves the shared wrapper tag. Read the rule file for a phase before starting it, and [rules/lessons.md](rules/lessons.md) before the e2e.

| Phase | What                                                                       | Rules                                          |
| ----- | -------------------------------------------------------------------------- | ---------------------------------------------- |
| 1     | Engine package in `../engines`: groups, settings, processes, Go tests      | [rules/engine.md](rules/engine.md)             |
| 2     | Image in `../engines`, built, smoke-tested with conformance, scanned       | [rules/image.md](rules/image.md)               |
| 3     | App registration: catalog entry, connection profile, icon                  | [rules/registration.md](rules/registration.md) |
| 4     | Pest tests, PHPStan, Pint, frontend checks, then a live e2e on three servers | [rules/testing.md](rules/testing.md)         |

A new **version line** of an existing engine is phase 2 (a `versions.json` entry), any line-specific branch in the engine package (`DATABASIFY_LINE`), and one more line in the app's `versions()` registration.

## Use the closest engine as the template

Pick the engine package whose replication model matches and copy its shape, not its text:

| New engine replicates like                                              | Template in `../engines/internal/engines/`                      |
| ----------------------------------------------------------------------- | --------------------------------------------------------------- |
| Built-in elections, the engine picks the primary (replica sets, Galera) | `mongodb/` (non-SQL), `mariadb/` (SQL, on `mysqlfamily/`)       |
| External HA manager plus a DCS (Patroni + etcd)                         | `postgres/`                                                     |
| Async replicas watched by a sentinel process                            | `sentinel/` (Valkey and Redis are two flavors of one package)   |
| Group replication with a shell (InnoDB Cluster)                         | `mysql/` (on `mysqlfamily/`)                                    |

## Non-negotiables

- Every node of a database runs the exact same image: the database stores `image_repository`, `image_digest` and `image_release` on create (`ImageResolver` picks the newest exact `<line>.x` tag), and `host deploy` runs every node from that digest. Upgrades roll node by node and store the new image only after the rollout succeeds.
- The image's `io.databasify.engine` label names the container (`databasify-<engine>`) and its data root (`/srv/<engine>`, bind-mounted at the same path). The engine user is UID/GID **64999**.
- The engine's code must land in `databasifylabs/engines` itself. The app installs one pinned wrapper on every node (`host wrapper-update`, following the digest of `databasify-wrapper:<version>`), so engine code kept only in a fork or a private image is replaced at the next wrapper update.
- Every feature is its own typed command; there is no raw SQL or script endpoint. New request or reply fields go in `internal/requests` and `internal/responses` in the engines repo and are mirrored name for name in `app/Engines/Requests`, `app/Engines/Responses` and `app/Engines/Data`.
- The user-editable settings mirror what DigitalOcean offers for that engine, no more and no fewer. Tuned values (memory, connections) stay automatic.
- Follow `~/.claude/CLAUDE.md` and `AGENTS.md`: Action pattern, final readonly DTOs with named constructors, collections first, early returns, no comments except PHPStan types, PHPStan max with no ignores.
- Drive e2e only through the app's actions; never repair a server by hand. Creating public DNS records, ordering or destroying servers and pushing images each need the user's go-ahead.
