# 0038. Engines ship only as images

- Status: Accepted
- Date: 2026-11-11

## Context

[0036](0036-pinned-wrapper-version.md) and [0037](0037-follow-the-pinned-wrapper-digest.md) installed one shared wrapper binary over every engine image with `host wrapper-update`, pinned by the app and followed by digest. That made two moving parts per node: the engine image and a binary replaced inside it. The binary a node ran could differ from the one its image was built and tested with, and every change had to wait for the app to resolve and install the right build.

## Decision

- The binary inside each engine image is the one that runs. The app no longer installs, resolves or records a wrapper: `host wrapper-update`, `WrapperResolver`, `SyncDatabaseWrapperJob` and the `databases.wrapper_version` and `databases.wrapper_digest` columns are gone.
- Fixes to the binary ship as new images in the weekly refresh window, like any other engine change.
- `Database::ensureNotBusy()` refuses a change while the database is busy or migrating. `Database::ensureIdle()` is the entry point for change actions and for now only calls it.
- The binary on the host itself is the pinned official `ghcr.io/databasifylabs/databasify-host:<version>` image (`databasify.sdk.host`), resolved to a digest by `HostBinaryResolver` and installed by `InstallHostBinary` before every deploy; it is replaced only when its bytes differ.

## Consequences

- One artifact per node to build, scan and reason about; a database always runs the binary its image shipped with.
- A binary fix reaches a database only through an image refresh, not within minutes.

## Alternatives considered

- **Keeping the wrapper channel:** rejected; it duplicated what an image refresh already delivers and let a node run a binary its image was never tested with.

## Evidence

- `app/Models/Database.php`
- `app/Actions/Databases/Lifecycle/CreateDatabase.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Nodes/InstallHostBinary.php`
- `app/Services/Images/HostBinaryResolver.php`
- `app/DTOs/Images/HostBinaryImage.php`
- `resources/views/ssh/databases/nodes/install-host-binary.blade.php`
- `config/databasify.php`
- `app/Engines/Flows/UpgradeFlow.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
