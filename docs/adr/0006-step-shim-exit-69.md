# 0006. Engine steps run through a host shim; exit 69 means "container not running"

- Status: Accepted
- Date: 2026-11-04

## Context

Some node work belongs on the host (Docker, firewall, kernel tuning). Engine work must run inside the container, where the engine's tools and files are. The app needs to tell "the step failed" apart from "the container is not running".

## Decision

Views under `ssh.engines.*` that `Ssh` (`$server->ssh()`) runs are engine steps: it base64-pipes them through `resources/views/ssh/databases/containers/step.blade.php` into `docker exec -i <container> databasify step <name>`, and refuses to run one without a container. Host work stays under `ssh/servers/**` and `ssh/databases/**`. Engine host fragments (`config.host-tuning`, `lifecycle.cleanup`) are `@include`d into host scripts instead of running in the container.

The shim exits with `69` (`Ssh::CONTAINER_NOT_RUNNING`) when the container is not running. A 69 returned by the inner script is rewritten to 1, so only the shim can signal it.

## Consequences

- `RefreshDatabaseStatus` maps 69 to a stopped node instead of a failure.
- With `startContainer`, the shim starts the container and waits up to 60 seconds for `/run/databasify.sock` (used by `PromoteSurvivor`). If the start fails or the socket never appears, it also exits 69.
- A script is named by its view path (for example `cluster.failover`) inside the folder of the database's version line ([0029](0029-engine-scripts-per-version-line.md)); the step name the shim passes is the view path after `ssh.engines.` (for example `mysql.lines.8_4.cluster.failover`). A missing engine script throws `MissingEngineScript`; engines skip steps explicitly in their group subclasses ([0026](0026-database-driver-groups.md)).

## Alternatives considered

- **Running engine tools on the host:** rejected by [0002](0002-container-per-node.md).

## Evidence

- `app/Services/Ssh/Ssh.php`
- `resources/views/ssh/databases/containers/step.blade.php`
- `app/Actions/Databases/Failover/PromoteSurvivor.php`
