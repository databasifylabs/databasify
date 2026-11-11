# 0037. Follow the digest of the pinned wrapper version

- Status: Superseded by 0038
- Date: 2026-11-10

## Context

[0036](0036-pinned-wrapper-version.md) pinned one wrapper version per app release and assumed a published version never moves, so the app resolved its digest once and cached it forever, and each database stored only the version it ran. The engine repository now republishes a changed wrapper under the same version tag until the app's first release, so `0.1.0` moves to a new digest with every fix. With the old behaviour the app kept installing the first `0.1.0` build over newer images, and a moved tag never reached a database that already recorded `0.1.0`.

## Decision

- The app still pins one version in `databasify.wrapper.version`. `WrapperResolver::pinned()` resolves the tag to a digest and caches it for ten minutes, so a moved tag is followed within that time. `WrapperResolver::cached()` reads the cache without asking the registry.
- Each database stores the version and the digest its nodes run (`databases.wrapper_version`, `databases.wrapper_digest`). A new database records the pinned build when it is created, and a finished image refresh or wrapper install records the build it installed.
- `Database::ensureIdle()` compares the database's build with the cached pinned build. A different version or digest refuses the change, naming the version being installed, and queues `SyncDatabaseWrapperJob`. With nothing cached, the change goes ahead and the job is queued to resolve the digest and install if needed.
- Everything else in [0036](0036-pinned-wrapper-version.md) stands: `host wrapper-update` after every `host deploy` that creates or replaces a container, `UpdateWrapperFlow` on every node without failover, nothing on a schedule, and `ensureIdleOnAnyWrapper()` for delete, retry setup, failover, recovery, image refresh and server removal.

## Consequences

- A wrapper fix published under the pinned version reaches a database at its next change, at most ten minutes after the tag moves, with no app release.
- The registry is asked at most once per ten minutes per app, and only when an install or a create needs the digest.
- A broken build pushed under the pinned version also spreads; the engine's run check before the rename is what guards a node.

## Alternatives considered

- **Re-resolving on a schedule:** rejected; the user wants no automatic sync, and a ten-minute cache checked on demand needs no schedule.
- **Comparing versions only:** cannot see a moved tag.

## Evidence

- `app/Services/Images/WrapperResolver.php`
- `app/DTOs/Images/WrapperImage.php`
- `app/Models/Database.php`
- `app/Actions/Databases/Upgrades/SyncDatabaseWrapper.php`
- `app/Actions/Databases/Nodes/InstallPinnedWrapper.php`
- `app/Actions/Databases/Lifecycle/CreateDatabase.php`
- `app/Engines/Flows/UpdateWrapperFlow.php`
- `app/Engines/Flows/UpgradeFlow.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
