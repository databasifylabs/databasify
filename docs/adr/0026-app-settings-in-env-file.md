# 0026. App settings live in `/opt/databasify/.env`

- Status: Accepted
- Date: 2026-11-08

## Context

The owner must be able to see and edit every app setting in one place, and back up the key that decrypts every stored secret ([0011](0011-secrets-and-ssh-keys.md)) together with the rest of the settings. Values generated inside the image would be hidden from `/opt/databasify/.env`.

## Decision

- `/opt/databasify/.env` is the only source of app settings. The image generates nothing.
- The installer writes `APP_KEY` (`base64:` and 32 random bytes) and `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (4, 16 and 32 random bytes as hex) into `.env`, together with the six `HORIZON_<GROUP>_MAX_PROCESSES` sized by `scripts/horizon-workers.sh` from the server's CPU and memory ([0006](0006-operations-and-server-logs.md)). Sizing overrides in `.env` (`DATABASIFY_CPUS`, `DATABASIFY_MEMORY_MB`, `DATABASIFY_RESERVE_MB`, `DATABASIFY_STACK_MB`, `DATABASIFY_PHP_PROCESS_MB`, `DATABASIFY_WORKER_RSS_MB`, `HORIZON_<GROUP>_MIN_PROCESSES`) feed the formula; the shell environment wins over `.env`.
- When `.env` lacks any of the app key and Reverb credentials, the updater (and the installer, whether it writes a new `.env` or keeps one) generates the missing values before it pulls or restarts anything. It never generates `APP_KEY` while a `databasify_storage` volume exists or Docker does not answer, because a new key would make existing secrets unreadable: it stops and asks for the `APP_KEY` and `REVERB_APP_*` values from the backup. Missing Reverb values alone are always generated.
- The updater recomputes the Horizon worker counts on every run. Empty values are filled; a value that differs asks first (default No, `DATABASIFY_YES=true` accepts), and without a terminal the current values stay.
- Every role in `docker/entrypoint.sh` refuses to start when `APP_KEY` is empty and points at `/opt/databasify/.env`.

## Consequences

- Every setting is visible and editable in one file, and a backup is the storage volume plus `.env` ([0019](0019-app-state-sqlite-and-redis.md)).
- `scripts/horizon-workers.sh` holds the sizing formula once; `scripts/lib.sh` fetches it from `get.databasify.io` like the other release files, and the image no longer contains it.
- Worker counts follow the server only when the installer or updater runs, so a resized server keeps its old counts until the next update.
- Losing `.env` now loses the app key, so `.env` must be backed up with the storage volume.

## Alternatives considered

- **Generate the values in the image:** rejected, because two sources of truth would hide the key from backups of `.env`.
- **Change Horizon counts silently on update:** rejected, because it would overwrite the owner's tuning.

## Evidence

- `scripts/lib.sh`
- `scripts/install.sh`
- `scripts/update.sh`
- `scripts/horizon-workers.sh`
- `scripts/env.example`
- `docker/entrypoint.sh`
- `docker/Dockerfile`
