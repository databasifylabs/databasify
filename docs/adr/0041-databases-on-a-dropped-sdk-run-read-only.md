# 0041. Databases on a dropped SDK run read-only

- Status: Accepted
- Date: 2026-11-11

## Context

When an app release moves `databasify.require.engine-sdk` past an SDK (0040), databases already running an image built on it keep running that image. Most often it is a community engine whose author has not rebuilt yet. The app can't promise its commands still speak that image's contract, but stopping or refusing to watch the database would put data at risk.

## Decision

- `Database::runsSupportedSdk()` checks the SDK of the current image against the range; a missing SDK is `unknown` and never supported.
- `Database::ensureIdle()` also calls `ensureRunsSupportedSdk()`, so every change that waits for an idle database is refused: settings, retune, persistence, adding, removing and renaming nodes, connection pools, trusted sources, domains, point-in-time recovery settings and migrating data in. The account actions (users, databases, passwords) call it directly. The refusal reads "<name> runs an engine image built on SDK <sdk>, which this Databasify no longer supports. Wait for <author> to publish a new image.", with the engine's plugin author, or Databasify for a built-in engine.
- Status, metrics, logs, backups (`BackUpNow`), rotating the keys of its backup storage (`UpdateStorageProvider`), failover, outage recovery (`RecoverDatabase`, including rejoining members), rebooting its servers (`RebootDatabase`), delete and migrating out keep working: they only check that the database isn't busy. `DeployNode` checks the SDK the engine reports only while the database's recorded image is supported, so redeploying a node of a read-only database on its current image works.
- A point-in-time restore from a read-only database creates the new database on the newest supported image of the line instead of the source's image.
- `UpgradeDatabase` still runs when the offered image is inside the range, so the weekly maintenance window moves the database to the newest supported image of its line and the block lifts.
- The database page shows a banner (`sdkUnsupported`: sdk, range, author) and the dashboard lists every read-only database. Before an app upgrade, `php artisan databases:check-sdks --range=<constraint>` lists the databases that range would make read-only (the app has no upgrade screen).

## Consequences

- An admin can always back up, restore, fail over, recover, reboot and delete a database whose engine was left behind, but can't change it until a new image exists.
- Pre-SDK images carry no label, so a database still on one is read-only until it moves to an SDK image.

## Alternatives considered

- **Blocking outage recovery too:** rejected; it would keep a database down when the cluster loses its majority, only because its image is old.
- **Stopping the database:** rejected; nothing in the old image is known to be broken, and stopping risks the data.

## Evidence

- `app/Models/Database.php`
- `app/Support/Engines/SdkRange.php`
- `app/Actions/Databases/Accounts/`
- `app/Actions/Databases/Recovery/BackUpNow.php`
- `app/Actions/Databases/Recovery/RestoreToPointInTime.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Nodes/RebootDatabase.php`
- `app/Actions/Storage/UpdateStorageProvider.php`
- `app/Actions/Databases/Upgrades/FindUnsupportedSdkDatabases.php`
- `app/Console/Commands/Databases/CheckEngineSdksCommand.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/Databases/DatabaseController.php`
- `resources/js/components/databases/unsupported-sdk-alert.tsx`
- `resources/js/components/dashboard/unsupported-sdk-card.tsx`
