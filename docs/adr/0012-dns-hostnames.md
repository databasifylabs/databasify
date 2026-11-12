# 0012. DNS names follow the primary

- Status: Accepted
- Date: 2026-11-04

## Context

Clients need a stable address for the writable primary, which moves on failover. [0007](0007-ha-per-engine.md) rules out a proxy in front of the cluster.

## Decision

When a database has a DNS zone, Databasify publishes:

- `<db>.db.<zone>` for the primary
- `replica-<db>.db.<zone>` for standbys
- `<node-name>.db.<zone>` for each read-only node
- `private-` variants that resolve to private addresses
- for MongoDB, an SRV record `_mongodb._tcp` plus a TXT record and one public A record per member (`<db>-node-<position>`), so `mongodb+srv://` works

Records are reconciled during the every-minute status refresh ([0018](0018-monitoring-by-ssh-polling.md)), so they follow the primary after failover. Cloudflare is the only DNS driver, with a 60-second TTL; its record calls retry transient failures ([0023](0023-provider-http-client-with-dns-write-retries.md)), and a sync that still fails is recorded once as a failed `Sync DNS records` server log on the database, until a sync succeeds, and tried again on every refresh. Hostnames are checked to be free before they are claimed, and records are cleared when the database leaves its zone or is deleted. Clearing always reads and deletes at Cloudflare, ignoring the per-name cache that lets a sync skip unchanged names. A per-database `database-dns:<id>` lock serialises the sync with a zone change or deletion: a sync waits up to 10 seconds for another sync and skips only when a zone change or deletion still holds the lock, and the zone change clears the old names and moves the database inside it. If the old names cannot be cleared, the database stays in its zone and the change fails with the provider's error.

## Consequences

- DNS follows the primary only when exactly one member claims to be primary. During a split, records stay where they were.
- After failover, clients may reach the old address for up to about a minute of polling, plus the TTL, plus their own resolver caching.
- Without a DNS zone, clients connect by IP and must handle failover themselves.

## Alternatives considered

- **A proxy layer:** see [0007](0007-ha-per-engine.md).

## Evidence

- `app/DTOs/Databases/DatabaseHostnames.php`
- `app/DTOs/Databases/ServiceRecords.php`
- `app/Actions/Databases/Domains/`
- `app/Services/Dns/DnsRecordPublisher.php`
- `app/DnsProviders/Drivers/Cloudflare/Cloudflare.php`
