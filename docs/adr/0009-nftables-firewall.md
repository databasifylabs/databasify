# 0009. One nftables table per database

- Status: Accepted
- Date: 2026-11-04

## Context

Docker is started with `iptables: false` ([0002](0002-container-per-node.md)), so container ports are not exposed through Docker's rules. Peer ports must be reachable only from other members, and client ports only from trusted sources. Hosts may also run ufw (Debian family) or firewalld (RHEL family).

## Decision

Each database gets its own nftables table, `databasify_database_<id without dashes>`, written to `/etc/databasify/nftables/<table>.nft`, checked with `nft -c` and applied atomically. A `databasify-firewall` systemd unit restores the tables at boot, before Docker starts.

- Peer ports accept only the `@peers` set.
- Client ports accept the `@peers` and `@trusted` sets plus the server's private network range (VPC, custom or WireGuard). Trusted sources are IPv4 CIDRs; their labels live only in the app.
- With no trusted sources, client ports are open to everyone.

If ufw or firewalld is active, Databasify also opens the matching ports in it. Changes run as a queued operation and are refused while the database runs another one ([0006](0006-operations-and-server-logs.md)).

## Consequences

- A broken rule set never half-applies.
- An empty trusted-source list means public client access. The UI must make this clear.
- DigitalOcean's Rocky and Alma images ship without firewalld. Whether provisioning should install it is still open.

## Alternatives considered

- **ufw or firewalld rules only:** rejected, because neither handles per-database sets atomically across both OS families.

## Evidence

- `resources/views/ssh/databases/network/trusted-sources.blade.php`
- `resources/views/ssh/servers/firewall/`
- `app/Actions/Databases/Network/ApplyNodeFirewall.php`
- `app/Actions/Databases/Network/RemoveNodeFirewall.php`
