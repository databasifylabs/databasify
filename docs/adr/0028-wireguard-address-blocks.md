# 0028. WireGuard address blocks of a chosen size, CGNAT only

- Status: Accepted
- Date: 2026-11-05

## Context

ADR 0010 gave every Databasify WireGuard network a fixed `/24` from `100.64.0.0/10`, so a network held at most 254 servers and only overlap with other `networks` rows was checked. A host can already use part of `100.64.0.0/10` itself: Tailscale takes the whole `/10`, and some providers put a CGNAT address on the public interface. A mesh block that overlaps such a subnet breaks routing on that host.

## Decision

- The user picks the block size when a Databasify network is created: `/24` (default), `/23`, `/22`, `/20` or `/16` (`Network::BLOCK_PREFIXES`). The block is stored in the network's `cidr`.
- `AllocateNetworkBlock` walks the aligned blocks of that size in `100.64.0.0/10` (`Network::WIREGUARD_POOL`) and takes the first one that overlaps no existing network in any project (the pool is queried with `withoutProject()`, [0031](0031-projects-as-a-global-scope.md)) and no subnet seen on a member server's interfaces. With no free block it fails validation on `network`.
- The facts script records every IPv4 interface subnet except loopback in `servers.interface_subnets`, canonicalised to the network address.
- `WireguardAddressAllocator` hands out the lowest free host from the first usable to the last usable address of any block, never the network or broadcast address. The `servers(network_id, private_ip)` unique index and the one retry in `AssignServerAddress` stay.
- The pool stays CGNAT only. RFC1918 ranges (`10.0.0.0/8`, `192.168.0.0/16`) are not offered.

## Consequences

- A network holds up to 65,534 servers (`/16`). The pool holds 16,384 `/24` blocks, or 64 `/16` blocks, shared by every size.
- Interface subnets are only known once facts have been read. A brand-new server that creates its own network is not checked against its interfaces, because its facts are read later during provisioning. Moving a provisioned server onto a new network is checked.
- A host that routes the whole `100.64.0.0/10` (Tailscale) cannot create a Databasify network; it can use a provider or custom network instead.

## Alternatives considered

- **An opt-in RFC1918 pool with a blocklist of provider and Docker ranges:** rejected. ADR 0010 already moved away from `10.240.N.0/24` because RFC1918 ranges collide with provider VPCs, and a blocklist only covers the ranges someone has seen.
- **Fixed `/24` blocks:** replaced, because 254 servers per network is too few for large fleets.

## Evidence

- `app/Actions/Networks/AllocateNetworkBlock.php`
- `app/Support/Networks/Cidr.php`
- `app/Services/Networks/WireguardAddressAllocator.php`
- `app/Actions/Networks/AssignServerAddress.php`
- `app/DTOs/Servers/ServerFacts.php`
- `resources/views/ssh/servers/provision/facts.blade.php`
- `app/Models/Network.php`
