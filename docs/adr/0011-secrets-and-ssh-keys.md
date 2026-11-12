# 0011. Encrypted secrets, one app SSH key, pinned host keys

- Status: Accepted
- Date: 2026-11-04

## Context

Databasify holds root-level access to every server it manages, plus database passwords, CA keys and provider API tokens. A leaked database dump or process list must not expose them.

## Decision

- **Secrets at rest** are encrypted with the app key:
    - `Database.settings` uses the `AsDatabaseSettings` cast (JSON with secrets, certificates and config, encrypted as one string).
    - `DatabaseMigration.source` uses `AsMigrationSource`.
    - `Server.ssh_password`, `SshKey.private_key`, `DatabaseUser.password` and `DnsZone.certificate_key` use the `encrypted` cast.
    - `credentials` on `ServerProvider`, `DnsProvider` and `StorageProvider` use `encrypted:array`.
- **SSH access** uses one app-wide Ed25519 key (`AppSshKey`, named `databasify`).
- **Host keys** are trusted on first connect and pinned. The app refuses to connect if a host key changes (`Server::trustsHostKey`).
- **Secrets reach nodes inside files**, not on command lines where process lists and server logs would show them: inside the uploaded script (in `~/.databasify`, mode 0700) and in engine files under `/srv/<engine>/conf`. One exception remains: migration copy units receive the source password as a `--setenv` argument to `databasify ctl run`.

## Consequences

- Losing `APP_KEY` makes every stored secret unreadable. Backups of the app must include it.
- A legitimately reinstalled server must be re-added, because its host key changed; there is no way to reset a pinned fingerprint.
- The default user of PostgreSQL, MySQL, MariaDB and MongoDB databases holds replication rights, so it can be a migration source ([0013](0013-data-protection-and-migration.md)). Its password is therefore as sensitive as the data of the whole server. Per engine it is granted:
    - MySQL: `REPLICATION SLAVE` and `REPLICATION CLIENT`, so it can read the binary log, which carries changes to every database on the server; `BACKUP_ADMIN`, whose `LOCK INSTANCE FOR BACKUP` blocks DDL server-wide while held; and `SELECT` on `mysql.default_roles` and `mysql.role_edges`, so it can see every account's roles.
    - MariaDB: `REPLICATION SLAVE` and `BINLOG MONITOR`, so it can read the binary log of every database; and `RELOAD`, so it can flush tables and logs and take the global read lock (`FLUSH TABLES WITH READ LOCK`), which blocks every write on the server while held.
    - PostgreSQL: the `REPLICATION` attribute, so it can open logical replication connections and create replication slots, which hold WAL on the server until they are dropped; `pg_hba.conf` keeps physical replication to `replicator`.
    - MongoDB: the `read` role on `local`, so it can read the whole oplog, which carries changes to every database.
- Redis, Valkey and Sentinel rewrite their config files (`conf/<engine>.conf` and `sentinel/sentinel.conf`, both holding the password) with the process umask. Both processes start under `umask 0027`, so the files stay owner and group only, and their directories are 750.

## Alternatives considered

- **A per-server SSH key:** not chosen. One key keeps provisioning simple, and the key never leaves the app.

## Evidence

- `app/Casts/AsDatabaseSettings.php`
- `app/Casts/AsMigrationSource.php`
- `app/Services/Ssh/AppSshKey.php`
- `app/Models/Server.php`
- `app/Models/SshKey.php`
