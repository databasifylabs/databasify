# Lessons from the MongoDB run (2026-10)

What broke the first time MongoDB ran on real servers. Check each for the new engine before the e2e.

## Environment

- Provisioning stalls at "Waiting for SSH to answer" when the user's network blocks outgoing port 22 (`nc -z github.com 22` fails too). The app gives up after 10 minutes.
- The provisioning queue can run one launch at a time; three orders may start minutes apart.
- These hosts run Docker with IPv4 forwarding off: containers on the default bridge have no network. Run throwaway clients with `--network host`.
- GitHub runners move to new kernels before servers do; image smoke failures can be kernel-specific (SERVER-121912 on Linux 6.19–7.0.13).

## Image and process

- Host tuning must never lower a kernel value the OS already set higher (`vm.max_map_count`); keep the larger of the original and ours.
- An engine process listed in `processes.json` is not started unless a script enables it.
- Start the server only when its data directory exists, or erase/disk-loss leaves it in a restart loop.
- Don't override image environment from the driver.

## Clustering

- Recovery after a total outage: the engine may elect a primary by itself while the app is still forcing one; wait for any primary, not for the forcing node.
- Clients learn member addresses from the server (`hello`). Without public names on each member, drivers outside the private network are sent to private IPs.
- A planned failover leaves a few seconds without a primary, an unplanned one about the election timeout; drivers that discover the cluster wait and retry, a single DNS name waits for the record and its TTL.

## Backups

- Bundled backup tools need their own users and agents; start the agent, wait until it reports ready, then configure, back up or restore.
- Some tools validate TLS hostnames strictly and ignore "allow invalid hostname" options: connect them to the node's own certificate name or IP.
- Check the tool's supported engine versions; ship unsupported lines with `'recovery' => false`.

## Migrations

- Third-party copy tools may mangle connection strings; read how they build URIs before relying on query options.
- Report lag as the unapplied backlog so an idle source reads 0.

## Product decisions recorded

- Settings mirror DigitalOcean's list for the engine; tuned values stay automatic.
- On a domain, MongoDB publishes `mongodb+srv` like DigitalOcean (all members in SRV, one A record each); without a domain clients connect to the IP with the CA certificate.
- MongoDB lines: 7.0, 8.0 (default), 9.0 without point-in-time recovery until PBM supports it.
- MySQL lines: 8.4 LTS (default), 9.7 LTS. MySQL 9.0 removed the `mysql_native_password` server option and plugin, so the 9.7 `files/server` is a full copy without it and 9.7 offers no Legacy password encryption.
