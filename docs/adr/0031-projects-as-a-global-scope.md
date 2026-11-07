# 0031. Projects as a global scope

- Status: Accepted
- Date: 2026-11-07

## Context

Every server, database and provider was visible everywhere, with no way to group them. The owner wants Cloudflare-style projects: the resource pages live under a project, a few pages stay global, and resources can move between projects. Passing a `Project` into every controller, query and action would touch most of the app.

## Decision

- **URL shape:** the dashboard, search, servers, providers, networks, DNS, storage and database routes sit under the `{project}` prefix (`/{project_uuid}/…`, `whereUuid`). Each route file opens its group with `Route::project()->group(...)`; the `Route::project()` macro (`app/Providers/AppServiceProvider.php`) applies `auth`, `verified` and the `project` middleware alias (`SetCurrentProject`, registered in `bootstrap/app.php`), the prefix and the UUID constraint. Every route file is listed in the `web` array of `bootstrap/app.php`; `routes/web.php` requires none. Route names stay the same (`servers.show`, `dashboard`…). Projects (`/projects`, in `routes/web.php`), plugins, settings, setup and Horizon stay global. `/` and `/dashboard` redirect to the current project's dashboard (the last visited project, else the first by name, else `/projects`).
- **Context, scope and trait:**
    - `ProjectContext` (bound `scoped`) holds the current project. Only the `SetCurrentProject` middleware initializes it.
    - `SetCurrentProject` runs after authentication and before `SubstituteBindings` (middleware priority), so route-model binding is scoped. It sets `URL::defaults(['project' => …])`, stores `users.last_project_id`, forgets the `project` route parameter so controllers keep their signatures, and terminates the context in `terminate()`.
    - `ProjectScope` filters on `project_id` only while the context is initialized. `BelongsToProject` adds the scope, fills `project_id` from the context on create, and offers `withoutProject()`.
    - `Server`, `Database`, `Network`, `ServerProvider`, `DnsProvider` and `StorageProvider` use the trait. Child models (nodes, users, zones, recoveries, logs…) have no column and follow their parent; project pages reach them through the parent (`DatabaseNode::inCurrentProject()`, `DnsZone::inCurrentProject()`, `ServerLog::inCurrentProject()`).
- **What stays global:** queued jobs, scheduled commands, Horizon and tinker never initialize the context, so they see every project. The WireGuard address pool and network names (`AllocateNetworkBlock`, `Network::freeName()`), database names (`ClusterName`), private addresses on a network (`PrivateNetworkRules`) and the `unique:` rules for server, database and network names are checked across projects, because names feed DNS hostnames ([0014](0014-dns-hostnames.md)) and the pool is shared ([0028](0028-wireguard-address-blocks.md)).
- **Unscoped links:** relations between a resource and the resources it depends on, or that depend on it, drop the scope: `Server::serverProvider()`, `Server::network()`, `Network::servers()`, `Network::serverProvider()`, `ServerProvider::servers()`, `ServerProvider::networks()`, `DnsZone::provider()`, `DnsZone::databases()`, `DnsProvider::databases()`, `StorageProvider::databases()` and `PointInTimeRecovery::storageProvider()`. Links keep working after a move, and deletion guards count users in every project. `DatabaseNode::server()` stays scoped because a cluster moves as one unit.
- **Providers are strictly per project.** Nothing is shared; a token is added again in each project that needs it. Provider names are unique per project (`unique(['project_id', 'name'])`). Ids sent in a request for the six models are checked with `ExistsInProject`, which runs the scoped Eloquent query.
- **Moving:** `POST /{project}/moves` (`MoveToProject`) moves a server, network or provider alone. A database moves with every server behind its nodes; a server that belongs to a database is refused. The six models implement `App\Contracts\Projects\Movable` (`ensureMovable()`, `movesAlongWith()`, `moveTo()`), with defaults in the `MovesBetweenProjects` trait; `Server` overrides the refusal and `Database` returns its servers. The request's `type` is the model's morph alias (`server`, `database`, `network`, `server-provider`, `dns-provider`, `storage-provider`), registered with `Relation::morphMap()` in `AppServiceProvider`.
- **Not an authorization boundary.** Databasify has one owner ([0016](0016-app-architecture.md)); projects only organise resources.
- Setup creates a `Default` project. `project_id` and `users.last_project_id` are declared in the original create migrations (the app is unreleased), so there is no backfill.
- **Future API and MCP: the token picks the project.** Not built yet; this is the agreed shape.
    - URLs carry no project: the API lives at `/api/v1/…` (`/api/v1/servers`, `/api/v1/servers/{server}`) and MCP at one `/mcp` endpoint.
    - Every API token belongs to one project (Sanctum personal access tokens with a `project_id` column), created from inside that project.
    - A `SetTokenProject` middleware runs after `auth:sanctum`, reads the project of `currentAccessToken()` and initializes `ProjectContext`. It takes the same priority slot as `SetCurrentProject`, before `SubstituteBindings`, so route binding, lists, creates, `ExistsInProject` and the per-project name rules are scoped exactly as on the web. It does not set URL defaults or `users.last_project_id`.
    - MCP clients authenticate with the same project tokens through the same middleware, so tools take no `project` argument.
    - API endpoints and MCP tools reuse the same actions, DTOs and refusals as the web, and always delete through the actions, never `->delete()` directly ([0032](0032-delete-rules-in-model-observers.md)).
    - Work that spans projects (listing or creating projects) stays web-only until an unscoped account-level token type is decided.

| Surface            | Project comes from       | Starts the context  |
| ------------------ | ------------------------ | ------------------- |
| Web `/{project}/…` | the URL                  | `SetCurrentProject` |
| API `/api/v1/…`    | the token's `project_id` | `SetTokenProject`   |
| MCP `/mcp`         | the token's `project_id` | `SetTokenProject`   |

## Consequences

- Controllers, queries and actions stay unchanged; new project-page code gets scoping for free from the trait.
- Code that runs inside a project request must not look up `project_id` itself. Code that creates one of the six models outside a request must set `project_id` explicitly, because the column is `NOT NULL`.
- A query on a child model from a project page must go through its parent, or it leaks other projects.
- A new relation between the six models must decide whether it is a dependency link (unscoped) or a listing (scoped).

## Alternatives considered

- **Explicit `Project` parameters and `/projects/{id}/…` URLs with shared providers:** rejected. It touched every controller and query, and sharing providers across projects made the delete and visibility rules complicated.
- **A project column on child models:** rejected, because children always live with their parent.
- **The project in API and MCP URLs (`/api/v1/{project}/…`):** rejected by the owner on 2026-11-07 in favour of project-owned tokens; the token already says which project a call belongs to.

## Evidence

- `app/Support/Projects/ProjectContext.php`
- `app/Models/Scopes/ProjectScope.php`
- `app/Models/Concerns/BelongsToProject.php`
- `app/Http/Middleware/SetCurrentProject.php`
- `app/Rules/ExistsInProject.php`
- `app/Actions/Projects/MoveToProject.php`
- `app/Actions/Projects/DeleteProject.php`
- `app/Queries/Projects/CurrentProjectQuery.php`
- `routes/web.php`
- `bootstrap/app.php`
- `app/Providers/AppServiceProvider.php`
- `app/Contracts/Projects/Movable.php`
- `app/Models/Concerns/MovesBetweenProjects.php`
- `database/migrations/2026_10_20_000001_create_projects_table.php`
