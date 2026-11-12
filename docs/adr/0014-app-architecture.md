# 0014. Application architecture and distribution

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
- **Plugins** are Laravel service providers (`AbstractPlugin`, with install, uninstall, enable and disable hooks). They are installed from a GitHub repository's latest release, a zip link or an upload into `storage/plugins` (configurable with `DATABASIFY_PLUGINS_PATH`) without Composer, and may bundle their own `vendor/autoload.php`. They register database, DNS and server providers through the same `Register*Provider` builders the built-in providers use. Engines they register are community engines ([0030](0030-community-engines.md)).
- **Auth** uses Fortify (password reset, email verification, 2FA, passkeys). There is no public registration: the first visit to `/setup` creates the single owner and a `Default` project ([0024](0024-projects-as-a-global-scope.md)).
- **Realtime** uses Reverb.
- **Distribution:**
    - Development and releases happen on the `0.x` branch.
    - An Ubuntu image with PHP-FPM behind nginx, HTTPS at Cloudflare (`docker/Dockerfile`), published to GHCR by `.github/workflows/release.yml` when a version tag without a `v` prefix (`1.2.3`, or `1.2.3-beta.1` for a pre-release) is pushed. The workflow writes that version into `config/app.php` before the build, so `app.version` matches the release; the image is tagged with that version, and also `latest` unless it is a pre-release. After a stable release the workflow commits the version to `config/app.php` and `scripts/version` on `0.x`. Each architecture builds on its own native runner (`ubuntu-26.04` for amd64, `ubuntu-26.04-arm` for arm64, no QEMU), with Composer authenticated by the workflow token through the `composer_auth` build secret, and pushes by digest without a layer cache or provenance attestation; a final job joins both digests into the multi-arch tags, so the package's only tags are the release versions and `latest`.
    - `scripts/compose.yaml` runs that image (`DATABASIFY_VERSION`, `latest` by default) as one container, `app`, with the `storage` and `redis` volumes, `HTTP_PORT` mapped to port 80 and a 30 minute stop grace period, with SQLite by default ([0019](0019-app-state-sqlite-and-redis.md)).
    - The entrypoint's `start` role (the default command) checks `APP_KEY`, prepares storage, runs migrations, caches the config and then runs `supervisord` as PID 1 (`docker/supervisord.conf`). Supervisord runs the bundled Redis (only when no external Redis is set, [0019](0019-app-state-sqlite-and-redis.md)), PHP-FPM, nginx, Reverb, Horizon and `schedule:work`, the Laravel processes as `www-data`, all logging to the container's stdout. On stop it stops Horizon, the scheduler and Reverb first, giving Horizon up to 30 minutes (`stopwaitsecs=1800`) to finish running jobs, then nginx, PHP-FPM and Redis.
    - Reverb listens on `127.0.0.1:8080` and nginx proxies `/app` and `/apps` to it; `REVERB_HOST=127.0.0.1` lets the app publish to it. The image healthcheck is `GET /up` through nginx.
    - `get.databasify.io` serves `scripts/` (the three scripts, `lib.sh`, `horizon-workers.sh`, `compose.yaml`, `env.example` and `version`) from the `0.x` branch through the Cloudflare Pages project `databasify-get`; the scripts download everything from there and the installer checks the host against [0016](0016-host-os-and-provisioning.md). Users run `curl -fsSL https://get.databasify.io/install.sh | sudo bash` (and `update.sh`, `uninstall.sh` the same way).
- **License:** Apache-2.0 (relicensed from AGPL-3.0-only on 2026-11-06).

## Consequences

- The single-owner model keeps authorization simple. It is also why the server log channel authorizes any signed-in user ([0006](0006-operations-and-server-logs.md)); the user and terminal channels are scoped to their owner.
- Plugins run arbitrary PHP inside the app, with no signature check. Only install plugins you trust.
- One container is one thing to install, update and read logs from (`docker compose logs app`), but its processes no longer restart independently: restarting it restarts the web, queue, scheduler and websockets together, a long Horizon drain delays the web coming back, and a process that keeps crashing is retried by supervisord without failing the healthcheck unless it is the web.

## Alternatives considered

- **Composer-installed plugins:** rejected, because it would need Composer and a writable vendor directory in production.
- **One container per process (web, Horizon, scheduler, Reverb, Valkey):** used until 0.1.0, replaced because five containers cost more to run and explain on one small server.

## Evidence

- `app/Actions/`
- `app/DTOs/`
- `app/Queries/`
- `config/essentials.php`
- `app/Plugins/AbstractPlugin.php`
- `app/Services/Plugins/PluginLoader.php`
- `app/Actions/Setup/CreateOwnerAccount.php`
- `docker/Dockerfile`
- `docker/entrypoint.sh`
- `docker/supervisord.conf`
- `docker/nginx.conf`
- `.github/workflows/release.yml`
- `scripts/compose.yaml`
- `scripts/install.sh`
- `scripts/lib.sh`
- `LICENSE`
