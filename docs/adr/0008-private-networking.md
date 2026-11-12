# 0008. Provider VPC, custom network or WireGuard mesh

- Status: Accepted
- Date: 2026-11-04

## Context

Replication traffic should not cross the public internet in clear text. Some servers sit in one provider VPC, some are on different providers, and some are bare servers added over SSH.

## Decision

A network has one of three kinds (`NetworkKind`):

- **Provider:** a provider VPC, discovered through the provider API.
- **Custom:** a private range the user types in.
- **WireGuard:** a mesh managed by Databasify. Each network takes a free block from `100.64.0.0/10` (ADR 0022), listens on its `listen_port` (51820 by default), and uses the interface `databasify`.

Each server generates its own WireGuard private key, which never leaves it. Each server takes the lowest free address (`WireguardAddressAllocator`) and freed addresses are reused. A unique index on `servers(network_id, private_ip)` keeps addresses unique: when two servers race for one address, the loser picks the next free one once (`AssignServerAddress`) and otherwise is asked to try again. Peers update with `wg syncconf` without a restart. The mesh gets its own nftables table (`databasify_network`) plus matching UDP rules in ufw or firewalld when they are active. Syncs for one network are serialized, and `networks:reconcile` runs every 5 minutes.

## Consequences

- All members of a database must share one network.
- The size of a WireGuard network and how its block is chosen are set by ADR 0022.
- Joining the mesh is the last provisioning step for a server.
- Overlapping WireGuard syncs used to race, which is why they are serialized.

## Alternatives considered

- **A `10.240.N.0/24` range:** replaced, because it overlapped common VPC ranges.

## Evidence

- `app/Enums/Servers/NetworkKind.php`
- `app/Models/Network.php`
- `app/Actions/Networks/`
- `app/Services/Networks/WireguardAddressAllocator.php`
- `database/migrations/2026_10_22_000002_create_servers_table.php`
- `resources/views/ssh/servers/wireguard/`
- `routes/console.php`
