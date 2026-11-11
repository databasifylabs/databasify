# 0036. Each app release pins one wrapper version

- Status: Superseded by 0038
- Date: 2026-11-10

## Context

All engine logic lives in the `databasify` wrapper inside each engine image ([0035](0035-engine-commands-and-flows.md)). A wrapper fix used to need a new image for every engine line and a rolling image refresh that restarts every node. Copying a new wrapper into a running container and renaming it over the old one is safe: the supervisor keeps its old inode and every engine command is a new process. The app and the wrapper also have to agree on the commands between them, and an app that picks up wrappers on its own can receive one it cannot talk to.

## Decision

- The engine repository publishes the wrapper alone as `ghcr.io/databasifylabs/databasify-wrapper` (overridable with `DATABASIFY_WRAPPER_REPOSITORY`), tagged with its version, such as `0.1.1`. The version is raised with every change to the wrapper's code and a published version never moves.
- The app pins exactly one version in `databasify.wrapper.version`. It resolves the tag to a digest once and caches it, so every node gets the same bytes.
- Each database stores the version its nodes run in `databases.wrapper_version`. A new database records the pinned version.
- Every `host deploy` that creates or replaces a container runs `host wrapper-update` with the pinned version straight after, so new nodes, redeploys and image refreshes keep the pinned wrapper. A finished image refresh records the pinned version.
- Nothing runs on a schedule. After an app update with a new pin, the first change someone starts on a database that runs another version is refused by `Database::ensureIdle()` with a message naming the version being installed, and it queues `SyncDatabaseWrapperJob`. The job starts `UpdateWrapperFlow`, which shows as the "Install databasify <version>" operation with a server log line per node: `host wrapper-update` on every node, read-only nodes and standbys first, with no failover, as finish steps that retry on the next tick. Its last step records the version, and the change can then be started again.
- Delete, retry setup, failover, recovery, image refresh and server removal use `ensureIdleOnAnyWrapper()`, so they stay possible on an older wrapper.
- Image refreshes (`UpgradeFlow`) remain the path for engine version changes. They run `node configure` after `host deploy` and before `node ready`.

## Consequences

- The app and the wrapper always move together; there is no compatibility matrix, no schedule and no polling of the registry.
- A database nobody touches keeps its older wrapper until its first change or image refresh.
- A wrapper fix reaches a self-hosted install only with an app release that raises the pin.
- Nothing checks that an engine image has every tool or path a wrapper version needs; the engine repository ships such needs as an image refresh first. The engine runs the new binary before it replaces the old one.
- The supervisor and long-running helper processes keep the old wrapper until their next restart.

## Alternatives considered

- **App-side SSH scripts for hotfixes:** rejected, it brings back engine logic in the app ([0035](0035-engine-commands-and-flows.md)).
- **A scheduled check for the newest wrapper, matched by API level and contract number:** built and dropped. It needed tag channels, a contract range in the app and a window where app and wrapper disagree.
- **A single app-wide version in the settings table:** rejected; one unreachable server would hold every database. The version is tracked per database.

## Evidence

- `config/databasify.php`
- `app/Services/Images/WrapperResolver.php`
- `app/Actions/Databases/Nodes/InstallPinnedWrapper.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Upgrades/SyncDatabaseWrapper.php`
- `app/Jobs/Databases/SyncDatabaseWrapperJob.php`
- `app/Engines/Flows/UpdateWrapperFlow.php`
- `app/Engines/Flows/UpgradeFlow.php`
- `app/Models/Database.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
