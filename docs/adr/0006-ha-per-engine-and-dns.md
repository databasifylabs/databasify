# 0006. Each engine keeps its own HA, and DNS follows the primary

- Status: Accepted
- Date: 2026-11-15

## Context

Every engine already ships a mature high-availability mechanism. A generic Databasify failover layer would have to understand every engine's replication state and would itself become a single point of failure. Clients still need a stable address for the writable primary, which moves on failover.

## Decision

- **Native HA per engine:**

| Engine         | HA mechanism                                   | Peer ports       |
| -------------- | ---------------------------------------------- | ---------------- |
| PostgreSQL     | Patroni + etcd, PgBouncer pools                | 8008, 2379, 2380 |
| MySQL          | InnoDB Cluster / Group Replication via mysqlsh | 33061            |
| MariaDB        | Galera, single writer                          | 4444, 4567, 4568 |
| MongoDB        | Replica set `databasify`                       | none             |
| Valkey / Redis | Sentinel                                       | 6379, 26379      |

- The app adds manual failover, quorum detection (`DetectQuorum`: a member reports online × 2 > total) and recovery after an outage (`RecoverCluster`, `RejoinMembers`). Recovering from a minority needs the owner's confirmation; with a majority and a serving primary, recovery only redeploys and rejoins the members that answer from outside the group.
- A database has a primary and at most 2 standbys (`Engine::MAX_STANDBYS`) plus up to 47 read-only nodes (`Engine::MAX_READ_ONLY_NODES`). MongoDB members past the voting limit join with 0 votes.
- **Join and leave order** follows each engine. Engines whose joiner needs the seed's cluster to exist first set `EngineFacts::$seedClustersFirst` (PostgreSQL, MariaDB); a failed join runs `UndoNodeJoin`. Shrinking to one node refuses unless the remaining node reports itself as primary (`TakeNodeOut`), so a stale standby never runs alone.
- **Read-only nodes** keep their own trusted sources, connection pools and config. Settings the cluster manager sets from the primary (`EngineFacts::$primaryControlledSettings`, for PostgreSQL Patroni's cluster-wide keys) are refused on a read-only node.
- **Durability differs per engine.** PostgreSQL replicates asynchronously and promotes only members within `maximum_lag_on_failover` (1 MiB); MySQL Group Replication and Galera certify each write through the group; MongoDB defaults to write concern `majority` (changeable to `1`); Valkey and Redis replicate asynchronously and, when persistence is on, keep an append-only file (`appendfsync everysec`) next to RDB snapshots.
- **DNS follows the primary.** With a DNS zone the app publishes `<db>.db.<zone>` for the primary, `replica-<db>.db.<zone>` for standbys, `<node-name>.db.<zone>` for each read-only node, `private-` variants, and for MongoDB an SRV and TXT record plus one A record per member. Records are reconciled in the every-minute status refresh. Cloudflare is the only DNS driver, with a 60 s TTL. A sync that keeps failing writes one failed `Sync DNS records` server log per outage. A per-database lock serialises a sync with a zone change or deletion.

## Consequences

- Failover keeps working while the app is down.
- After failover, clients may reach the old address for about a minute of polling plus the TTL and their resolver cache. DNS moves only when exactly one member claims to be primary; during a split, records stay.
- Without a DNS zone, clients connect by IP and must handle failover themselves.
- An asynchronous failover can lose the last writes the old primary accepted (PostgreSQL, Valkey, Redis). Galera SST traffic is unencrypted on the private network.
- A change on one read-only node never restarts the main or the other read-only nodes.

## Alternatives considered

- **HAProxy or MySQL Router in front of the cluster:** removed, another moving part per node; DNS that follows the primary was enough.
- **Each read-only node as its own database row:** rejected, it rewrites replication, membership, DNS and certificates.

## Evidence

- `app/Engines/Engine.php`
- `app/Engines/Data/EngineFacts.php`
- `app/Engines/Drivers/Postgres/PostgresDriver.php`
- `app/Engines/Drivers/MySqlFamily/MySqlFamilyEngine.php`
- `app/Engines/Drivers/MongoDb/MongoDbSettings.php`
- `app/Actions/Databases/Failover/DetectQuorum.php`
- `app/Actions/Databases/Failover/RecoverCluster.php`
- `app/Actions/Databases/Failover/RejoinMembers.php`
- `app/Actions/Databases/Nodes/JoinNode.php`
- `app/Actions/Databases/Nodes/UndoNodeJoin.php`
- `app/Actions/Databases/Nodes/TakeNodeOut.php`
- `resources/views/engines/postgres/files/patroni-yml.blade.php`
- `resources/views/engines/sentinel/files/server-conf.blade.php`
- `app/DTOs/Databases/DatabaseHostnames.php`
- `app/Actions/Databases/Domains/SyncDatabaseDns.php`
- `app/DnsProviders/Drivers/Cloudflare/Cloudflare.php`
