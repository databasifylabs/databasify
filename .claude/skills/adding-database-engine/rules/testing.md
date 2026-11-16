# Phase 4: tests and live e2e

## Pest

Actions and flows run for any engine against `Tests\Support\RecordingDriver::fake()`, which binds every registered driver class to a recording stand-in and returns an `EngineRecording`: answer a read with `on('<group>.<method>', $reply)`, then assert with `callsOf()`, `called()` and `changedNothing()`. SSH goes through `Tests\Support\FakeServerConnection` (`$this->ssh()`). Registering one more engine needs no change to existing tests.

Add feature tests for what the registration changes: the connection details the engine shows (URI, CLI command, `.env` keys, the public-certificate and service variants), the migration source form, and a version line with `'recovery' => false` hiding backups. Engine scripts are checked in the live e2e below.

Checks before committing:

```bash
php artisan test --compact --parallel
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint --dirty --format agent
npm run check
npm run types:check
```

Tests with fixed dates rot (restore targets fall out of the restorable window); pin time with `$this->travelTo(...)`.

## Live e2e

It needs the engine's published release images, so it runs once the engine pull request has merged, or a maintainer runs it before merging. Run it on three provider servers through the app's own actions, from tinker or the UI, and reach servers only through `App\Contracts\Ssh\ServerConnection`. Read [lessons.md](lessons.md) first. Put the step-by-step results in the app pull request.

- Ordering servers needs the user's go-ahead. Order them through the app's server provider (`OrderProviderServer` with a `ProviderServerOrder`), and agree up front whether to destroy them (`DeleteServer(destroyAtProvider: true)`) or keep them.
- Never seed, wipe or migrate the app's own database: it holds the user's account and provider keys.
- Run `php artisan queue:restart` after PHP changes; Horizon runs under `composer run dev`.
- Engine fixes go in the engine repository, as commits on its pull request, instead of patches to a container by hand. A merged fix reaches running nodes as a rebuilt image, through an upgrade or the maintenance window.
- Run the full step list on the default line, then a shorter pass (create, cluster, failover, backup and restore) on every other line.
- Watch each database's Activity tab: every engine step, long ones included, writes a named server log.
- DNS steps create public records in the user's zone: ask first, then check records with `dig +tcp @<zone nameserver>`, connect by name, fail over and connect again.
- A read-only node needs a fourth server.
- Hand the agent the files it owns; when you edit the same engine in parallel, tell it which files are yours.
