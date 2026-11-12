# 0017. Cloud providers only order the server

- Status: Accepted
- Date: 2026-11-04

## Context

Users can order servers on DigitalOcean, Hetzner, Vultr and Linode, or bring their own over SSH. Each provider offers many extra features (firewalls, volumes, snapshots, resizing) with different APIs and limits.

## Decision

- The `ServerDriver` contract covers only regions, plans, private networks (VPCs), SSH keys, and creating, finding and deleting a server.
- Each provider registration maps every supported operating system to the provider's image ([0016](0016-host-os-and-provisioning.md)). Ordered servers log in as `root` with the app's SSH key, registered with the provider, and always have password login turned off.
- After ordering, the app waits up to 10 minutes for the server to boot, then up to 10 more for SSH. From there the server goes through the same provisioning as one the user added by hand.
- The region and plan catalog is cached for 10 minutes, and networks for 60 seconds.

## Consequences

- Provider-specific behaviour stops at launch; everything after is the same SSH path ([0001](0001-ssh-control-plane.md)).
- Firewalls, volumes, snapshots and resizing are not managed through providers.
- A new provider needs only a small driver and an image map, which plugins can add.

## Alternatives considered

- **Using provider firewalls and volumes:** not chosen, so that ordered and bring-your-own servers behave the same.

## Evidence

- `app/ServerProviders/Contracts/ServerDriver.php`
- `app/Providers/ServerProviderServiceProvider.php`
- `app/Actions/Providers/LaunchProviderServer.php`
- `app/Services/Providers/ProviderCatalog.php`
