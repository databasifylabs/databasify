# 0021. App state in SQLite, queues in Valkey

- Status: Accepted
- Date: 2026-11-04

## Context

The app installs on one small server and must be easy to back up. It still needs a queue that Horizon can run across many workers.

## Decision

- SQLite is the system of record. The installed app keeps the cache, sessions and locks in Valkey ([0034](0034-cache-and-sessions-in-valkey.md)); development keeps them in SQLite (`CACHE_STORE=database`, `SESSION_DRIVER=database`).
- SQLite runs in WAL mode with a busy timeout (`journal_mode=wal`, `busy_timeout=5000`, `synchronous=normal`, overridable with `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`), so parallel workers wait for the write lock instead of failing with "database is locked". Production may run the app on MySQL or PostgreSQL instead.
- Valkey, with append-only persistence, holds the queues and Horizon, and in the installed app the cache, sessions and locks ([0034](0034-cache-and-sessions-in-valkey.md)). Its `retry_after` is above the longest step timeout.
- The installer generates the app key and Reverb secrets into `/opt/databasify/.env` ([0033](0033-app-settings-in-env-file.md)), and migrations run on every web start.

## Consequences

- SQLite lives at `storage/database.sqlite` (the `DB_DATABASE` default), so `storage` is the only volume the app needs.
- A backup is the storage volume plus `/opt/databasify/.env`, which holds `APP_KEY` ([0013](0013-secrets-and-ssh-keys.md), [0033](0033-app-settings-in-env-file.md)).
- Losing Valkey's data loses queued jobs and, in the installed app, sessions, cache entries and locks ([0034](0034-cache-and-sessions-in-valkey.md)).
- All Horizon workers write to one SQLite file, so write contention grows with the number of workers; moving the cache and locks to Valkey keeps those writes off it.
- The storage volume must be on a local filesystem, not NFS, because WAL needs shared memory between processes. A backup copies `database.sqlite` together with its `-wal` and `-shm` files, or is taken with the app stopped.

## Alternatives considered

- **MySQL or PostgreSQL for the app itself:** not the default, because it adds a server to install and back up.

## Evidence

- `docker/Dockerfile`
- `scripts/compose.yaml`
- `docker/entrypoint.sh`
- `config/database.php`
- `storage/.gitignore`
- `config/queue.php`
