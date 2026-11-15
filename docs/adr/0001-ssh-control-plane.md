# 0001. Laravel over SSH is the only control plane

- Status: Accepted
- Date: 2026-11-15

## Context

Databasify manages database clusters on servers the user owns or orders from a cloud provider. Something has to install software, write configuration, run operations and watch each node. A long-running agent or exporter per node would need its own release, upgrade and security story on every server.

## Decision

- The Laravel app is the only control plane. It renders bash scripts from Blade views, uploads them over SFTP to `~/.databasify` (mode 0700) and runs them with `bash`, prefixed with `sudo -n` when the SSH user is not root. The uploaded script is deleted after each run. No Databasify agent runs on the host; on a node only the engine's own HA layer ([0006](0006-ha-per-engine-and-dns.md)) and the engine runtime in the container ([0003](0003-engine-images-and-frozen-runtime.md)) act without the app.
- Every script starts with `set -euo pipefail`. Steps time out after 3600 s (`Ssh::TIMEOUT`), probes after 90 s (`Ssh::PROBE_TIMEOUT`), and the apt wrapper waits up to 600 s for the dpkg locks (`Ssh::APT_LOCK_TIMEOUT`). SSH connects time out after 10 s and are tried 3 times, 3 s apart. A script that has started is never run again by the connection.
- **Monitoring is the app polling over SSH.** Every minute the scheduler queues status and metrics jobs for each database on the `monitoring` queue. Node status and engine metrics are driver read scripts ([0004](0004-engine-drivers-and-blade-scripts.md)); host CPU, memory and disk come from `ssh.databases.monitoring.metrics`. Samples are stored in the app database (`NodeMetricSample`). Alert thresholds are fixed in code (`EvaluateResourceAlerts`). A node that misses a status read keeps its last role until it misses 3 in a row (`missed_status_reads`), so one dropped connection never marks a database offline. There is no Prometheus, exporter or agent.
- **Cloud providers only order the server.** The `ServerDriver` contract covers regions, plans, private networks, SSH keys, and creating, finding and deleting a server. Ordered servers log in as `root` with the app's SSH key and have password login turned off; from then on they follow the same SSH provisioning as a server added by hand. Every provider request goes through `ProviderHttp`: `GET` and DNS record writes retry on a dropped connection, 429 or 5xx (`TransientFailure`); server `POST` and `DELETE` never retry, because a repeated create orders a second server. Callers catch only `ProviderRequestFailed`.
- **The browser terminal is a queued SSH shell.** `RunTerminalSessionJob` holds an interactive shell on the `terminals` queue; keystrokes arrive over authenticated HTTP and output streams over Reverb on a channel bound to its owner. A session closes after 15 minutes idle or 2 hours in total (`TerminalSession`).

## Consequences

- Scripts must be idempotent, because a failed operation is retried by running the same steps again.
- When the app is down, failover still works, but provisioning, status refresh, DNS updates and alerts stop.
- Throughput is bounded by SSH round trips, and metrics have one-minute resolution. Thresholds cannot be changed per database.
- Firewalls, volumes, snapshots and resizing are not managed through providers, so ordered and bring-your-own servers behave the same. A new provider is a small driver and an image map, which plugins can add.
- Each open terminal holds one Horizon worker for up to 2 hours, and gives any signed-in user a root shell, which is acceptable only with a single owner ([0011](0011-app-code-structure.md)).

## Alternatives considered

- **A node agent** pulling work from the app: rejected, a second deployable to version, secure and upgrade on every server.
- **Prometheus exporters on each node:** rejected, they need ports, credentials and upgrades on every server.
- **Provider firewalls and volumes:** not used, so that every server follows one path.
- **Retrying every provider verb:** rejected, a retried server create can order two servers.

## Evidence

- `app/Services/Ssh/Ssh.php`
- `app/Services/Ssh/PhpseclibConnection.php`
- `resources/views/ssh/`
- `routes/console.php`
- `app/Actions/Databases/Lifecycle/ReadNodeStatus.php`
- `app/Actions/Databases/Monitoring/ReadDatabaseMetrics.php`
- `app/Actions/Databases/Monitoring/EvaluateResourceAlerts.php`
- `app/ServerProviders/Contracts/ServerDriver.php`
- `app/Actions/Providers/LaunchProviderServer.php`
- `app/Services/Providers/ProviderHttp.php`
- `app/Services/Http/TransientFailure.php`
- `app/Services/Ssh/TerminalSession.php`
- `app/Jobs/Servers/RunTerminalSessionJob.php`
