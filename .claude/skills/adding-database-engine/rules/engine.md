# Phase 1: the engine package (engine repository)

The engine is a Go package on the public engine SDK, in its own repository created from [`databasifylabs/engine-template`](https://github.com/databasifylabs/engine-template). Paths here are from the root of that clone. Before writing any, read:
- the closest built-in engine's `README.md`, for its flows;
- [`engine-sdk`](https://github.com/databasifylabs/engine-sdk)'s `README.md` (the command contract and the versioning);
- the SDK's ADRs (`docs/adr/`).

## Files

| Path                                   | Purpose                                                                                                                                                                         |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `engine/`                              | The package. `New()` returns a value implementing the SDK's `contract.Engine`. Split it by concern like the templates: `node.go`, `cluster.go`, `accounts.go`, `archive.go`, `migration.go`, `config.go`, `catalog.go` (settings), `files.go` (config files), `manifest.go` (processes), `status.go`, `insights.go`, each with a `_test.go`. |
| `cmd/databasify/main.go`               | `os.Exit(sdk.Run(<engine>.New()))`. `Name()` returns the image's `io.databasify.engine` label, which is also the app's engine id.                                                |
| SDK `requests`, `responses`, `dtos`    | An `engine-sdk` pull request, only when the engine needs a field no engine had. Shared types never grow engine-specific fields: those go in the request's `options` object, which the engine validates. |
| `README.md`                            | The engine's lines, features, ports and flows, with every engine-specific step. The app's flows follow it.                                                                       |
| `docs/adr/`                            | An ADR for each lasting choice the engine makes (its HA mechanism, backup and migration tools), like 0004, 0005 and 0007.                                                       |
| `go.mod`, `versions.json`              | The `engine-sdk` version in `go.mod` and the `sdk` pin in `versions.json` must match (`tools/images.sh check`). Move both only to an SDK release inside the app's range.          |

## Groups

`contract.Engine` has one accessor per group: `Nodes`, `Cluster`, `Accounts`, `Archive`, `Migration`, `Pools` and `Config`, plus `Name()`, `Capabilities()` and `HostProfile()` (the sysctl settings, floors and transparent hugepages choice `host tune` applies).

- **Nodes and Cluster are required.** The app's create, add-node, remove-node, rolling restart, upgrade and recovery flows call them on every engine. `Ready`, `Cluster.Enable`, `Cluster.Rejoin` and `Archive.Restore` are long commands: they report phases through `report` and run in a transient unit, and the app polls `operation status`.
- **Every other group is optional.** Return the matching `contract.Unsupported*` stand-in for a group the engine lacks; its commands answer `unsupported` and `engine capabilities` leaves them out, so the app hides the feature. Offer a group only when the engine fully supports it, or implement `contract.Partial` on it to offer only the commands its `Implements()` lists.
- **Capabilities** list the password encryptions (and the default), reserved users and databases, and the ports (client, pool, peers). The app reads them once after the first deploy and stores them on the database.
- Every command is safe to repeat. The app retries a lost operation under a new key, and a crashed app worker resends the same step.
- Clusters manage their own writer (ADR 0008): the engine's HA mechanism picks and fences the primary; `cluster failover` and `cluster force-quorum` ask it to.

## Settings and tuning

- `catalog.go` declares the fields users may edit with the SDK's `settings` package (`settings.Integer`, `Choice`, `Text`, `.Restarting()`, `.TunedFromServer()`, `.In("MB")`). Offer exactly what DigitalOcean offers for that engine.
- Tuned values come from the server's CPU and memory (`config tune`) and stay automatic. `config fields` and `config show` are what the app's Settings tab renders.
- A setting that differs per line reads `DATABASIFY_LINE` (set in the image), as `engine-sentinel-family`'s `catalog.go` does for `io-threads`.

## Processes and files

- `manifest.go` writes `/srv/<engine>/conf/processes.json` for the engine binary's supervisor (the SDK's `manifest` package). HA processes appear only once the database has a peer; backup agents only while point-in-time recovery is on.
- Guard the server process on its data directory, so an erase or a lost disk doesn't leave it in a restart loop.
- Don't set environment the image already sets: a process's `env` overrides the container's.
- No passwords in argv or the manifest. Secrets travel only on stdin and are written as 600 files owned by the engine user.

## Accounts, backups and migrations

- **Accounts:** users, databases and grants (`read`, `write`) through the engine's own client. Refuse reserved names with `invalid_request`, and never echo a password back.
- **Archive:** the backup tool's config, scheduled backups gated on the primary, `archive status` with the earliest and latest restorable time, and `restore start`. When a bundled tool doesn't support a line yet, ship the line and say so in the pull request, so the app registers it with `'recovery' => false`.
- **Migration:** `migration check` returns facts and blockers, `migration start` runs the copy in a transient unit, and `migration progress` reports lag as the unapplied backlog, so an idle source reads 0.
- Read third-party tools' source for how they build connection strings before relying on query options, and check which engine versions each tool supports.

## Verify

Run what CI runs first (the SDK's `engine-images.yml`, called by `.github/workflows/images.yml`):

```bash
go vet ./...
go test -race -count=1 ./...
```

Then phase 2 builds the image and runs the conformance suite against a live container.
