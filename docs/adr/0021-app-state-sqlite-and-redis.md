# 0021. App state in SQLite, queues in Redis

- Status: Accepted
- Date: 2026-11-04

## Context

The app installs on one small server and must be easy to back up. It still needs a queue that Horizon can run across many workers. Some owners want to point the app at a Redis or database they already run.

## Decision

- SQLite is the system of record. The installed app keeps the cache, sessions and locks in Redis ([0034](0034-cache-and-sessions-in-redis.md)); development keeps them in SQLite (`CACHE_STORE=database`, `SESSION_DRIVER=database`).
- SQLite runs in WAL mode with a busy timeout (`journal_mode=wal`, `busy_timeout=5000`, `synchronous=normal`, overridable with `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`), so parallel workers wait for the write lock instead of failing with "database is locked". `DB_CONNECTION` and the `DB_*` settings in `/opt/databasify/.env` switch the app to MySQL or PostgreSQL.
- Redis holds the queues and Horizon, and in the installed app the cache, sessions and locks ([0034](0034-cache-and-sessions-in-redis.md)). Its `retry_after` is above the longest step timeout.
- The image bundles Redis (`redis-server` from Ubuntu, `docker/redis.conf`), run by supervisord inside the app container ([0016](0016-app-architecture.md)): bound to loopback, append-only persistence, data in `/var/lib/redis` on the `redis` volume. `scripts/env.example` sets `REDIS_HOST=127.0.0.1`.
- The bundled Redis starts only when no external Redis is set: `REDIS_URL` is empty and `REDIS_HOST` is `127.0.0.1`, `localhost` or `::1`. Any other `REDIS_HOST` or a `REDIS_URL` uses that server and leaves the bundled one stopped.
- The installer generates the app key and Reverb secrets into `/opt/databasify/.env` ([0033](0033-app-settings-in-env-file.md)), and migrations run on every container start.
- The updater moves installs from the old separate containers: `REDIS_HOST=valkey` and `REVERB_HOST=reverb` become `127.0.0.1`, other values stay, and the old `databasify_valkey` volume is removed after the restart.

## Consequences

- SQLite lives at `storage/database.sqlite` (the `DB_DATABASE` default), so `storage` is the only volume a backup needs.
- A backup is the storage volume plus `/opt/databasify/.env`, which holds `APP_KEY` ([0013](0013-secrets-and-ssh-keys.md), [0033](0033-app-settings-in-env-file.md)).
- Losing Redis data loses queued jobs and, in the installed app, sessions, cache entries and locks ([0034](0034-cache-and-sessions-in-redis.md)).
- Updating from 0.1.0, which ran Valkey in its own container, drops whatever was queued there at update time.
- All Horizon workers write to one SQLite file, so write contention grows with the number of workers; keeping the cache and locks in Redis keeps those writes off it.
- The storage volume must be on a local filesystem, not NFS, because WAL needs shared memory between processes. A backup copies `database.sqlite` together with its `-wal` and `-shm` files, or is taken with the app stopped.

## Alternatives considered

- **MySQL or PostgreSQL for the app itself:** not the default, because it adds a server to install and back up. It stays available through `.env`.
- **Redis or Valkey in its own container:** rejected, because the app runs as one container ([0016](0016-app-architecture.md)); an external Redis is still possible through `.env`.

## Evidence

- `docker/Dockerfile`
- `docker/redis.conf`
- `docker/supervisord.conf`
- `docker/entrypoint.sh`
- `scripts/compose.yaml`
- `scripts/env.example`
- `scripts/update.sh`
- `config/database.php`
- `storage/.gitignore`
- `config/queue.php`
