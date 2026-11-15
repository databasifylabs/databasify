# 0007. Private networking and a firewall table per database

- Status: Accepted
- Date: 2026-11-15

## Context

Replication traffic should not cross the public internet in clear text. Some servers sit in one provider VPC, some are on different providers, and some are bare servers added over SSH. Docker runs with `iptables: false` ([0002](0002-hosts-and-container-per-node.md)), so peer ports must be closed to everyone but members, and client ports open only to trusted sources, on hosts that may also run ufw or firewalld.

## Decision

- **Three network kinds** (`NetworkKind`): a provider VPC discovered through the provider API, a custom private range the user types in, or a WireGuard mesh managed by Databasify. All members of a database share one network.
- **WireGuard mesh.** Each server generates its own private key, which never leaves it. The interface is `databasify`, listening on 51820 by default. Each server takes the lowest free address of the block (`WireguardAddressAllocator`), kept unique by an index on `servers(network_id, private_ip)` with one retry (`AssignServerAddress`). Peers update with `wg syncconf` without a restart; syncs of one network are serialised and `networks:reconcile` runs every 5 minutes.
- **Address blocks.** The user picks `/24` (default), `/23`, `/22`, `/20` or `/16` (`Network::BLOCK_PREFIXES`). `CreateWireguardNetwork` takes the first aligned block in `100.64.0.0/10` (`Network::WIREGUARD_POOL`) that overlaps no network in any project and no interface subnet of a member server. The pool is CGNAT only.
- **One nftables table per database**, `databasify_database_<id without dashes>`, written to `/etc/databasify/nftables/`, checked with `nft -c` and applied atomically. The `databasify-firewall` systemd unit restores the tables at boot, before Docker. Peer ports accept only the `@peers` set; client ports accept `@peers`, `@trusted` and the server's private range. Each node uses its own trusted list (`DatabaseNode::trustedCidrs()`). The mesh has its own table, `databasify_network`. When ufw or firewalld is active, the matching ports are opened there too.

## Consequences

- A broken rule set never half-applies.
- An empty trusted-source list means public client access, and the UI must say so.
- A host that routes the whole `100.64.0.0/10` (Tailscale) cannot create a WireGuard network; it can use a provider or custom network.
- A brand-new server that creates its own network is not checked against its interfaces, because its facts are read later.

## Alternatives considered

- **A `10.240.N.0/24` or opt-in RFC1918 pool:** rejected, RFC1918 ranges collide with provider VPCs.
- **Fixed `/24` blocks:** replaced, 254 servers per network is too few.
- **ufw or firewalld rules only:** rejected, neither handles per-database sets atomically across both OS families.

## Evidence

- `app/Enums/Servers/NetworkKind.php`
- `app/Models/Network.php`
- `app/Actions/Networks/CreateWireguardNetwork.php`
- `app/Actions/Networks/AssignServerAddress.php`
- `app/Services/Networks/WireguardAddressAllocator.php`
- `app/Support/Networks/Cidr.php`
- `resources/views/ssh/servers/wireguard/`
- `resources/views/ssh/servers/firewall/`
- `resources/views/ssh/databases/network/trusted-sources.blade.php`
- `app/Actions/Databases/Network/ApplyNodeFirewall.php`
- `routes/console.php`
