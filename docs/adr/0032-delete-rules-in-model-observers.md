# 0032. Delete guards in actions, cascades in model observers

- Status: Accepted
- Date: 2026-11-07

## Context

The create migrations declared foreign keys with `constrained()` and `cascadeOnDelete()`, `restrictOnDelete()` or `nullOnDelete()`. The rules were split: the database cascaded and nulled rows without any model event, while the user-facing refusals (`ServerProvider::ensureDeletable()`, `DnsProvider::ensureDeletable()`…) lived in actions, so a plain `->delete()` either skipped the friendly message or failed with an integrity error. Databasify must also run on managed databases that forbid foreign keys, such as PlanetScale (Vitess), so the schema cannot rely on database-level constraints at all.

## Decision

- Migrations declare relationship columns as plain `foreignUuid('x_id')`, `->nullable()` when the relation is optional, with `->index()` unless the column is already `->unique()` or leads a composite unique or index of its table. Relationship columns come directly after `id`. No migration uses `constrained()`.
- **Dependants refuse, owned parts cascade:** a resource managed on its own page (project, server provider, network, DNS provider or zone, storage provider, server) is never deleted while another resource depends on it; the delete is refused. Only parts owned by a resource with no page of their own (server users and logs, a database's nodes, accounts and pools, a node's samples and alerts, imported DNS zones) are deleted with it.
- **Restrict lives in the delete action:** the action calls the model's `ensureDeletable()` first, which throws a `ValidationException` (`DeleteProject`, `DeleteServerProvider`, `DeleteNetwork`, `DeleteDnsProvider`, `DeleteStorageProvider`, `DeleteServer`). The guards count dependants in every project ([0031](0031-projects-as-a-global-scope.md)), through the unscoped dependency relations. Observers never refuse.
- **Cascade and null-out live in one observer per parent model** (`app/Observers/<Model>Observer.php`, `#[ObservedBy]`), in `deleted`:
    - **Cascade:** deletes the children one model at a time through `DeleteChildren` (`->reorder()->lazyById()->each(...)`), so the children's own observers run and memory stays flat.
    - **Null out:** sets the column to `null` with one update query through the relation.
- Actions that delete a parent wrap `->delete()` in `DB::transaction`, so a failure part-way through a cascade leaves nothing half-deleted.

| Column                                         | Parent              | Rule     | Where it lives                                                                             |
| ---------------------------------------------- | ------------------- | -------- | ------------------------------------------------------------------------------------------ |
| `passkeys.user_id`                             | `users`             | cascade  | `UserObserver::deleted`                                                                    |
| `users.last_project_id`                        | `projects`          | null out | `ProjectObserver::deleted`                                                                 |
| `*.project_id` (six resource models)           | `projects`          | restrict | `DeleteProject` → `Project::ensureDeletable()` (also last one)                             |
| `networks.server_provider_id`                  | `server_providers`  | restrict | `DeleteServerProvider` → `ServerProvider::ensureDeletable()`                               |
| `servers.server_provider_id`                   | `server_providers`  | restrict | `DeleteServerProvider` → `ServerProvider::ensureDeletable()`                               |
| `servers.network_id`                           | `networks`          | restrict | `DeleteNetwork` → `Network::ensureDeletable()`                                             |
| `dns_zones.dns_provider_id`                    | `dns_providers`     | cascade  | `DnsProviderObserver::deleted`                                                             |
| `databases.dns_zone_id`                        | `dns_zones`         | restrict | `DeleteDnsProvider` → `DnsProvider::ensureDeletable()` (zones only go with their provider) |
| `point_in_time_recoveries.storage_provider_id` | `storage_providers` | restrict | `DeleteStorageProvider` → `StorageProvider::ensureDeletable()`                             |
| `server_users.server_id`                       | `servers`           | cascade  | `ServerObserver::deleted`                                                                  |
| `server_logs.server_id`                        | `servers`           | cascade  | `ServerObserver::deleted`                                                                  |
| `database_nodes.server_id`                     | `servers`           | restrict | `DeleteServer` → `Server::ensureDeletable()`                                               |
| `database_nodes.database_id`                   | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `database_users.database_id`                   | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `schemas.database_id`                          | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `trusted_sources.database_id`                  | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `database_migrations.database_id`              | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `connection_pools.database_id`                 | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `point_in_time_recoveries.database_id`         | `databases`         | cascade  | `DatabaseObserver::deleted`                                                                |
| `server_logs.database_id`                      | `databases`         | null out | `DatabaseObserver::deleted`                                                                |
| `node_metric_samples.database_node_id`         | `database_nodes`    | cascade  | `DatabaseNodeObserver::deleted`                                                            |
| `node_alerts.database_node_id`                 | `database_nodes`    | cascade  | `DatabaseNodeObserver::deleted`                                                            |
| `database_migrations.database_node_id`         | `database_nodes`    | null out | `DatabaseNodeObserver::deleted`                                                            |

`sessions.user_id` never had a constraint and has no rule.

## Consequences

- Refusals only run through the delete actions. A plain `->delete()` from a job, tinker or a test skips them and cascades straight away, so deleting code goes through the action.
- A query-builder delete (`Model::query()->...->delete()`) or a mass prune skips observers. It is only safe for rows without rules of their own: leaves such as samples, logs, users and schemas, or zones already filtered with `doesntHave('databases')` (`ImportDnsZones`).
- Cascading model by model costs time on large child sets: about 2.2 s for 20,000 `node_metric_samples` rows on SQLite against about 10 ms for one delete query. Seven days of per-minute samples is about 10,000 rows per node.
- Observers only touch the database. Side effects outside it (jobs, SSH, HTTP) stay in the actions, after the transaction, so a rolled-back delete never reaches a server or provider.
- The database no longer stops orphans written by code that bypasses the models; a new relationship needs its rule added to the parent's observer and to this table.

## Alternatives considered

- **Refuse in the observer's `deleting`:** rejected by the owner on 2026-11-07; a refusal is a business rule of the delete action, and observers only clean up what a delete leaves behind.
- **Keep the database constraints:** rejected. Cascades and null-outs ran without model events, so child observers never fired, and refusals surfaced as integrity errors on every path that skipped the action.
- **Keep the foreign keys as a safety net next to the observers (hybrid):** rejected, because managed databases that forbid foreign keys (PlanetScale, Vitess) could not run the migrations.
- **One bulk delete query per child table:** rejected for the cascade, because grandchildren and their observers would be skipped. Kept for null-outs, which need no events.

## Evidence

- `app/Observers/UserObserver.php`
- `app/Observers/ProjectObserver.php`
- `app/Observers/DnsProviderObserver.php`
- `app/Observers/ServerObserver.php`
- `app/Observers/DatabaseObserver.php`
- `app/Observers/DatabaseNodeObserver.php`
- `app/Actions/Models/DeleteChildren.php`
- `app/Models/Project.php`
- `app/Actions/Projects/DeleteProject.php`
- `app/Actions/Providers/DeleteServerProvider.php`
- `app/Actions/Networks/DeleteNetwork.php`
- `app/Actions/Dns/DeleteDnsProvider.php`
- `app/Actions/Storage/DeleteStorageProvider.php`
- `app/Actions/Servers/DeleteServer.php`
- `tests/Feature/Models/DeleteRulesTest.php`
- `database/migrations/2026_10_22_000003_create_databases_table.php`
