# Phase 4 — capabilities

Each capability is a driver group that is `null` when the engine lacks it: `accounts()` is an `EngineAccounts` when the engine has an `accounts.create-user` script, `archive()` an `EngineArchive` when the driver's `archiveSteps()` returns one (and, per version line, `Database::archivesForRecovery()`), and `migration()` an `EngineMigration` when `migrationSteps()` returns one (mandatory for built-in engines). Callers check for `null` once; code that already knows the capability exists uses `$engine->migration() ?? throw MissingCapability::of($engine, 'migration')`. Offer a capability only when the engine fully supports it.

## Accounts — `accounts.*` scripts

| Method                                                                                    | Notes                                                                                                                                                                                                                                                                                                                                  |
| ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `accounts.create-user`, `drop-user`, `reset-password`, `create-database`, `drop-database` | Views in the engine's `accounts/` folder. They receive `name`, `password`, `databases` (the schemas a user gets), `users` (who gets a new database) and `encryption`, and render the engine's own language (SQL for MySQL and PostgreSQL, mongosh JavaScript for MongoDB). MariaDB maps them to the MySQL views with script overrides. |
| `accounts()->reservedUsers()`, `reservedDatabases()`                                      | Names users may never create or drop (admin, replication, monitoring, backup agent, system databases, a migration checkpoint database).                                                                                                                                                                                                |
| `accounts()->passwordEncryption()`                                                        | `null` when the engine has no choice (hides the encryption UI).                                                                                                                                                                                                                                                                        |

- `EngineAccounts`' `createDatabase()`, `deleteDatabase()`, `createUser()`, `updatePassword()`, `updatePasswordEncryption()` and `deleteUser()` render the statement and run it with `runSql()`. Override reserved names or `passwordEncryption()` in a `<Name>Accounts` subclass returned from `accountSteps()`. Actions call them through `Database::engineAccounts()`, which refuses engines whose `accounts()` is `null` with `MissingCapability::rejectionOf($engine, 'accounts')`. `runSql()` refuses a busy or migrating database, takes the operation lock under the log name, runs on the current primary (no status refresh; no primary is a "has no healthy primary" error), turns failures into validation errors and releases the lock. `accounts.run-sql` receives the rendered text (whatever the language) and pipes it into the engine's client on the primary.
- `nodes()->finishBootstrap()` passes `accounts()->defaultAccountSql()` to `lifecycle.after-bootstrap`; `CreateDatabase` records the `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` secrets as the first user and schema, so `generateSecrets()` must include `DefaultAccount::secrets()`.
- Validate rendered statements in tests: `javascriptErrorsIn()` (node `--check`) for JavaScript, a real parser or exact strings for SQL.
- Without account views the tab shows a single fixed user (`ValkeyAccess`) reading the password from `environment()`; the profile then needs `fixedUsername`.

## Point-in-time recovery — `archive()`

- The driver's protected `archiveSteps()` returns a subclass of `Groups\EngineArchive` from the engine folder (`MysqlArchive`, `MariadbArchive` on `Shared\BinlogArchive`, `PostgresArchive`, `MongodbArchive`), built with `new <Name>Archive($this, $this->database)`. Callers use `archive()`: `files()`, `variables()`, `restoreSource()`, `restore()`, `status()`. The archive owns its tool's config file and paths; `restore()` and `status()` run the `archive.*` steps generically with `scriptData()->restoring()` and `scriptData()->archiving()`.
- The archive's `files($recovery)` writes the archive tool's config with the S3 storage provider's credentials (add an `ArchiveLocation::<tool>…()` mapper next to `pgbackrestRepository()`/`pbmStorage()`); `variables()` feed `archive.*` scripts (`archiveWork`, `archiveSecrets` let `archive-off` clean up); `restoreSource($location)` names what `archive.restore` restores from (the source's config, with continuous archiving off).
- Scheduled backups are wrapper `Schedule`s in `processes()` gated on the primary inside the script; backup agents are `ProcessSpec`s added only while recovery is on, and `lifecycle.start` must enable them.
- Status must report `backups=`, `earliest=`, `latest=`, `last_backup=` (epoch seconds) and `problem=`; include `ssh.databases.recovery.backup-result` and `start-backup`.
- Restore runs before the nodes start: start the engine, initiate it, create the users the tool needs, start a temporary agent with `databasify ctl run`, wait for it, then restore. The earliest time the app offers is a snapshot's own end time — restore that snapshot by name when the target equals it.
- When the tool does not support a line yet, mark the line `'recovery' => false` in the engine's `versions()` registration: the Backups tab disappears, enabling is refused with "<Engine> <line> has no point-in-time recovery.", and the version picker says "no backups".
- Test against the user's real bucket early: S3-compatible stores differ (path style, checksums, regions).

## Migrations — `migration()` (mandatory)

The driver's protected `migrationSteps()` returns a `<Name>Migration` (extends `Groups\EngineMigration`) from the engine folder, built with `new <Name>Migration($this, $this->database)`: `form()`, `check($source)`, `start()`, `progress()`, `follow()` and `stop()`, reading `$this->database` and `$this->engine` instead of taking them. Callers use `migration()`. Scripts live in `migrations/` and keep state under a per-migration directory (`STATE_DIR` in the existing migrations); long copies run as a `databasify ctl run --remain-after-exit` transient unit.

- `check()` returns `SourceReport` facts and blockers; a blocker disables continuous mode only (e.g. "the source must be a replica set").
- `progress()` maps the tool's state onto `MigrationProgress` (`working`, `caughtUp` with lag, `broken`). Lag must reach 0 on an idle source — measure the unapplied backlog, not "now minus the last applied change".
- Read third-party tools' URI handling in their source: MongoShake 2.8.9 drops every query option from URIs with credentials, so TLS and the auth database go through its own config keys.
- Never pass passwords on command lines; write 600 config files in the state directory.

## DNS service records — `->serviceRecord(prefix, options)` (optional)

For engines whose drivers discover the cluster through DNS (MongoDB `mongodb+srv`). With a DNS zone:

- `ServiceRecords` names one A record per member (`<db>-node-<id>.db.<zone>`), an SRV record listing every member on the client port, and a TXT record with the connection defaults (the registered options); `SyncDatabaseDns` publishes them through `PublishServiceRecords`, `ClearDatabaseDns`/`ClearNodeDns` withdraw them. Read-only nodes stay out of SRV and keep their own domain.
- Members must advertise those public names to outside clients (MongoDB: a `public` horizon on every member, all or none), or drivers are sent to private IPs. Certificates include the member names (`Database::certificateNames()`).
- The driver's `connection()` adds the `ServiceConnection` (`withService()`) when the database has a DNS zone; it is shown on the primary's public tab only (no port, `uri_port: false`); standbys, the private network, read-only nodes and databases without a domain keep the direct `ip:port` string with the CA certificate.
- Engines registered without a service record keep the plain model: `<db>.db.<zone>` follows the primary, `replica-<db>` the standbys.
