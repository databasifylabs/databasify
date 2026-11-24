# 0011. Application code structure

- Status: Accepted
- Date: 2026-11-15

## Context

Databasify is a self-hosted, open-source Laravel app maintained by a small team with heavy use of AI agents. The code must stay easy to change safely, run on databases that forbid foreign keys, and keep its behaviour testable without real servers.

## Decision

- **Code shape.** Business logic lives in single-purpose `final readonly` Action classes whose only public method is `handle()` (Fortify's own contracts aside); controllers validate, call an action and respond. Values several entry points share, such as log names, live in `app/Support` (`ProviderLogNames`, `ProvisionSteps`). Arrays crossing boundaries become `final readonly` DTOs. Read models are Query objects. Primary keys are UUIDs. PHPStan runs at max level with full type coverage. Only per-area cache classes in `app/Support` (`ExtensionCache`, `ExtensionChanges`, `UpgradeCache`, `ImageCache`, `ProviderCatalogCache`, `DnsCache`, `SetupCache`, `TerminalSession`) touch Laravel's `Cache`, each with private keys; actions, services, models and drivers inject them, and `tests/Feature/ArchitectureTest.php` enforces it. Model defaults come from `nunomaduro/essentials` (`config/essentials.php`): strict, unguarded models with automatic eager loading and immutable dates, so models only ever receive validated or DTO data.
- **One owner.** Auth uses Fortify. There is no public registration: the single owner and a `Default` project come from the first visit to `/setup`, or from `php artisan setup:owner` when the installer is given `DATABASIFY_OWNER_*` settings, in which case `/setup` never opens. Owner creation runs under one cache lock, so two setups can never both create an owner. Projects organise resources but are not an authorization boundary ([0012](0012-projects-as-a-global-scope.md)).
- **Plugins** are Laravel service providers (`AbstractPlugin`) loaded from `storage/plugins` without Composer. They register database, DNS and server providers through the same builders the built-in providers use; their engines are community engines ([0004](0004-engine-drivers-and-blade-scripts.md)).
- **Live UI by polling.** Pages refresh with Inertia `usePoll` partial reloads. Reverb carries only byte streams (server log and terminal output); there are no model-change broadcasts. UI endpoints are Inertia routes reached through Wayfinder.
- **Public API.** A JSON API lives under `/api/v1` with its own `Api\V1` controllers and resources, so the UI can change without breaking scripts; the controllers reuse the UI's actions, queries and form requests. It authenticates with Laravel Passport personal access tokens, shown to the owner as API keys at `/api-keys` (password confirmation, shown once on creation). Each key gets a read or write scope per area (`AccessArea`: projects, server, DNS and storage providers, servers, databases, and read-only system), where write includes read, an expiry, and either all projects or a selected set ([0012](0012-projects-as-a-global-scope.md)). Requests are limited to 120 a minute per key. The interactive terminal, plugin changes, self-update and account security stay UI only.
- **MCP server.** `/mcp` (Laravel MCP, `routes/ai.php`) serves AI agents one tool per API action, so MCP always matches the API: each tool reuses the API's actions, queries, form requests and `Api\V1` resources, carries the area and level of its route (`#[MirrorsApi]`), and is hidden and refused without that scope. Agents sign in with OAuth 2.1 on the same Passport install (dynamic client registration, PKCE, refresh tokens; Passport's device flow stays off). The authorize, approve and deny routes go through Databasify's controller, which extends Passport's: the owner confirms the password and picks a level per area and the projects on the consent page, exactly as for an API key, and the client and every token it gets, including a reconnect that skips the page, carry only those scopes. The page names the host of the redirect the request actually uses. API keys also work on `/mcp` as a Bearer token. The owner sees and removes connected agents at `/connected-apps`, which also shows the setup for common agents.
- **Tests fake the boundaries.** Outside systems sit behind contracts bound with `#[Bind]` (`ServerConnection`, `ImageRegistry`, `CertificateAuthority`, `PortProbe`) and feature tests replace them with hand-written fakes from `tests/Support`; provider and DNS APIs use `Http::fake`. Scripts are tested as rendered text. Real servers are tested by an end-to-end run outside CI.
- **Delete rules live in the app, not the schema.** Migrations declare relationship columns without `constrained()`. A delete action refuses while dependants exist (`ensureDeletable()`, counting every project). Owned children are deleted or nulled out by one observer per parent model (`#[ObservedBy]`, children one by one through `DeleteChildren`), inside the action's transaction.

## Consequences

- The single-owner model keeps authorization simple; adding users means revisiting the shared channels and the terminal.
- Plugins run arbitrary PHP inside the app with no signature check.
- New live status in the UI is a new polled prop, not a broadcast event.
- A new UI feature that should be scriptable needs its own `Api\V1` route, resource and scope area; database passwords appear only in write responses.
- Passport needs phpseclib 4, which only accepts whole-second SSH timeouts, so the terminal polls once a second.
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
- `routes/api.php`
- `app/Enums/Access/AccessArea.php`
- `app/Models/ApiKey.php`
- `app/Actions/Api/CreateApiKey.php`
- `routes/ai.php`
- `app/Mcp/Tools/McpTool.php`
- `app/Http/Controllers/ConnectedApps/AuthorizeConnectedAppController.php`
- `tests/Feature/Mcp/ToolParityTest.php`
- `app/Console/Commands/Setup/CreateOwnerCommand.php`
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
- `app/Support/`
- `tests/Feature/ArchitectureTest.php`
