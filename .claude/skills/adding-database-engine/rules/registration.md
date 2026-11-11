# Phase 3: the app registration

The app holds only what it needs per engine (`App\Engines\EngineCatalog`, ADR 0035). Everything else comes from the engine at runtime.

## Files

| File                                                  | Change                                                                                                                       |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `app/Providers/EngineServiceProvider.php`             | One `RegisterEngine` chain in `boot()`, built from the `engine()` or `sentinel()` helper. The id is the image's engine name. |
| `public/assets/icons/database-providers/<engine>.svg` | The logo, square SVG, named after the engine id (the registration's default icon path).                                     |
| `README.md`                                           | The engine and its lines in the supported list.                                                                             |
| ADRs in `docs/adr/`                                   | Update any ADR that lists the engines (0009 HA per engine, 0012 TLS, 0014 DNS hostnames) in the same change.                |

## The registration

| Method                                                                 | Notes                                                                                                                                                                                  |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `label()`, `category()`, `icon()`                                      | `Application` or `Caching` groups the create page. `engine()` sets all three.                                                                                                          |
| `versions(default, lines)`                                             | One entry per `versions.json` line, labelled as users know it (`MySQL 8.4 LTS`). Add `'recovery' => false` to a line whose backup tool doesn't support it yet: the Backups tab disappears and the version picker says so. |
| `image()`                                                              | Only when the image isn't `ghcr.io/databasifylabs/databasify-<id>` (`databasify.images.registry` and `repository`).                                                                  |
| `connection(profile, withPublicCertificate, service)`                  | A `ConnectionProfile`: URI scheme and query, TLS parameter and mode, a CLI command, a fixed username, whether a database is selected, the `.env` keys and notes. `{host}`, `{port}`, `{username}`, `{password}`, `{database}` and `{ca}` are filled in per database. `withPublicCertificate` replaces it on a domain with the zone's public certificate; `service` adds a DNS-discovered string (`mongodb+srv`). |
| `environment(laravelConnection, extra)`                                | The Laravel connection for the `.env` snippet (`pgsql`, `mysql`, `mongodb`, or `null`) and extra keys (`DB_SSLMODE`).                                                                  |
| `migrationSource(defaultPort, credentialsRequired, selectsDatabases)`  | Every built-in engine offers migrations; this sets the source form.                                                                                                                    |
| `failoverHint()`                                                       | What the Nodes tab says when the database can't fail over on its own.                                                                                                                  |
| `serviceRecord(prefix, options)`                                       | Only for engines whose drivers discover the cluster through DNS (MongoDB `_mongodb._tcp`). With a domain, the app publishes one A record per member, an SRV and a TXT record.          |
| `publicCertificate()`                                                  | The engine serves the zone's public Let's Encrypt wildcard on a domain instead of the database's CA (Valkey and Redis, ADR 0012).                                                     |
| `persistenceChoice()`, `readOnlyNodesVote()`                           | Snapshot or append-only persistence, and read-only nodes counting as failover voters (Sentinel).                                                                                       |

Derived automatically, with no change:

- Settings, password encryptions, reserved names, ports and the features shown (`engine capabilities`, `config fields`, stored by `LearnEngine`).
- The database list filter and search (`DatabaseFilters`), the dashboard's system requirements, and the engines the create form offers and validates (`StoreDatabaseRequest`).
- `tests/Support/FakeImageRegistry.php`, which publishes `<line>.0` for every registered line.

## When the engine needs a new command or field

Add the command to `engine-sdk` first and implement it in the engine (phase 1), then mirror it here name for name: a request in `app/Engines/Requests` with its `command()` and `toPayload()`, a reply in `app/Engines/Responses` with `fromPayload()`, and shared data in `app/Engines/Data`. Send it with `EngineClient::send()` from an action, or from a flow step through `FlowSteps` (`send()` for quick commands with a server log name, `long()` for operations). Never add an engine-specific branch to a flow: an engine difference belongs in the engine.
