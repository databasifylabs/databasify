# 0038. Read-only nodes are managed on their own

- Status: Accepted
- Date: 2026-11-14

## Context

A database can have up to 47 read-only nodes. Until now they shared everything with the primary and standbys: one trusted-sources list, one config, one set of connection pools and one busy status. A config change on the main restarted every read-only node, and a change aimed at one read-only node blocked the whole database. The owner wants each read-only node managed on its own, while the things replication needs to match still come from the main. Databasify is open source and runs on any server, so the rules follow the engines' own replication behaviour rather than any one hosted service.

## Decision

- **Per-node data.** `database_nodes` has `status` (a `DatabaseStatus`, used by read-only nodes) and `config` (the node's own engine settings). `trusted_sources` and `connection_pools` have a nullable `database_node_id`: null is the main's row, a node id is that node's own. `Database::trustedSources()` and `connectionPools()` return only the main's rows. A new read-only node starts with a copy of the main's trusted sources, pools and config; the migration gave existing read-only nodes the same copy.
- **Each read-only node has its own busy state.** `Database` and `DatabaseNode` share `RunsOperations`: `markBusy()`/`markBusyWith()` (with `refreshForUpdate()` inside a transaction), `markReady()`, `markFailed()` and `busyError()`. A read-only node refuses work while its database is pending, creating, deleting or failed.
- **What stays on the main and claims every read-only node.** Upgrade and redeploy-all, delete, adding and removing a node use `Database::markBusyWithReadOnly()`: one transaction locks the database and every read-only node, refuses with the busy message of whichever is busy, and otherwise writes the status on all of them. These statuses (`DatabaseStatus::claimsReadOnlyNodes()`: upgrading, redeploying, deleting, scaling) release the read-only nodes when the main's operation ends. Failover, recovery and rejoin touch only members, and read-only nodes follow the new primary through the engine, so they claim the main alone and a busy read-only node never blocks them. Users, databases (schemas), migrations and maintenance stay on the main only.
- **What a read-only node does on its own.** Trusted sources (`AddNodeTrustedSource`, `RemoveNodeTrustedSource`, `ApplyNodeTrustedSources`), connection pools (`CreateNodeConnectionPool`, `DeleteNodeConnectionPool`, `ApplyNodeConnectionPools`, sharing `SyncNodePools` with the main), config (`UpdateNodeConfig`, `RollOutNodeSettings`), retune (`RetuneNode`, `RollOutNodeTuning`), reboot (`RebootNode`, `RebootNodeServer`, sharing `RebootServer` with the main) and rename/redeploy (read-only nodes only) run on that node alone, each as one action and one job ([0036](0036-operations-as-one-job.md), [0037](0037-engine-tasks-off-the-worker.md)). A failed node job ends through `EndFailedNodeOperation`, which stops only that node's engine task, fails only that node's logs and alerts "{database}: {operation} on {node} failed".
- **The main's own work skips read-only nodes.** Its trusted sources and pools reach members only; `RollingNodes` leaves read-only nodes out of settings, retune and reboot (`withReadOnly: false`). The firewall of every node uses that node's own list (`DatabaseNode::trustedCidrs()`), and `BuildNodeConfig` uses a read-only node's own config.
- **Settings the primary controls.** `EngineFacts::$primaryControlledSettings` lists settings the engine's cluster manager sets on every node from the primary; for Postgres these are Patroni's cluster-wide keys (`PostgresFiles::CLUSTER_WIDE_KEYS`: `max_connections`, `max_locks_per_transaction`, `max_worker_processes`, `max_prepared_transactions`, `max_wal_senders`, `max_replication_slots`, `track_commit_timestamp`, `wal_level`), whose local values Patroni ignores. A read-only node refuses them ("{key} is set on the primary for every node, change it on {database}.") and its config editor hides them. After the main's settings rollout, each read-only node that is not busy and reports a pending restart gets its own `ApplyNodeSettingsJob`, which restarts it; a node busy with its own operation is left for later.
- **Status poll.** `RefreshDatabaseStatus` skips read-only nodes busy with their own operation, so a node reboot does not mark it unknown or raise degraded and healthy-again alerts.
- **Scoped cleanup.** `EngineTasks::stillRunning()` and `stopRunning()` take a database or a node: a node scope looks only at that node, a database scope at the members and the read-only nodes its operation claimed. Ending or failing the main's operation fails only logs of servers not running their own operation (`Database::runningLogsOfItsOwnOperation()`). Pool names are unique per owner: the main and each read-only node keep their own names.

## Consequences

- A change on one read-only node never restarts or blocks the main or the other read-only nodes, and a change on the main never restarts read-only nodes, except to raise a floor setting.
- Upgrade, delete and node changes on the main wait until no read-only node is busy, and the owner sees which one; failover and recovery never wait for read-only nodes.
- Each read-only node page has its own Network Access, Connection Pools and Settings (config, retune, reboot).

## Alternatives considered

- **Each read-only node as its own `Database` row linked to its primary:** rejected, it rewrites replication, membership, DNS and certificates, the riskiest code, for a structural gain.
- **One status plus per-node locks:** rejected, "busy" would live in two places with different rules.
- **A separate DNS zone or maintenance window per read-only node:** not done; the node keeps its hostname under the database's zone and maintenance stays on the main.

## Evidence

- `database/migrations/2026_11_14_130738_make_read_only_nodes_independent.php`
- `app/Models/Concerns/RunsOperations.php`
- `app/Models/Database.php`
- `app/Models/DatabaseNode.php`
- `app/Enums/Databases/DatabaseStatus.php`
- `app/Engines/Data/EngineFacts.php`
- `app/Engines/Drivers/Postgres/PostgresDriver.php`
- `app/Services/Databases/EngineTasks.php`
- `app/Services/Databases/RollingNodes.php`
- `app/Actions/Databases/Nodes/BuildNodeConfig.php`
- `app/Actions/Databases/Network/AddNodeTrustedSource.php`
- `app/Actions/Databases/Network/ApplyTrustedSources.php`
- `app/Actions/Databases/Pools/SyncNodePools.php`
- `app/Actions/Databases/Configuration/UpdateDatabaseConfig.php`
- `app/Actions/Databases/Configuration/UpdateNodeConfig.php`
- `app/Actions/Databases/Configuration/RollOutNodeTuning.php`
- `app/Actions/Databases/Nodes/RebootServer.php`
- `app/Actions/Databases/Lifecycle/EndFailedNodeOperation.php`
- `app/Http/Controllers/Databases/DatabaseNodeController.php`
- `app/Http/Controllers/Databases/NodeTrustedSourceController.php`
- `app/Http/Controllers/Databases/NodeConnectionPoolController.php`
- `resources/js/pages/databases/nodes/show.tsx`
- `resources/js/components/databases/nodes/node-settings-tab.tsx`
