---
name: adding-database-engine
description: 'Add a new database engine (or a new version line of one) to Databasify end to end: the engine image in the sibling engines repository, then the app side — engine registration, driver, config fields, tuner, Blade step scripts, accounts, point-in-time recovery, migrations, DNS records, frontend icon, tests — and finally a live e2e run on three provider servers. Use when the user asks to add, support, port or integrate an engine such as ClickHouse, KeyDB or a new PostgreSQL/MySQL/MongoDB major, or to add a version line to an existing engine. Do not use for fixing a single script of an existing engine or for routine image pin bumps (the weekly refresh workflow handles those).'
license: Apache-2.0
metadata:
    author: databasify
---

# Adding a database engine

A Databasify engine is two halves that ship separately:

1. **The image** — `../engines` (repo `databasifylabs/engines`): a thin extension of the official image with the `databasify` Go wrapper as PID 1. Published to `ghcr.io/databasifylabs/databasify-<engine>` from `main`. The app never reads that repo.
2. **The app** — this repo: a `RegisterDatabaseProvider` registration and a driver implementing `App\DatabaseProviders\Contracts\DatabaseDriver` (plus group subclasses from `app/DatabaseProviders/Groups/` where the engine differs), Blade config files and Blade bash scripts under `resources/views/ssh/engines/<directory>/` (the shared base) with one folder per version line, `<directory>/lines/<key>/`, that run inside the container through `docker exec … databasify step <directory>.lines.<key>.<step>`.

Laravel over SSH is the only control plane. Nothing on the node orchestrates by itself except what the engine already does (Patroni, Sentinel, Galera, replica-set elections).

## Order of work

Do the phases in order, commit per phase in each repo, and never push either repo unless the user says so — pushing `../engines` publishes image digests. Read the rule file for a phase before starting it, and [rules/lessons.md](rules/lessons.md) before the e2e.

| Phase | What                                                                            | Rules                                          |
| ----- | ------------------------------------------------------------------------------- | ---------------------------------------------- |
| 1     | Image in `../engines`, built, smoke-tested and scanned like CI                  | [rules/image.md](rules/image.md)               |
| 2     | Registration and version lines, driver (fields, tuning), config file, icon      | [rules/driver.md](rules/driver.md)             |
| 3     | Step scripts, their callers and output contracts                                | [rules/scripts.md](rules/scripts.md)           |
| 4     | Capabilities: accounts, point-in-time recovery, migrations, DNS service records | [rules/capabilities.md](rules/capabilities.md) |
| 5     | Pest tests, PHPStan, Pint, frontend checks, then a live e2e on three servers    | [rules/testing.md](rules/testing.md)           |

## Use the closest engine as the template

Pick the existing engine whose replication model matches and copy its shape, not its text:

| New engine replicates like                                              | Template                                                                       |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Built-in elections, the engine picks the primary (replica sets, Galera) | `Mongodb` + `engines/mongodb/` (non-SQL), `Mariadb` + `engines/mariadb/` (SQL) |
| External HA manager plus a DCS (Patroni + etcd)                         | `Postgres` + `engines/postgres/`                                               |
| Async replicas watched by a sentinel process                            | `Valkey`, `Redis` (on `AbstractSentinelDriver`) + `engines/valkey/`, `engines/redis/` |
| Group replication with a shell (InnoDB Cluster)                         | `Mysql` + `engines/mysql/`                                                     |

## Non-negotiables

- Every node of a database runs the exact same image: the database stores `image_repository`, `image_digest` and `image_release` on create (`ImageResolver` picks the newest exact `<line>.x` tag), and `ContainerSpec` deploys every node from `$database->image()`. Upgrades roll node by node through `RollOutNodes` and store the new image only after the rollout succeeds.
- Data lives under `/srv/<engine>` (bind-mounted at the same path). The engine user is UID/GID **64999** in the image.
- Scripts never use `apt`, `dnf`, `systemctl`, `journalctl`, `systemd-run` or `runuser`; process control is `databasify ctl …`. `NativeToolsGateTest` enforces this.
- Script names are their path inside the database's version line: `scriptView('cluster.failover')` resolves `ssh.engines.<directory>.lines.<key>.cluster.failover` (`<key>` is the line with `.` → `_`, e.g. `8_4`). There is no fallback to the base file: a missing line file throws `MissingEngineScript`, so every view of the base tree needs a file in every line folder (an `@include` of the base view, or a full copy where the line differs) and every step the engine lacks an explicit no-op override in its group subclass. See `docs/adr/0029-engine-scripts-per-version-line.md`.
- The user-editable settings mirror what DigitalOcean offers for that engine — no more, no fewer. Tuned values (memory, connections) stay automatic.
- Follow `~/.claude/CLAUDE.md` and `AGENTS.md`: Action pattern, final readonly DTOs with named constructors, collections first, early returns, no comments except PHPStan types, PHPStan max with no ignores.
- Drive e2e only through the app's actions; never repair a server by hand. Creating public DNS records, ordering or destroying servers and pushing images each need the user's go-ahead.
