# 0034. Cache, sessions and locks in Valkey

- Status: Accepted
- Date: 2026-11-08

## Context

[0021](0021-app-state-sqlite-and-valkey.md) kept the cache, sessions and locks in SQLite. Every Horizon worker then writes cache entries and locks into the one SQLite file the app depends on, which adds to its write contention. The installed app already runs Valkey for the queues.

## Decision

- The installed app keeps the cache, sessions and locks in Valkey: `scripts/env.example` sets `CACHE_STORE=redis` and `SESSION_DRIVER=redis`. This covers stop signals, unique-job locks, network and firewall locks, and terminal input.
- SQLite stays the system of record for everything else.
- Development keeps `CACHE_STORE=database` and `SESSION_DRIVER=database` in the root `.env.example`, and tests use the array stores.

## Consequences

- Fewer writes reach SQLite, so contention between Horizon workers drops.
- Losing Valkey's data now also signs users out and drops cache entries and locks, including the locks of jobs that are running. Valkey keeps its append-only file in the `valkey` volume, so a restart keeps them.
- The `valkey` volume holds only queues, cache, sessions and locks, so a backup still needs only the storage volume and `/opt/databasify/.env`.

## Alternatives considered

- **Keep them in SQLite:** rejected, because it puts every worker's cache and lock writes on the one file the app depends on.

## Evidence

- `scripts/env.example`
- `scripts/compose.yaml`
- `config/cache.php`
- `config/session.php`
