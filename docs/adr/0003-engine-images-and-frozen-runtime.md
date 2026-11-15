# 0003. Engines ship as pinned images on a frozen runtime

- Status: Accepted
- Date: 2026-11-15

## Context

Nodes of one database must run byte-identical software, or replication and failover can break. Engine behaviour used to live in Go inside the images, so a small change needed an SDK release, rebuilds in every engine repository and an app release. Most contributors are Laravel developers. Community engines are rebuilt on their authors' schedule, so the app can meet an image built on an SDK it does not speak.

## Decision

- **Images carry no engine logic.** An engine image is the official database image plus its tools plus the runtime from the public engine SDK (`databasifylabs/engine-sdk`), which runs as PID 1 and supervises the processes listed in `conf/processes.json`. The app writes that manifest when it configures a node ([0004](0004-engine-drivers-and-blade-scripts.md)). Engine behaviour ships as app releases.
- **The runtime is frozen.** It answers engine-neutral commands: `runtime run`, `task start/status/log/stop`, `file put/get`, `logs read`, `engine info`, `host deploy` and `host remove`. Scripts run from stdin, never argv, with capped output and a timeout of at most 3600 s that kills the whole process group. Tasks run under a caller-chosen key, so starting a key again returns the existing task. File paths stay inside the engine home. The protocol only gains commands, and new ones must stay rare and engine-neutral.
- **Engine user.** Engine processes and files are owned by uid/gid 64999 inside and outside the container, and data, configuration, TLS material and logs live under `/srv/<engine>`. Every image remaps its database user to 64999.
- **Pinned by digest per database.** When a database is created, `ImageResolver` picks the newest exact `<line>.N` tag of `databasify-<engine>` in the registry (`databasify.registry`, default `ghcr.io/databasifylabs`) whose SDK is supported, and stores repository, digest, release and SDK as one `EngineImage` on the database. Every node pulls `repo@digest`. Restores reuse the source's image. The app holds no digest list.
- **Only images ship.** The runtime inside the image is the one that runs. The host itself runs the official `engine-sdk` host binary, pinned by digest (`HostBinaryResolver`) and installed before each deploy. A deploy refuses an image built for another engine.
- **One SDK range per app release.** `databasify.require.engine-sdk` (`^0.2.2 || ^1.0`) is checked by `SdkRange`. An image outside it is refused before anything is pulled, and `DeployNode` checks the SDK the deployed engine reports. A daily check (`CheckDatabaseUpgrade`) offers the newest supported release, and warns once per refused image; offered images install in the database's weekly maintenance window.
- **Databases on a dropped SDK run read-only.** `Database::ensureIdle()` refuses every change to a database whose image is outside the range. Status, metrics, logs, backups, failover, outage recovery, reboots, redeploys on the current image, delete and migrating out keep working. A restore from such a database uses the newest supported image of its line. `php artisan databases:check-sdks --range=<constraint>` lists the databases a range would make read-only.

## Consequences

- One artifact per node to build, scan and reason about; a runtime fix reaches a database only through an image refresh.
- Every need the runtime cannot cover means an image change, so engine work stays in app scripts.
- An SDK break can only reach a database through an app release that widens the range.
- An admin can always back up, restore, fail over, recover and delete a database whose engine was left behind, but cannot change it until a new image exists.

## Alternatives considered

- **Floating tags:** rejected, nodes could drift. **A digest lock file in the app:** dropped, it forced an app release for every image rebuild.
- **A wrapper binary installed over every image:** removed, a node could run a binary its image was never tested with.
- **Keeping engine logic in Go:** rejected by the owner, every change needed the SDK, each engine repository and the images.
- **Stopping, or blocking recovery of, a database on a dropped SDK:** rejected, it puts data at risk although nothing in the old image is known to be broken.

## Evidence

- `app/Engines/Runtime/Runtime.php`
- `app/Services/Images/ImageResolver.php`
- `app/Services/Images/OciRegistry.php`
- `app/Services/Images/HostBinaryResolver.php`
- `app/DTOs/Databases/EngineImage.php`
- `app/Casts/AsEngineImage.php`
- `app/Support/Engines/SdkRange.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Nodes/InstallHostBinary.php`
- `app/Actions/Databases/Upgrades/CheckDatabaseUpgrade.php`
- `app/Console/Commands/Databases/CheckEngineSdksCommand.php`
- `app/Models/Database.php`
- `resources/views/engines/_write.blade.php`
- `config/databasify.php`
