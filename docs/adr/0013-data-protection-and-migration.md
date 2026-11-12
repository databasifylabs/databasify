# 0013. Engine-native PITR and migrations over plain S3

- Status: Accepted
- Date: 2026-11-04

## Context

Point-in-time recovery and importing data from existing databases are core features. Each engine already has proven tools for both, and every one of them writes to S3 directly.

## Decision

- **PITR** uses each engine's own tool:
    - PostgreSQL: pgBackRest
    - MongoDB: Percona Backup for MongoDB (not on 9.0 yet)
    - MySQL and MariaDB: xtrabackup or mariadb-backup, plus binlog shipping through rclone (`BinlogArchive`)
    - Valkey and Redis: no PITR
- **PITR restore:** a restore creates a new database with the source's secrets, settings and image, and copies the source's user and database records with their stored passwords and encryption (`Database::copyAccountsFrom`) before its bootstrap job is dispatched. After bootstrap, the engine's `accounts/list` script lists the users and databases on the restored primary (one `user:` or `database:` line each; MongoDB also counts the databases its users are granted), and only the copied records it lists are kept (`ReconcileRestoredAccounts`), so accounts created after the restore point are dropped, as on DigitalOcean. Every kept user, the default user included, is then reset to the password and encryption its record holds, in one `accounts.run-sql` step, so a password changed after the restore point still works. The engine's reserved users and databases are skipped, and the default user and default database are always kept. If the list or the reset fails, or the list lacks the default user, every copied record stays and a failed "Verify restored accounts" server log says the accounts could not be verified; the restore still succeeds.
- **Storage** is any S3-compatible bucket, reached with a small hand-written SigV4 client. A test object is written, read and deleted when a bucket is added.
- **Migrations** use engine-native tools and run as `databasify ctl run` units:
    - PostgreSQL: a full `pg_dump | pg_restore` for one-time migrations; for continuous ones, `pg_dump` of the schema plus logical replication. The publication on each source database is named `databasify_migration_<tag>` and each subscription (and so its replication slot on the source) `databasify_migration_<tag>_<first 20 characters of the database>_<first 8 hex digits of its md5>`, at most 63 characters so PostgreSQL never truncates two long names into one, where the tag is the last 12 hex digits of the target database's id, so two targets migrating from one source never drop or reuse each other's publication or slot. The stop drops the publication each subscription follows, which also cleans up subscriptions made before the tag.
      The restore runs as `postgres`, so untrusted extensions install, and then hands the restored schemas, tables (partitions included; owned sequences follow their table), views, sequences, routines, domains, enum and range types, collations, operators, text-search configurations and dictionaries, and large objects to the default user, one autocommitted `ALTER … OWNER` each (psql `\gexec`), so thousands of tables never hold all their locks at once. Extension members stay with `postgres`. Operator classes and families, conversions, foreign-data wrappers and foreign servers are out of scope and stay owned by `postgres`.
    - MySQL: mysqlsh copy plus a replication channel.
    - MariaDB: `mariadb-dump` plus a `CHANGE MASTER 'migration'` channel.
    - MongoDB: MongoShake. Valkey and Redis: redis-shake.
- **Migration sources:**
    - TLS: every engine encrypts the connection to the source and does not verify its certificate, like DigitalOcean's migration, which has no CA input. A source with a private or self-signed certificate therefore works.
    - PostgreSQL uses `sslmode=require`, MySQL `ssl-mode REQUIRED`, and Valkey and Redis `--tls --insecure` in the check. redis-shake (`tls = true`) never verifies the server certificate.
    - Requirements: the check refuses sources that cannot work, following DigitalOcean's migration requirements. Continuous sync needs:
        - PostgreSQL: `wal_level=logical`, and a user with `REPLICATION` (or superuser) that owns every table it publishes.
        - MySQL: row binlogs, `gtid_mode=ON`, `REPLICATION SLAVE` and `REPLICATION CLIENT`, plus `RELOAD` or `BACKUP_ADMIN` for a consistent snapshot. Every copy also needs `SELECT` on the table the mysqlsh dump reads the user's roles from: `mysql.default_roles`, or `mysql.role_edges` when the source runs with `activate_all_roles_on_login=ON`. Binlog retention (`binlog_expire_logs_seconds`) under 14 days is only a warning.
        - MariaDB: row binlogs, `REPLICATION SLAVE` and `BINLOG MONITOR` (`REPLICATION CLIENT` is its older name).
        - MongoDB: a replica set, and a user that can read the `local` database (the oplog).
    - One-time copies need none of these. Every migration is refused when the source user cannot read some PostgreSQL tables, when a MariaDB source user lacks `RELOAD` (`mariadb-dump --master-data` needs it in both modes), when a selected MySQL database does not exist on the source or the source user cannot see it (the copy would not find it), or when a target database already holds data (PostgreSQL, MySQL and MongoDB). Refusals name at most 10 tables or databases, then "and N more".
    - The default user of every PostgreSQL, MySQL, MariaDB and MongoDB database gets the rights above, so it can be a source: `REPLICATION`; `REPLICATION SLAVE, REPLICATION CLIENT, BACKUP_ADMIN` and `SELECT` on `mysql.default_roles` and `mysql.role_edges`; `REPLICATION SLAVE, BINLOG MONITOR, RELOAD`; the `read` role on `local`. New databases get them at bootstrap; every rollout (redeploy, retune or upgrade) grants them again on the primary, so older databases get them at their next one. That grant is best effort: a failure becomes a failed "Grant replication to the default user" server log and the rollout still succeeds. A missing privilege tells the user to redeploy when the source is an in-app database reached as its default user, otherwise to grant it on the source.
- **Migration target:** stays writable during a migration, as on DigitalOcean. Writes made there can conflict with changes replicated from the source.
- **Migration completion:** when a one-time copy finishes or a continuous migration is cut over, every selected source database is recorded as a database (schema) of the target, once, before the engine stop runs, skipping the engine's reserved databases, names already recorded, and names outside the schema-name charset (`SchemaName`, the rule `StoreSchemaRequest` also uses: a letter, then letters, digits and underscores), because the create-database and drop-database SQL renders names as-is. A failed "Record migrated databases" server log names the skipped ones. The PostgreSQL and MariaDB copies also skip discovered source databases outside that charset, and the PostgreSQL check does not look at them. Selected names must match that charset at the request too (`SourceForm`). The default user is granted every recorded name each time, not only new ones, so a retried completion repeats an interrupted grant, with the engine's idempotent create-database SQL (SSH timeout `EngineAccounts::GRANT_TIMEOUT`, 120 s), best effort: a failure becomes a failed "Grant migrated databases to the default user" server log. Other users get no new grants. With no databases selected the engine still copies and replicates every database, as on DigitalOcean. Only the recording differs: the source check runs again at completion and its user databases (`SourceReport::$databases`) are recorded, so databases created on the source mid-migration are included; if the source is unreachable then, the names the check found at start (`MigrationSource::$discovered`) are recorded instead. Recording uses `createOrFirst` per name, so concurrent completions never fail on a duplicate. Valkey and Redis have no databases to record. A migration that is stopped or fails before finishing records the copied databases that already exist on the target (selected, or found at start, that the target's `accounts/list` probe lists; same charset rule, no grants), so they can be deleted on the Users & Databases tab. The refusal of a target database that already holds rows points there.
- **Migration end:** a migration ends once. Completing, stopping and failing it go through `DatabaseMigration::finishIfActive()`, a conditional `UPDATE ... WHERE status IN (copying, syncing)` (atomic on SQLite too). Every end claims the new status first and only the winner touches the server: it runs the engine's stop (`CleanUpMigration`), raises the failure alert or records the schemas; a losing refresh, stop or failure runs no script. The claim also sets `cleanup_failed_at`, and only a successful engine stop clears it, so a stop that fails, or a worker killed after the claim, leaves the migration ended and cleanable; a failed stop also writes a failed "Clean up migration" server log saying the copy or replication may still be running. The engine stop's SSH timeout is `EngineMigration::STOP_TIMEOUT` (240 s), under half of every migration job's timeout. The migrations tab then shows "Clean up again" on that migration: a force stop, which is allowed on an ended migration only while `cleanup_failed_at` is set and no other migration is active on the database (the engine stop scripts use fixed unit, channel, subscription and state-dir names, so they would also stop the newer migration), and only runs the idempotent engine stop again; a successful stop clears `cleanup_failed_at`. A continuous migration first claims `copying → syncing` (`claimSyncing()`, `WHERE status = copying`) and only the winner runs the engine's replicate step, so replication starts at most once. After the start of the copy (`RunMigration`) or the replicate step returns, or fails while a stop already won, the status is read again and the engine stop runs if the migration has ended meanwhile (`CleanUpEndedMigration`). The replicate-step check only matters for MySQL and MariaDB, whose `follow()` creates the replication channel; the other engines' `follow()` is empty. The start check matters for every engine. Progress, lag, stall and copying readings use the same conditional update (stalls increment in SQL), so a late reading never rewrites an ended migration.
- **Alerts** are email only, sent to all users.
- **Upgrades:** a daily check finds newer images ([0004](0004-image-digest-pinning.md)), and they install automatically in a weekly maintenance window (day and hour in UTC, default Sunday 03:00), checked hourly, for databases that are ready.

## Consequences

- Restores always create a new database rather than overwriting one.
- Rotated storage keys must be pushed to every database archiving to that bucket.
- Backups stay in the bucket unless the user chooses to delete them when turning PITR off or when deleting the database.
- PostgreSQL `latest_restorable_at` lags right after the primary moves.
- The maintenance window has no opt-out.
- Metric samples are kept for 7 days.
- The MongoDB default user's `read` on `local` lets it read the oplog, so it sees every database's changes, not only the databases it owns.

## Alternatives considered

- **A storage provider registry** (one driver per S3 vendor): removed, because the engine tools talk to S3 themselves.
- **Webhook alerts:** removed. Email is the only channel.

## Evidence

- `app/Actions/Databases/Recovery/`
- `app/Actions/Databases/Migrations/`
- `app/DTOs/Migrations/SourceReport.php`
- `app/Support/Databases/SchemaName.php`
- `app/DTOs/Databases/ServerAccounts.php`
- `app/Models/DatabaseMigration.php`
- `app/Actions/Databases/RunOnPrimary.php`
- `app/Engines/Flows/UpgradeFlow.php`
- `app/Actions/Databases/Migrations/CheckMigrationSource.php`
- `app/Actions/Databases/Upgrades/`
- `app/Services/Storage/`
- `app/Listeners/EmailDatabaseAlert.php`
- `app/Models/NodeMetricSample.php`
