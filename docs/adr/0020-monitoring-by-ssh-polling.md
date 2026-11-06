# 0020. Monitoring is the app polling over SSH

- Status: Accepted
- Date: 2026-11-04

## Context

The app needs node status, resource metrics and alerts. Exporters or agents on each node would add software to install, secure and upgrade on every server ([0001](0001-ssh-control-plane.md)).

## Decision

- Every minute the scheduler queues unique status and metrics jobs for each database on the `monitoring` queue.
- They run each engine's `lifecycle.status` and `monitoring.metrics` scripts over SSH and store the samples in the app database (`NodeMetricSample`).
- Alert thresholds are fixed in code: usage at or above 90% for 5 consecutive samples (`EvaluateResourceAlerts`).
- The status refresh also keeps DNS records following the primary ([0014](0014-dns-hostnames.md)).
- There is no Prometheus, exporter or agent.

## Consequences

- Each node gets about two SSH sessions per minute, and metrics have one-minute resolution.
- Alerts, status and DNS updates stop while the app is down; failover itself does not ([0009](0009-ha-per-engine.md)).
- Thresholds cannot be changed per database.

## Alternatives considered

- **Prometheus exporters on each node:** not chosen, because they need ports, credentials and upgrades on every server.

## Evidence

- `routes/console.php`
- `app/Actions/Databases/Monitoring/ReadDatabaseMetrics.php`
- `app/Actions/Databases/Monitoring/EvaluateResourceAlerts.php`
- `app/Actions/Databases/Lifecycle/RefreshDatabaseStatus.php`
- `app/Models/NodeMetricSample.php`
