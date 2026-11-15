# 0012. Projects as a global scope

- Status: Accepted
- Date: 2026-11-15

## Context

The owner wants to group servers, databases and providers into projects, with a few pages staying global and resources able to move between projects. Passing a `Project` into every controller, query and action would touch most of the app.

## Decision

- **URL shape.** Resource routes sit under `/{project}` through the `Route::project()` macro, which applies `auth`, `verified`, the `SetCurrentProject` middleware, the prefix and a UUID constraint. Route names stay unprefixed. Projects, plugins, settings, setup and Horizon stay global.
- **Context and scope.** `SetCurrentProject` runs before route-model binding and initializes `ProjectContext`. `ProjectScope` filters on `project_id` only while the context is initialized, and `BelongsToProject` adds it to `Server`, `Database`, `Network`, `ServerProvider`, `DnsProvider` and `StorageProvider` and fills `project_id` on create. Child models have no project column and are reached through their parent.
- **What stays global.** Queued jobs, scheduled commands and Horizon never initialize the context, so they see every project. Names that feed DNS hostnames and the WireGuard pool are unique across projects. Relations between a resource and the resources it depends on drop the scope, so links survive a move and deletion guards count every project.
- **Providers are strictly per project**; ids sent in a request are checked with `ExistsInProject`.
- **Moving.** `MoveToProject` moves a server, network or provider alone; a database moves with every server behind its nodes (`Movable`).

## Consequences

- Controllers, queries and actions stay unchanged; new project-page code is scoped by the trait.
- Code that creates a scoped model outside a request must set `project_id` itself.
- A query on a child model from a project page must go through its parent, or it leaks other projects.
- A new relation between the six models must decide whether it is a dependency link (unscoped) or a listing (scoped).

## Alternatives considered

- **Explicit `Project` parameters with shared providers:** rejected, it touched every controller and complicated the delete and visibility rules.
- **A project column on child models:** rejected, children always live with their parent.

## Evidence

- `app/Support/Projects/ProjectContext.php`
- `app/Models/Scopes/ProjectScope.php`
- `app/Models/Concerns/BelongsToProject.php`
- `app/Http/Middleware/SetCurrentProject.php`
- `app/Providers/AppServiceProvider.php`
- `app/Rules/ExistsInProject.php`
- `app/Actions/Projects/MoveToProject.php`
- `app/Contracts/Projects/Movable.php`
- `app/Actions/Networks/CreateWireguardNetwork.php`
- `bootstrap/app.php`
