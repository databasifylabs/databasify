# 0004. Engine images pinned by digest per database

- Status: Accepted
- Date: 2026-11-04

## Context

Nodes of one database must run byte-identical engine images, or replication and failover can break. A floating tag such as `postgres-18` can move between two node deploys.

## Decision

When a database is created, `ImageResolver::newest()` asks the registry (`ghcr.io/databasifylabs`, overridable with `DATABASIFY_IMAGE_REGISTRY`; an anonymous bearer token is fetched only after a 401) for the newest exact tag of `databasify-<engine>` in the version line. Tags must match `<line>.N[.N…]` and are ranked with `version_compare`. An image built on an SDK outside the app's range is refused ([0040](0040-one-sdk-range-per-app-release.md)). The repository, digest, release and SDK are stored on the `databases` row (`image_*` columns). Every node pulls `repo@digest`. A daily check stores a newer image in `available_image_*` until it is installed.

## Consequences

- `EngineImage::replaces()` decides whether an update is offered: a newer release in the line, or the same release with a new digest. `refreshes()` labels the same-release case as "Refresh image" instead of an upgrade.
- Restores reuse the source database's image.
- The binary inside the image is the one that runs; the app installs no other ([0038](0038-engines-ship-only-as-images.md)).
- The app repository holds no digest list. The engine repository publishes images, and the app discovers them.

## Alternatives considered

- **Floating tags:** rejected, because nodes could drift.
- **A digest lock file committed in the app:** dropped. It forced an app release for every image rebuild.

## Evidence

- `app/Services/Images/ImageResolver.php`
- `app/Services/Images/OciRegistry.php`
- `app/DTOs/Databases/EngineImage.php`
- `app/Engines/Requests/HostDeploy.php`
- `config/databasify.php`
