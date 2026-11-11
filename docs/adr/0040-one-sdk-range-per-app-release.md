# 0040. Each app release runs one engine SDK range

- Status: Accepted
- Date: 2026-11-11

## Context

Every engine image is built on a version of the engine SDK and carries it in its `io.databasify.sdk` label. Before `1.0` any SDK minor may break the contract, and community engines are rebuilt on their author's schedule, so the app can meet an image built on an SDK it does not speak.

## Decision

- The app declares the SDK versions it runs as one Composer-style constraint, `databasify.sdk.range` (`^0.1`: one `0.x` minor per app release before `1.0`, then `^1.0`, and `^1.0 || ^2.0` during an overlap). `SdkRange::allows()` checks a version against it with `VersionConstraint`, ignoring a leading `v` and `+build` metadata; a missing version is never allowed.
- `ImageResolver::newest()` reads the labels of the chosen digest (`ImageRegistry::labelsOf()`; for an index, the `linux/amd64` image's config) and refuses an image outside the range with `ImageUnavailable::unsupportedSdk()` before anything is pulled: "<Engine> <release> is built on SDK <sdk>; this Databasify supports <range>.", with `unknown` for a missing label. Creating a database shows this message as is.
- `DeployNode` asks the deployed engine for `engine info` after `host deploy` and fails the deploy when its `sdk` is outside the range.
- The weekly upgrade check (`CheckDatabaseUpgrade`) offers nothing for a refused image, keeps the current offer and image, and raises a `DatabaseAlertRaised` warning that names both versions.
- Each database stores the SDK of its current and offered images (`image_sdk`, `available_image_sdk`).

## Consequences

- An SDK break can only reach a database through an app release that widens the range.
- Each refused upgrade check raises the warning again until a supported image is published.

## Alternatives considered

- **`~0.1`:** rejected; in Composer terms it allows every `0.x` from `0.1`, so a breaking `0.2` image would pass.
- **Checking only `engine info` at deploy:** rejected; it fails after the image is already pulled and the node touched.

## Evidence

- `app/Support/Engines/SdkRange.php`
- `app/Services/Images/ImageResolver.php`
- `app/Services/Images/OciRegistry.php`
- `app/Exceptions/Images/ImageUnavailable.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Upgrades/CheckDatabaseUpgrade.php`
- `config/databasify.php`
