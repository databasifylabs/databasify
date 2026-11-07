# 0016. Application architecture and distribution

- Status: Accepted
- Date: 2026-11-04

## Context

Databasify is a self-hosted, open-source Laravel app maintained by a small team with heavy use of AI agents. The code must stay easy to change safely, and the app must be easy to install on one small server.

## Decision

- **Code structure:**
    - Business logic lives in single-purpose `final readonly` Action classes with one `handle()` method. Controllers validate, call an action and respond.
    - Arrays crossing boundaries become `final readonly` DTOs.
    - Read models are Query objects (for example `ServerSummaryQuery`).
    - Logic about one model lives on that model.
    - Primary keys are UUIDs (`HasUuids`, version 7); plugins are the exception, keyed by their string id.
    - PHPStan runs at max level, with 100% type coverage.
    - Model and app defaults come from `nunomaduro/essentials` with every option on (`config/essentials.php`): strict, unguarded models with automatic eager loading, immutable dates, HTTPS URLs in production when `APP_URL` is `https://` (so plain-HTTP IP installs keep working), the strong default password rule, and faked sleeps and stray-request protection in tests. Automatic eager loading means lazy-load prevention mainly catches single-model loads, and `Unguard` makes `#[Fillable]` inert, so models (plugin models too) rely on writing only validated or DTO data.
- **Plugins** are Laravel service providers (`AbstractPlugin`, with install, uninstall, enable and disable hooks). They are installed from a GitHub repository's latest release, a zip link or an upload into `storage/plugins` (configurable with `DATABASIFY_PLUGINS_PATH`) without Composer, and may bundle their own `vendor/autoload.php`. They register database, DNS and server providers through the same `Register*Provider` builders the built-in providers use.
- **Auth** uses Fortify (password reset, email verification, 2FA, passkeys). There is no public registration: the first visit to `/setup` creates the single owner and a `Default` project ([0031](0031-projects-as-a-global-scope.md)).
- **Realtime** uses Reverb.
- **Distribution:**
    - Development and releases happen on the `0.x` branch.
    - A FrankenPHP/Caddy image (`docker/Dockerfile`), published to GHCR when a tag is pushed.
    - `docker/compose.yaml` runs that image (`DATABASIFY_VERSION`, `latest` by default) as web, Horizon, the scheduler and Reverb, plus a pinned Valkey, with SQLite by default ([0021](0021-app-state-sqlite-and-valkey.md)).
    - `scripts/install.sh` fetches the compose file, environment template and scripts from the `0.x` branch.
    - `get.databasify.io` serves `scripts/` from the `0.x` branch through the Cloudflare Pages project `databasify-get`, so users run `curl -fsSL https://get.databasify.io/install.sh | sudo bash` (and `update.sh`, `uninstall.sh` the same way).
- **License:** Apache-2.0 (relicensed from AGPL-3.0-only on 2026-11-06).

## Consequences

- The single-owner model keeps authorization simple. It is also why the server log channel authorizes any signed-in user ([0007](0007-operations-and-server-logs.md)); the user and terminal channels are scoped to their owner.
- Plugins run arbitrary PHP inside the app, with no signature check. Only install plugins you trust.

## Alternatives considered

- **Composer-installed plugins:** rejected, because it would need Composer and a writable vendor directory in production.

## Evidence

- `app/Actions/`
- `app/DTOs/`
- `app/Queries/`
- `config/essentials.php`
- `app/Plugins/AbstractPlugin.php`
- `app/Services/Plugins/PluginLoader.php`
- `app/Actions/Setup/CreateOwnerAccount.php`
- `docker/Dockerfile`
- `docker/compose.yaml`
- `scripts/install.sh`
- `LICENSE`
