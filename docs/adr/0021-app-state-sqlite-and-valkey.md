# 0021. App state in SQLite, queues in Valkey

- Status: Accepted
- Date: 2026-11-04

## Context

The app installs on one small server and must be easy to back up. It still needs a queue that Horizon can run across many workers.

## Decision

- SQLite is the system of record. It also backs the cache, sessions and locks (`CACHE_STORE=database`, `SESSION_DRIVER=database`), which includes stop signals, unique-job locks, network and firewall locks, and terminal input.
- SQLite runs in WAL mode with a busy timeout (`journal_mode=wal`, `busy_timeout=5000`, `synchronous=normal`, overridable with `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`), so parallel workers wait for the write lock instead of failing with "database is locked". Production may run the app on MySQL or PostgreSQL instead.
- Valkey, with append-only persistence, holds only the queues and Horizon. Its `retry_after` is above the longest step timeout.
- On first start the app key and Reverb secrets are generated into `storage/databasify.env`, and migrations run on every web start.

## Consequences

- SQLite lives at `storage/database.sqlite` (the `DB_DATABASE` default), so `storage` is the only volume the app needs.
- The storage volume is the whole backup, including `APP_KEY` ([0013](0013-secrets-and-ssh-keys.md)).
- Losing Valkey loses only queued jobs.
- All Horizon workers write to one SQLite file, so write contention grows with the number of workers.
- The storage volume must be on a local filesystem, not NFS, because WAL needs shared memory between processes. A backup copies `database.sqlite` together with its `-wal` and `-shm` files, or is taken with the app stopped.

## Alternatives considered

- **MySQL or PostgreSQL for the app itself:** not the default, because it adds a server to install and back up.

## Evidence

- `docker/Dockerfile`
- `docker/compose.yaml`
- `docker/entrypoint.sh`
- `config/database.php`
- `storage/.gitignore`
- `config/queue.php`
