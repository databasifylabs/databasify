# Phase 4: tests and live e2e

## Pest

The app has no per-engine code, so most tests run for any engine. Registering one more engine breaks the tests that pin the engine list:

| Test                                                    | Change                                                                       |
| ------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `tests/Feature/Engines/EngineCatalogTest.php`           | The engine ids and labels, plus a line asserting the new entry's settings.   |
| `tests/Feature/Plugins/PluginLoaderTest.php`            | `Database::engines()`.                                                       |
| `tests/Feature/Databases/Lifecycle/CreateDatabaseTest.php` | `->has('engines', N)`.                                                    |
| `tests/Pest.php`                                        | `engineCapabilitiesPayload()`: the engine's ports, reserved names, commands and password encryptions, as its `engine capabilities` reply has them. |

Then add tests for what the registration changes: the connection details the engine shows (URI, CLI command, `.env` keys, the public-certificate and service variants), the migration source form, and a version line with `'recovery' => false` hiding backups. Use `createThroughFlow('<engine>', 3)` in `CreateDatabaseFlowTest` to run the create flow against `fakeEngine()`.

Helpers in `tests/Pest.php`: `fakeSsh()`, `fakeEngine()` (answers every command; pass `fakeSsh()->respondToEngine(...)` first to override one), `engineCommandsIn()`, `answerNodeStatus()`, `idleDatabase()`, `advanceFlows()`, `inProjectDuring()`.

Checks before committing:

```bash
php artisan test --compact --parallel
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint --dirty --format agent
npm run check
npx tsc --noEmit
```

Tests with fixed dates rot (restore targets fall out of the restorable window); pin time with `$this->travelTo(...)`.

## Live e2e

Run it on three provider servers through the app's own actions, from tinker or the UI, and reach servers only through `App\Contracts\Ssh\ServerConnection`. Read [lessons.md](lessons.md) first.

- Ordering servers needs the user's go-ahead. Order them through the app's server provider (`OrderProviderServer` with a `ProviderServerOrder`), and agree up front whether to destroy them (`DeleteServer(destroyAtProvider: true)`) or keep them.
- Never seed, wipe or migrate the app's own database: it holds the user's account and provider keys.
- Run `php artisan queue:restart` after PHP changes; Horizon runs under `composer run dev`.
- Engine fixes go in `../engines`: rebuild the image (or push and let `host wrapper-update` install the new wrapper) instead of patching a container by hand.
- Run the full step list on the default line, then a shorter pass (create, cluster, failover, backup and restore) on every other line.
- Watch each database's Activity tab: every engine step, long ones included, writes a named server log.
- DNS steps create public records in the user's zone: ask first, then check records with `dig +tcp @<zone nameserver>`, connect by name, fail over and connect again.
- A read-only node needs a fourth server.
- Hand the agent the files it owns; when you edit the same engine in parallel, tell it which files are yours.
