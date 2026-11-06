# Phase 3 — step scripts

Scripts live in `resources/views/ssh/engines/<directory>/` (the base) and `resources/views/ssh/engines/<directory>/lines/<key>/` (one folder per version line, which is what runs) and run **inside the engine container** (`Ssh` (`$server->ssh()`) sends every `ssh.engines.*` view through `docker exec -i databasify-<engine> databasify step <engine>.lines.<key>.<step>`; the in-container script runs as root). Host-side work (Docker, firewall, sysctl, files) is shared under `resources/views/ssh/databases/` and needs no per-engine copy, except `config.host-tuning` and `lifecycle.cleanup`, which run on the host.

## Layout

```
engines/<directory>/
  helpers.blade.php        client wrappers (TLS + auth from files), waits, fingerprint
  lifecycle/               prepare start wait-ready status after-bootstrap cleanup (+ initiate, server)
  cluster/                 cluster uncluster admit adopt membership quorum force-quorum
                           failover forget recovery-position (+ shared JS/SQL partials)
  config/                  apply-config reload-tls sync-config pools host-tuning
  monitoring/              metrics
  accounts/                run-sql + one view per statement method
  archive/                 status restore backup (+ users, replay as the archive needs)
  migrations/              check start/copy progress stop env (+ tool config)
  lines/<key>/             one folder per registered line mirroring every view above;
                           each file is @include('ssh.engines.<directory>.<name>') or a full copy
```

## Version lines

- The line key is the registered line with `.` replaced by `_` (`8.4` → `8_4`, `7.0` → `7_0`, `18` → `18`). `scriptView()` resolves only `<scripts>.lines.<key>.<name>`; it never falls back to the base file.
- Adding a line: register it in `versions()`, then create `lines/<key>/` with one wrapper per base view, generated with a throwaway loop (do not commit a generator):
  `cd resources/views/ssh/engines/<directory> && find . -name '*.blade.php' -not -path './lines/*' | sed 's|^\./||' | while read -r f; do mkdir -p "lines/<key>/$(dirname "$f")"; printf "@include('ssh.engines.<directory>.%s')\n" "$(echo "${f%.blade.php}" | tr / .)" > "lines/<key>/$f"; done`
- Diverging a step for a line: replace that line's wrapper with a full copy and change it there (MySQL 9.7 `files/server` drops `mysql_native_password = ON`). If the step is a partial, the line's files that include it become full copies too, including the line's partial by its line view name.
- Adding a base view: add its wrapper to every line folder. `EngineScriptLinesTest` fails for every engine × line that lacks one.
- Version-specific behaviour outside scripts (choices offered in the UI, validation) stays in PHP keyed on `$this->database->version_line`.

Only add what the engine needs, but every step the engine has gets a file in its own folder: never borrow another engine's or the shared folder's view through the registration's `scripts()` overrides. Shared logic comes in with a one-line `@include('ssh.engines.shared.wake')` (or `ssh.engines.mysql.…`) file at the conventional path. A missing script is not skipped: `scriptView()` throws `MissingEngineScript`. A step the engine does not have is an explicit empty override in its group subclass (see `driver.md`).

## Who calls what, with which data

| Script                                                                | Called by                                                                                                          | Data (driver `*Variables()` methods)                                                                             |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| `lifecycle.prepare`                                                   | `databases/nodes/deploy` (via `$prepare`)                                                                          | `scriptData()->deploy()`                                                                                                                  |
| `config.host-tuning`                                                  | `databases/nodes/deploy` (runs on the host)                                                                        | `scriptData()->deploy()`                                                                                                                  |
| `lifecycle.start`                                                     | `nodes()->start()` (`StartDatabaseNode`)                                                                         | `scriptData()->starting()` (`bootstrapsCluster`, `secrets`)                                                                               |
| `lifecycle.wait-ready`                                                | `nodes()->waitUntilReady()` (`StartDatabaseNode`, `RebootDatabaseNode`)                                              | `scriptData()->readiness()` (`expectPrimary`, `clientPort`, `deadline`)                                                                   |
| `lifecycle.status`                                                    | `nodes()->status()` (`RefreshDatabaseStatus`, scheduled and after most actions)                                      | `scriptData()->for()`                                                                                                                    |
| `lifecycle.after-bootstrap`                                           | `nodes()->finishBootstrap()` (`BootstrapDatabase`)                                                                   | `scriptData()->for()` + `secrets`, `username`, `schema` and `sql` (the driver's `defaultAccountSql()`, empty without accounts or on restore) |
| `lifecycle.wake`                                                      | `nodes()->wake()` (`PromoteSurvivor`, complete-outage recovery)                                                  | `scriptData()->for()`                                                                                                                    |
| `lifecycle.cleanup`                                                   | `databases/nodes/stop` on erase (host)                                                                             | `scriptData()->for()`                                                                                                                    |
| `lifecycle.reset`                                                     | `nodes()->reset()` (`DetachNode`)                                                                                | `scriptData()->for()`                                                                                                                    |
| `cluster.cluster` / `cluster.uncluster`                               | `cluster()->cluster()` / `uncluster()` (`ClusterDatabase` 1 → 2 nodes, `UnclusterDatabase` back to 1)          | `scriptData()->clustering()` = versions + `membershipVariables()`                                                                                  |
| `cluster.membership`                                                  | `cluster()->updateMembership()` (`UpdateDatabaseMembership` on every node, after a join or detach)                     | `scriptData()->for()` (no node) + `membershipVariables()`                                                                                             |
| `cluster.admit` / `cluster.adopt`                                     | `cluster()->admit()` / `adopt()` on the primary, before / after the joining node starts                        | `scriptData()->admission()` (`node`, `readOnly`, `instance`, `alias`)                                                                     |
| `cluster.quorum`                                                      | `cluster()->quorum()` (`DetectQuorum`)                                                                                 | `scriptData()->for()`                                                                                                                    |
| `cluster.failover`                                                    | `cluster()->failoverTo()` (`PromoteStandby`, and `RollOutNodes` before the old primary restarts)                       | `scriptData()->for()` + `target`                                                                                                             |
| `cluster.force-quorum`, `cluster.recovery-position`, `cluster.rejoin` | `cluster()->forceQuorum()`, `recoveryPosition()`, `rejoin()` (`PromoteSurvivor`)                                   | `scriptData()->for()` + `instance`, `othersAreDown`                                                                                          |
| `cluster.forget`                                                      | `cluster()->forget()` (`DetachNode`, on the remaining primary)                                                     | `container`, `instance` only                                                                                                |
| `config.apply-config`                                                 | `nodes()->applyConfig()` (`ApplyDatabaseConfig`)                                                                     | `scriptData()->liveConfig()` (`settings`, `resets`)                                                                                       |
| `config.reload-tls`                                                   | `nodes()->reloadCertificates()`, through the host script `databases/certificates/refresh` (`$reload`)                | `scriptData()->for()` + TLS `files`                                                                                                          |
| `config.sync-config`                                                  | `nodes()->syncConfig()` (`RollOutNodes`)                                                                             | `scriptData()->for()` + `files`                                                                                                              |
| `config.pools`                                                        | `nodes()->applyPools()` (`ApplyConnectionPools`)                                                                     | `scriptData()->for()` + pools                                                                                                                |
| `helpers` + `monitoring.metrics`                                      | `nodes()->metrics()`, through the host script `databases/monitoring/metrics` (`$engineHelpers`, `$engineMetrics`)    | `scriptData()->for()`                                                                                                                    |
| `accounts.run-sql`                                                    | `accounts()->runSql()`, called by the account actions                                                                    | `container`, `sql`, `summary` only                                                                                          |
| `archive.status` / `archive.restore`                                  | `archive()->status()` / `restore()`                                                                      | `scriptData()->archiving()` + `backUpNow` / `scriptData()->restoring()`                                                                                 |

Every engine script gets `scriptData()->for($node)` (a plain array passed to `Ssh::exec()`): `directory`, `clientPort`, `processes`, `privateIp`, `readOnly`, `seed`, `clustered`, `primary`, `cleanup`, `container`, `repository`, `minimumWrapper` and the driver's `scriptVariables()`. Templates never reference PHP classes: engine paths, ports and unit names come from the driver's `variables()` (`$configFile`, `$socket`, `$patroniPort`), and values that need quoting are quoted in PHP before rendering. `Ssh` (`$server->ssh()`) adds `$aptTimeout` and `$notRunning` to every script.

## Flows the scripts must fit

- **Create:** certificates → `configure` (deploy + prepare) on every node → `RestoreArchive` (restore runs **before** any node starts, so the restore script starts the engine itself) → firewall → start the seed with `bootstrapsCluster` (or alone when single), then each joiner: admit on primary → start → adopt on primary → wait-ready → `lifecycle.after-bootstrap` on the seed.
- **Join:** certificates → peer ports → `cluster.cluster` on existing nodes when going 1 → 2 → deploy the new node (`joining`) → `cluster.membership` on every other node.
- **Detach:** `cluster.forget` on the remaining primary (or force when no primary is left) → stop and erase → `cluster.membership` → `cluster.uncluster` when one node remains.
- **Restart-required settings and image swaps:** `RollOutNodes` redeploys read-only nodes, then standbys, fails over, then the old primary. `lifecycle.start` must be idempotent and restart only when the rendered config changed (fingerprint the config, keyfile and server version; record it after a live apply too).
- **Status** runs often on every node. Use it to heal drift on the primary (restored votes, cluster-wide settings, published member names), one small change per run and only when the cluster configuration is committed.

## Output contracts (parsed by PHP — keep them exact)

| Script                      | Output                                                                                                                                                                                                                                                                                       |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `lifecycle.status`          | Lines the driver's `role()` understands, plus `version:<release>` (`NodeStatus::versionIn`).                                                                                                                                                                                            |
| `cluster.quorum`            | `online=<n>` and `total=<n>` (`DetectQuorum`: majority when `online * 2 > total`).                                                                                                                                                                                                           |
| `cluster.recovery-position` | `position=<int>` — the highest wins the complete-outage reseed; `-1` when unknown.                                                                                                                                                                                                           |
| `monitoring.metrics`        | `key=value` lines. Charts read them in `MetricSample::from`: connections from `threads_connected` or `connected_clients`, throughput from `instantaneous_ops_per_sec` or a running `questions` total. Also emit `data_mb` and `state`. CPU, load, memory and disk come from the host script. |
| `archive.status`            | `backups=`, `earliest=`, `latest=`, `last_backup=` (epoch seconds), `problem=` (`ArchiveStatus::fromOutput`).                                                                                                                                                                                |
| Certificates                | `CERT <name> <base64>` lines (`Support\Ssh\CertificateOutput::parse`).                                                                                                                                                                                                                         |

## Rules

- The prelude sets `set -euo pipefail`; guard expected failures with `|| true` and never let a client's dropped connection (a primary stepping down) fail the step.
- Use `databasify ctl start|stop|restart|enable --now|is-active|show|logs|run|run-schedule` — never systemd tools.
- Loop over `$processes` (from `databases/helpers`) instead of hard-coding process names; a process listed in the manifest is only started if a script enables it.
- Secrets come from files under `<dir>/conf` (read inside the client, e.g. `require('fs').readFileSync` in mongosh), never interpolated into command lines that land in logs or `ps`.
- Wait for the engine and its agents before driving them: a CLI fired one second after its agent starts fails or nominates nobody.
- Third-party CLIs may prompt (`[y/N]`): pass their yes flag — stdin is closed, so a prompt silently cancels with exit 0.
- `@include` helpers by full view name (`ssh.engines.<directory>.helpers`).

## Blade inside bash

- `@{{ … }}` is Blade's escape for a literal `{{`: `date -d @{{ $epoch }}` renders the braces, not the value. Precompute in PHP or move the `@`.
- `@@` renders `@`, so MySQL system variables need `@@@`.
- `{!! json_encode($value) !!}` produces double quotes: fine inside a quoted heredoc (`<<'JS'`), broken inside a double-quoted bash string. Inside bash strings use single-quoted literals of validated values.
- Long client programs go in a quoted heredoc so bash expands nothing; shared JavaScript or SQL lives in partials included inside the heredoc (`cluster/peers`, `cluster/horizons`).
