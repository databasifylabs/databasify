# 0036. Wrapper fixes ship on their own, matched by API level

- Status: Accepted
- Date: 2026-11-10

## Context

All engine logic lives in the `databasify` wrapper inside each engine image ([0035](0035-engine-commands-and-flows.md)). A wrapper fix therefore needed a new image for every engine line and a rolling image refresh that restarts every node, which is far slower than the app-side SSH scripts it replaced. End-to-end testing showed that copying a new wrapper into a running container and renaming it over the old one is safe: the supervisor keeps its old inode and every engine command is a new process.

## Decision

- The engine repository publishes the wrapper alone as `ghcr.io/databasifylabs/databasify-wrapper` (overridable with `DATABASIFY_WRAPPER_REPOSITORY`), tagged `api-<level>`. `host wrapper-update {image}` installs it into the running container and onto the host by copy and rename, then runs `node configure` from the node's saved request. Nothing restarts.
- **Compatibility rule:** each engine image carries a wrapper API level (`io.databasify.wrapper-api`, reported as `wrapperApi` by `engine capabilities`). The engine repository raises the level whenever the wrapper starts to need a tool, package, path, file, user or supervisor protocol an older image lacks. A wrapper is only installed on an image of exactly its level:
    - the app only offers a database the `api-<level>` wrapper of the level stored in its `engine_capabilities`, and skips level 0 (images built before the label);
    - the engine refuses another level with `unsupported` before it changes anything.
- `databases:update-wrappers` runs every five minutes and queues `UpdateDatabaseWrapperJob` for each ready database without a running flow. `UpdateDatabaseWrapper` resolves the digest of `api-<level>` (cached for four minutes per level) and, when it differs from `databases.wrapper_image`, stores it and starts `UpdateWrapperFlow`: `host wrapper-update` on every node, read-only nodes and standbys first, with no failover. Each step is a finish step, so a failed node is retried on the next tick. A busy or migrating database waits for the next check.
- `wrapper_image` is cleared whenever `host deploy` creates or replaces a container, because that container runs its image's own wrapper; the next check pushes the newest one again.
- Image refreshes (`UpgradeFlow`) remain the path for engine version changes. They now run `node configure` after `host deploy` and before `node ready`, so the new image's config rendering lands too.

## Consequences

- A wrapper fix reaches every compatible database within about ten minutes of being published, with no restart and no image build.
- The supervisor and long-running helper processes keep the old wrapper until their next restart; a fix in that code still needs an image refresh or restart.
- A database on an older level only gets wrapper fixes through its next image refresh.
- The level is a judgement made when changing the wrapper. The engine checks that the new binary runs and reports its version before it replaces the old one, which limits the damage of a wrong call.

## Alternatives considered

- **App-side SSH scripts for hotfixes:** rejected, it brings back engine logic in the app ([0035](0035-engine-commands-and-flows.md)).
- **An image refresh for every wrapper fix:** correct, but slow and it restarts every node.
- **Comparing wrapper versions semantically:** rejected; the digest of `api-<level>` is enough, and the engine skips the copy when that version already runs.

## Evidence

- `app/Actions/Databases/Upgrades/UpdateDatabaseWrapper.php`
- `app/Console/Commands/Databases/UpdateDatabaseWrappersCommand.php`
- `app/Jobs/Databases/UpdateDatabaseWrapperJob.php`
- `app/Engines/Flows/UpdateWrapperFlow.php`
- `app/Engines/Flows/UpgradeFlow.php`
- `app/Engines/Requests/HostWrapperUpdate.php`
- `app/Services/Images/WrapperResolver.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `config/databasify.php`
- `database/migrations/2026_11_10_112206_add_wrapper_image_to_databases_table.php`
