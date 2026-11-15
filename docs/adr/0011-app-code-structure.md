# 0011. Application code structure

- Status: Accepted
- Date: 2026-11-15

## Context

Databasify is a self-hosted, open-source Laravel app maintained by a small team with heavy use of AI agents. The code must stay easy to change safely, run on databases that forbid foreign keys, and keep its behaviour testable without real servers.

## Decision

- **Code shape.** Business logic lives in single-purpose `final readonly` Action classes with one `handle()` method; controllers validate, call an action and respond. Arrays crossing boundaries become `final readonly` DTOs. Read models are Query objects. Primary keys are UUIDs. PHPStan runs at max level with full type coverage. Model defaults come from `nunomaduro/essentials` (`config/essentials.php`): strict, unguarded models with automatic eager loading and immutable dates, so models only ever receive validated or DTO data.
- **One owner.** Auth uses Fortify. There is no public registration: the first visit to `/setup` creates the single owner and a `Default` project. Projects organise resources but are not an authorization boundary ([0012](0012-projects-as-a-global-scope.md)).
- **Plugins** are Laravel service providers (`AbstractPlugin`) loaded from `storage/plugins` without Composer. They register database, DNS and server providers through the same builders the built-in providers use; their engines are community engines ([0004](0004-engine-drivers-and-blade-scripts.md)).
- **Live UI by polling.** Pages refresh with Inertia `usePoll` partial reloads. Reverb carries only byte streams (server log and terminal output); there are no model-change broadcasts. All endpoints are Inertia routes reached through Wayfinder; there is no public API.
- **Tests fake the boundaries.** Outside systems sit behind contracts bound with `#[Bind]` (`ServerConnection`, `ImageRegistry`, `CertificateAuthority`, `PortProbe`) and feature tests replace them with hand-written fakes from `tests/Support`; provider and DNS APIs use `Http::fake`. Scripts are tested as rendered text. Real servers are tested by an end-to-end run outside CI.
- **Delete rules live in the app, not the schema.** Migrations declare relationship columns without `constrained()`. A delete action refuses while dependants exist (`ensureDeletable()`, counting every project). Owned children are deleted or nulled out by one observer per parent model (`#[ObservedBy]`, children one by one through `DeleteChildren`), inside the action's transaction.

## Consequences

- The single-owner model keeps authorization simple; adding users means revisiting the shared channels and the terminal.
- Plugins run arbitrary PHP inside the app with no signature check.
- New live status in the UI is a new polled prop, not a broadcast event; automation has no stable API yet.
- A new outside dependency needs a contract and a fake. A script can render correctly and still be wrong on a server; only the end-to-end run catches that.
- A plain `->delete()` or a query-builder delete skips the refusals or the observers, so deleting code goes through the actions. A new relationship needs its rule added to the parent's observer.

## Alternatives considered

- **Composer-installed plugins:** rejected, it needs Composer and a writable vendor directory in production.
- **Broadcasting model changes:** rejected, every state transition would need an event and one would be missed.
- **Mocking SSH calls with Mockery:** rejected, hand-written fakes record what ran and stay readable.
- **Database foreign keys, alone or next to the observers:** rejected, cascades skipped model events and managed databases such as PlanetScale forbid them.

## Evidence

- `app/Actions/`
- `app/DTOs/`
- `app/Queries/`
- `config/essentials.php`
- `app/Actions/Setup/CreateOwnerAccount.php`
- `app/Plugins/AbstractPlugin.php`
- `app/Services/Plugins/PluginLoader.php`
- `resources/js/pages/databases/index.tsx`
- `app/Events/ServerLogOutputReceived.php`
- `app/Events/TerminalOutputReceived.php`
- `tests/Pest.php`
- `tests/Support/FakeServerConnection.php`
- `app/Observers/`
- `app/Actions/Models/DeleteChildren.php`
- `tests/Feature/Models/DeleteRulesTest.php`
