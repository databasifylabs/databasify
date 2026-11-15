# 0010. Engine-native backups, restores and migrations over plain S3

- Status: Accepted
- Date: 2026-11-15

## Context

Point-in-time recovery and importing data from existing databases are core features. Each engine already has proven tools for both, and they all talk to S3 themselves. A restore or a migration must never risk the data it starts from.

## Decision

- **Point-in-time recovery uses each engine's own tool:** pgBackRest for PostgreSQL, Percona Backup for MongoDB, xtrabackup or mariadb-backup plus binlog streaming and shipping jobs for MySQL and MariaDB. Valkey and Redis have no point-in-time recovery.
- **Storage** is any S3-compatible bucket, reached with a small SigV4 client (`app/Services/Storage`). A test object is written, read and deleted when a bucket is added.
- **A restore always creates a new database** with the source's secrets, settings and image (or the newest supported image of the line when the source is read-only, [0003](0003-engine-images-and-frozen-runtime.md)). It copies the source's user and database records, then keeps only those the restored primary lists (`ReconcileRestoredAccounts`) and resets their passwords to the stored ones.
- **Migrations use engine-native tools:** `pg_dump` plus logical replication for PostgreSQL, MySQL Shell plus a replication channel for MySQL, `mariadb-dump` plus a replication channel for MariaDB, Percona ClusterSync for MongoDB, and redis-shake for Valkey and Redis. The connection to the source is always encrypted and the source certificate is not verified. The source check (`CheckMigrationSource`) refuses sources that cannot work and offers continuous sync only when the source grants what it needs. The target stays writable during a migration.
- **A migration ends once.** Completing, stopping and failing all go through a conditional update (`DatabaseMigration::finishIfActive()`); only the winner runs the engine stop. A stop that fails leaves `cleanup_failed_at` set, so the owner can clean up again.
- **The MongoDB default user can be a continuous migration source.** It holds `backup`, `clusterMonitor` and `readAnyDatabase` on `admin`, granted at bootstrap, after a restore and additively on every image rollout (`GrantMigrationSourceRoles`, only for accounts implementing `MigrationSourceRoles`).
- **Alerts** are email only, sent to every user.
- **Upgrades** found by the daily image check install automatically in each database's weekly maintenance window (UTC day and hour), checked hourly, for databases that are ready.

## Consequences

- Restores never overwrite an existing database.
- Rotated storage keys must reach every database archiving to that bucket. Backups stay in the bucket unless the owner chooses to delete them.
- Writes on a migration target can conflict with changes replicated from the source.
- The MongoDB default user can read every database, the oplog and the user password hashes; existing databases get its roles at their next rollout.
- The maintenance window has no opt-out. Metric samples are kept for 7 days.

## Alternatives considered

- **A storage provider registry, one driver per S3 vendor:** removed, the engine tools talk to S3 themselves.
- **Webhook alerts:** removed, email is the only channel.
- **A separate migration user per MongoDB database:** rejected for now, one more secret to create, rotate and show.

## Evidence

- `app/Actions/Databases/Recovery/`
- `app/Actions/Databases/Migrations/`
- `app/Services/Storage/S3.php`
- `app/Actions/Storage/CheckStorageAccess.php`
- `app/Models/DatabaseMigration.php`
- `app/Models/Database.php`
- `app/Actions/Databases/Accounts/GrantMigrationSourceRoles.php`
- `app/Engines/Contracts/MigrationSourceRoles.php`
- `app/Engines/Drivers/MongoDb/MongoDbAccounts.php`
- `resources/views/engines/mysql-family/jobs/`
- `app/Actions/Databases/Upgrades/`
- `app/Listeners/EmailDatabaseAlert.php`
- `app/Models/NodeMetricSample.php`
