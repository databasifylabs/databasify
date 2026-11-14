# 0007. Each engine keeps its own HA mechanism

- Status: Accepted
- Date: 2026-11-04

## Context

Every engine already ships a mature high-availability mechanism. A generic Databasify failover layer would have to understand every engine's replication state, and would itself become a single point of failure.

## Decision

Each engine uses its native HA stack:

| Engine         | HA mechanism                                   | Peer ports       |
| -------------- | ---------------------------------------------- | ---------------- |
| PostgreSQL     | Patroni + etcd, PgBouncer pools                | 8008, 2379, 2380 |
| MySQL          | InnoDB Cluster / Group Replication via mysqlsh | 33061 (8.4 only) |
| MariaDB        | Galera, single writer                          | 4444, 4567, 4568 |
| MongoDB        | Replica set `databasify`, at most 7 voters     | —                |
| Valkey / Redis | Sentinel                                       | 6379, 26379      |

Clients reach the primary through a DNS name that follows it ([0012](0012-dns-hostnames.md)). The app adds manual failover, quorum detection (`DetectQuorum`: online × 2 > total) and recovery after a total outage (`PromoteSurvivor`).

## Consequences

- New read-only nodes copy their data from a replica when one is healthy, to spare the primary: PostgreSQL through Patroni's `clonefrom` tag on read-only nodes, MySQL through MySQL Shell's `cloneDonor` (a healthy standby). MariaDB is the exception: it copies from the writer it will follow, through a `mariadb-dump` stream whose GTID position a `sed` stage in the same pipeline keeps for `gtid_slave_pos`, and a failed dump, filter or load never counts as a copy. MongoDB already chains initial sync from secondaries (`chainingAllowed` default). Valkey and Redis keep replicating from the primary, because Sentinel points every replica back at the primary on failover. PostgreSQL and MariaDB skip the membership pass for a read-only join (`membershipChangesFor()`), since their member lists only hold members.
- Growing a single node into a cluster follows each engine's order. By default the joiner starts and gets ready first and the seed enables its cluster afterwards. Engines whose joiner needs the seed's group to exist before it can start set `EngineFacts::$seedClustersFirst`, and `JoinNode` then configures and enables the seed before it configures and starts the joiner: MariaDB, because a Galera joiner that finds no running cluster at its `wsrep_cluster_address` fails to start, so the seed, the only node holding data, bootstraps first with `--wsrep-new-cluster` and the joiner then copies from it by SST; PostgreSQL, because a new etcd member must be added to a running cluster before it starts with `initial-cluster-state=existing` and Patroni needs etcd. When such a join fails after the seed's `enable` task started, `UndoNodeJoin` runs `cluster disable` on the sole node so it serves on its own again; a seed that never started that task is left as it is. When that `cluster disable` fails, `AddNodeJob` marks the database `failed` with the reason instead of `ready`. The same engines shrink back the other way: when a removal leaves one node, `TakeNodeOut` configures it standalone and runs `cluster disable` on it, which for PostgreSQL stops Patroni and etcd and restarts `databasify-postgres` on the same data directory as a plain primary.
- Failover keeps working while the Databasify app is down.
- A database has a primary and at most 2 standbys (`Engine::MAX_STANDBYS`). Read-only nodes are counted separately and capped at 47 for every engine (`Engine::MAX_READ_ONLY_NODES`, MongoDB's 50-member replica set less the voting members); the engine checks sizes and limits again in `node configure`.
- MongoDB members past the 7th join with 0 votes. Sentinel counts read-only nodes toward its 3 sentinels.
- Recovering from a minority of members needs the user to confirm. While a majority still answers and a primary serves, recovery only redeploys the members that answer from outside the group, which starts a stopped container, and runs `cluster rejoin` on them (`RejoinMembersFlow`), such as a restarted MySQL member; it never forces quorum or touches the healthy members. With a majority but no such member, recovery is refused (failover is the tool then).
- Galera SST traffic is unencrypted on the private network.
- The UI shows a hint to run 3 members for a majority.
- Durability of Valkey and Redis: they keep an append-only file (`appendonly yes`, `appendfsync everysec`, `aof-use-rdb-preamble yes`) next to the RDB snapshot (`save 3600 1`), so a crash loses at most about 1 s of writes. DigitalOcean's managed Valkey and Redis are RDB-only with a dump every 10 minutes, which can lose up to 10 minutes. We accept the extra disk writes for the stronger guarantee.
- Durability on failover differs per engine:
    - PostgreSQL replicates asynchronously through Patroni streaming replication, as on DigitalOcean's Standard Edition. A failover after a total outage or during a partition can lose the last writes the old primary accepted. Patroni promotes only members within `maximum_lag_on_failover` (1048576 bytes, 1 MiB, in `resources/views/ssh/engines/postgres/files/patroni.blade.php`). Synchronous mode is not offered.
    - MySQL Group Replication and MariaDB Galera certify every write through the group before it commits, so an acknowledged write survives the loss of the primary, and a minority partition stops taking writes.
    - MongoDB depends on the write concern. The app sets the replica set default to `w: majority` (`Mongodb::WRITE_CONCERN`, changeable to `1`), and majority writes survive elections.
    - Valkey and Redis replicate asynchronously, so a failover can lose writes the old primary accepted that had not reached the promoted replica. The append-only file above only covers a crash of one node.
- The cost of the append-only file: a node that restarts or a primary that is demoted does a full resync from the new primary, about 15 s per GB, and the node is not handed work until its initial append-only file and any running rewrite have finished (`aof_written`).

## Alternatives considered

- **HAProxy or MySQL Router in front of the cluster:** tried and removed. They added another moving part per node, and DNS that follows the primary was enough.

## Evidence

- `app/Actions/Databases/Failover/DetectQuorum.php`
- `app/Actions/Databases/Failover/RecoverCluster.php`
- `app/Engines/Engine.php`
- `app/Engines/Data/EngineFacts.php`
- `app/Actions/Databases/Nodes/JoinNode.php`
- `app/Actions/Databases/Nodes/UndoNodeJoin.php`
- `app/Actions/Databases/Nodes/TakeNodeOut.php`
- `app/Providers/EngineServiceProvider.php`
