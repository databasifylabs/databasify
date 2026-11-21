# 0013. One-container install, app state, settings and self-update

- Status: Accepted
- Date: 2026-11-15

## Context

The app installs on one small server and must be easy to back up, update and roll back. It still needs a queue that Horizon can run across many workers. Updating runs on the host, and giving the app container the Docker socket or root SSH to its own host would turn any app compromise into root on the host.

## Decision

- **One container.** Releases build a multi-arch image on GHCR from a version tag without a `v` prefix. `scripts/compose.yaml` runs it as one `app` container whose entrypoint checks `APP_KEY`, runs migrations and starts `supervisord` with Redis (bundled), PHP-FPM, nginx, Reverb, Horizon and the scheduler. Horizon gets 30 minutes to finish running jobs on stop. Users install, update and uninstall with the scripts served from `get.databasify.io`.
- **App state.** SQLite (`storage/database.sqlite`) is the system of record, in WAL mode with a busy timeout and `IMMEDIATE` transactions, so `refreshForUpdate()` serializes like a row lock. `DB_*` settings switch the app to MySQL or PostgreSQL. Redis holds the queues and, in the installed app, the cache, sessions and locks; development keeps them in SQLite. The bundled Redis keeps an append-only file and starts only when no external Redis is set.
- **Settings live in `/opt/databasify/.env`**, the only source. The installer generates `APP_KEY` and the Reverb credentials and sizes the Horizon workers from the server's CPU and memory (`scripts/horizon-workers.sh`). Given all three of `DATABASIFY_OWNER_NAME`, `DATABASIFY_OWNER_EMAIL` and `DATABASIFY_OWNER_PASSWORD`, it removes them from its environment at once, creates the owner after the health check by piping the password into `setup:owner` (never as an argument, never written to a file), and fails the install if that fails. Without them, `/setup` stays first-come until the owner opens it. The updater fills missing values, asks before changing worker counts, and never generates a new `APP_KEY` while a storage volume exists.
- **Self-update through a host helper.** The app checks `version.json` daily and on demand. To update, it only writes a request into the bind-mounted updater folder; the systemd unit `databasify-update.path` starts `databasify-update.service`, which runs the latest `update.sh` as root. `update.sh` writes its progress to `status.json` and rolls back to the previous image when the new one is not healthy. Updates exist only on Docker installs with the helper's `installed` marker.

## Consequences

- A backup is the storage volume plus `/opt/databasify/.env`, which holds `APP_KEY` ([0009](0009-secrets-and-ssh-keys.md)). The Passport signing keys live in the storage volume (`storage/oauth-*.key`); the entrypoint creates them only when they are missing, so API keys survive restarts, updates and restores. The storage volume must be on a local filesystem, because WAL needs shared memory.
- All workers write to one SQLite file, so transactions stay short and never wrap SSH or HTTP calls.
- Losing Redis data loses queued jobs, sessions and locks.
- Restarting the container restarts web, queue, scheduler and websockets together.
- Migrations run on container start, so a rolled-back release can meet a newer schema: every release's migrations must stay readable by the release before it.

## Alternatives considered

- **One container per process:** used until 0.1.0, replaced because five containers cost more to run and explain on one small server.
- **MySQL or PostgreSQL by default:** not chosen, it adds a server to install and back up.
- **Generating settings inside the image:** rejected, two sources of truth would hide the key from backups.
- **Docker socket in the app container, or the app SSHing to its host as root:** rejected, a container compromise would be root on the host.

## Evidence

- `docker/Dockerfile`
- `docker/entrypoint.sh`
- `docker/supervisord.conf`
- `docker/redis.conf`
- `.github/workflows/release.yml`
- `scripts/compose.yaml`
- `scripts/env.example`
- `scripts/install.sh`
- `scripts/install.sh`
- `scripts/update.sh`
- `scripts/lib.sh`
- `scripts/horizon-workers.sh`
- `scripts/databasify-update.path`
- `scripts/databasify-update.service`
- `config/database.php`
- `config/queue.php`
- `app/Services/Updates/HostUpdater.php`
- `app/Actions/Updates/StartUpdate.php`
- `app/Http/Middleware/EnsureUpdaterIsInstalled.php`
