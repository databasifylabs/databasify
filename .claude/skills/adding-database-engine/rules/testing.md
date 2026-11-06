# Phase 5 — tests and live e2e

## Pest

Registering the engine pulls it into every test that iterates the registered engines (`Database::providers()`) (shim syntax, filter options, image registry). These tests hard-code engine lists and break or need a row:

| Test                                                                                       | Change                                                                                   |
| ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| `tests/Feature/DatabaseProviders/EngineProfileTest.php`                                    | Category order (`Application:…` before `Caching:…`) and the index of the Valkey entry.   |
| `tests/Feature/Dashboard/DashboardTest.php`                                                | The supported-engines sentence.                                                          |
| `tests/Feature/Databases/Lifecycle/CreateDatabaseTest.php`                                 | `->has('engines', N)`.                                                                   |
| `tests/Feature/Databases/Nodes/DeployDatabaseNodeTest.php`                                 | The `for engine_dir in /srv/…` list.                                                     |
| `tests/Feature/DatabaseProviders/ScriptSyntaxTest.php`                                     | Single and cluster rows in the script dataset and in the point-in-time recovery dataset. |
| `ClientPortsTest`, `ReadDatabaseMetricsTest`, `ReadNodeLogsTest`, `MigrationLifecycleTest` | One row each for the new engine.                                                         |

Add, following the existing engine tests in `tests/Feature/DatabaseProviders/`:

- `<Name>Test`: config file contents, files and owners, secrets, tuner output, process manifest, `role`, `membershipVariables`, start/apply-config/certificate scripts, version lines.
- Account view / archive / migration tests for every capability implemented (`AccountSqlTest` with `accountSql()`, `MongodbArchiveTest`, `Migrations/<Name>MigrationTest` built from `$database->provider()->migration()`).
- Render real scripts with `view($database->provider()->scriptView('…'), $database->provider()->scriptData()->…(...))->render()`, then check `bashErrorsIn()` for bash and `javascriptErrorsIn()` for embedded JavaScript (extract heredocs with `/<<'JS'[^\n]*\n(.*?)\nJS\n/s`). Most e2e bugs were scripts that rendered fine but did the wrong thing, so assert behaviour, not just syntax.
- Add every step the engine skips to `tests/Feature/DatabaseProviders/SkippedStepsTest.php` (the group method runs no SSH call and writes no log), to `stepsMissingFrom()` in `ScriptSyntaxTest`, and every `@include`-only step file to `EngineScriptLayoutTest` (it renders the same as the view it includes).

Helpers in `tests/Pest.php`: `idleDatabase()`, `newDatabase()`, `markRoles()`, `withReadOnlyNode()`, `fakeSsh()`, `withImage()`, `withRecovery()`, `withZone()`, `fakeCloudflare()`, `migratingDatabase()`, `migrationOf()`, `renderedFile()`, `bashErrorsIn()`, `javascriptErrorsIn()`.

Checks before committing:

```bash
php artisan test --compact --parallel
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint --dirty --format agent
npx vp check --fix
npx tsc --noEmit
```

Tests with fixed dates rot (restore targets fall out of the restorable window); pin time with `$this->travelTo(...)`.

## Live e2e

Use the `e2e-engine-tester` agent (`.claude/agents/e2e-engine-tester.md`) with the engine and three servers, after updating that file for the engine. Read [lessons.md](lessons.md) first.

- Ordering servers needs the user's go-ahead: DigitalOcean provider, `sgp1`, `s-2vcpu-4gb`, Ubuntu 24.04, through `OrderProviderServer` + `ProviderServerOrder(serverProviderId, …)`. Agree up front whether to destroy them (`DeleteServer(destroyAtProvider: true)`) or keep them.
- Never seed, wipe or migrate `database/database.sqlite` — it holds the user's account and provider keys.
- Run `php artisan queue:restart` after PHP changes; Horizon runs under `composer run dev`.
- Run the full step list on the default line, then a shorter pass (create, cluster, failover, backup and restore) on every other line.
- Scenario R7 (mixed patch versions) needs two images of one line: a rebuild with a new digest (`Refresh image`) or a branch build publishing `<release>-<sha7>`.
- DNS steps create public records in the user's zone: ask first, then check records with `dig @<zone nameserver>`, connect by name with the CA certificate, fail over and connect again.
- A read-only node needs a fourth server.
- Hand the agent the files it owns; when you edit the same engine in parallel, tell it which files are yours.
