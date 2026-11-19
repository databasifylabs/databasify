# 0014. Direct encrypted SQL exports to file servers

- Status: Proposed
- Date: 2026-11-20

## Context

Some installations have FTP or SFTP storage and need backups without an S3 account. ADR 0010's S3-native recovery tools cannot use arbitrary file-transfer destinations interchangeably. A separate export mode can serve these installations without claiming point-in-time recovery.

## Decision

Add manually configured, project-scoped file backup destinations associated with one database. PostgreSQL, MySQL and MariaDB export with their native SQL dump tools. Dumps are compressed and encrypted on the database host, then transferred directly with rclone. SFTP verifies a supplied host key, FTPS verifies TLS certificates and plain FTP requires acknowledgement. Credentials and encryption passphrases are encrypted in the application database and kept out of command arguments and user-visible logs.

Each destination has a daily UTC schedule and a manual run action. A run succeeds after read-back checksum verification and publishing its completion manifest. Connection probes and upload verification are distinguished from restore verification. Remote backup sets are kept in a destination-specific namespace; this version does not remove remote files automatically.

## Consequences

- S3 is unnecessary for this export mode. ADR 0010's existing S3 point-in-time recovery remains unchanged.
- Native SQL exports have engine-specific consistency and restore constraints. They are not continuous recovery archives.
- The database host needs rclone, GnuPG and temporary space for the export and encrypted file.
- Users need independent copies of encryption passphrases. Removing a destination does not remove its remote backups.
- New engine support needs an explicit export design and an end-to-end restore test.
- This is a local contribution proposal pending upstream review; it is not an accepted upstream architecture decision.

## Alternatives considered

- Pretend FTP is an S3 endpoint: rejected because native archive tools cannot use it.
- Require an S3 gateway: would keep the native archive interface but would not meet the request for direct file-server backups.
- Copy continuous S3 archives to FTP: requires S3 and explicit cross-engine consistency guarantees.

## Evidence

- `app/Models/FileBackupDestination.php`
- `app/Actions/Storage/RunFileBackup.php`
- `app/Actions/Storage/QueueFileBackup.php`
- `resources/views/ssh/backups/`
- `tests/Feature/Storage/FileBackupTest.php`
