# 0031. Each app release runs one engine SDK range

- Status: Accepted
- Date: 2026-11-11

## Context

Every engine image is built on a version of the engine SDK and carries it in its `io.databasify.sdk` label. Before `1.0` any SDK minor may break the contract, and community engines are rebuilt on their author's schedule, so the app can meet an image built on an SDK it does not speak.

## Decision

- The app declares the SDK versions it runs as one Composer-style constraint in a `require` block, `databasify.require.engine-sdk` (`^0.2`: one `0.x` minor per app release before `1.0`, then `^1.0`, and `^1.0 || ^2.0` during an overlap). `SdkRange::allows()` checks a version against it with `VersionConstraint`, ignoring a leading `v` and `+build` metadata; a missing version is never allowed.
- `ImageResolver::imagesOf()` walks the line's exact `<line>.x` releases newest first and reads the labels of each digest until one is inside the range (`ImageRegistry::labelsOf()`; for an index, the `linux/amd64` image's config, else the first image that is not an `unknown/unknown` attestation) `ImageResolver::newest()` returns that newest supported release, so a newer release built on another SDK never blocks an older supported one. Only when no release of the line is inside the range does it refuse, naming the newest release, with `ImageUnavailable::unsupportedSdk()` before anything is pulled: "<Engine> <release> is built on SDK <sdk>; this Databasify supports <range>.", with `unknown` for a missing label. Creating a database shows this message as is. `SdkRange::supported()` checks every image a create uses, including the source image a point-in-time restore reuses (a restore from a database on a dropped SDK takes the newest supported image of the line instead, 0032), and `UpgradeDatabase` checks a stored offer before it starts, so neither starts any work on a refused image.
- `DeployNode` asks the deployed engine for `engine info` after `host deploy` and fails the deploy when its `sdk` is outside the range, unless the database's recorded image is itself already outside it (a read-only database, 0032); an empty or missing `sdk` is reported as `unknown`.
- The weekly upgrade check (`CheckDatabaseUpgrade`) offers the newest supported release when it replaces the current image, and keeps the current offer when no release is supported. When the newest release is refused it raises a `DatabaseAlertRaised` warning that names both versions, once per refused image: the digest is kept in the cache under `sdk-refused:{database id}` for 30 days and forgotten once the newest release is supported again.
- Each database stores the SDK of its current and offered images inside their `EngineImage` (`image`, `available_image`).

## Consequences

- An SDK break can only reach a database through an app release that widens the range.
- Admins get one warning per refused image, not one per check; a newer refused release or rebuild warns again.

## Alternatives considered

- **`~0.1`:** rejected; in Composer terms it allows every `0.x` from `0.1`, so a breaking `0.2` image would pass.
- **Checking only `engine info` at deploy:** rejected; it fails after the image is already pulled and the node touched.

## Evidence

- `app/Support/Engines/SdkRange.php`
- `app/Services/Images/ImageResolver.php`
- `app/DTOs/Images/LineImages.php`
- `app/Services/Images/OciRegistry.php`
- `app/Exceptions/Images/ImageUnavailable.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Upgrades/CheckDatabaseUpgrade.php`
- `app/Actions/Databases/Upgrades/UpgradeDatabase.php`
- `app/Actions/Databases/Lifecycle/CreateDatabase.php`
- `config/databasify.php`
