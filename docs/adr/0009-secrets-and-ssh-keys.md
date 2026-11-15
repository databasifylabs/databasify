# 0009. Encrypted secrets, one app SSH key, pinned host keys

- Status: Accepted
- Date: 2026-11-15

## Context

Databasify holds root-level access to every server it manages, plus database passwords, CA keys and provider API tokens. A leaked database dump or process list must not expose them.

## Decision

- **Secrets at rest** are encrypted with the app key: `Database.settings` (`AsDatabaseSettings`), `DatabaseMigration.source` (`AsMigrationSource`), `Server.ssh_password`, `SshKey.private_key` and `DatabaseUser.password` (`encrypted`), `DnsZone.certificate_key` (`AsSecret`) and provider `credentials` (`AsCredentials`). The last two read a value encrypted with another key as missing instead of throwing, because the app can replace them.
- **SSH access** uses one app-wide Ed25519 key (`AppSshKey`).
- **Host keys** are trusted on first connect and pinned; a changed host key refuses the connection (`Server::trustsHostKey`).
- **Secrets reach nodes inside files**, never on command lines where process lists and server logs would show them: inside the uploaded script, in script env, and in engine files under `/srv/<engine>/conf`. Server logs of secret steps keep no output.

## Consequences

- Losing `APP_KEY` makes every stored secret unreadable, so backups of the app must include it ([0013](0013-install-state-and-updates.md)).
- A legitimately reinstalled server must be re-added, because its host key changed.
- The MongoDB default user holds source roles that let it read every database and the user password hashes ([0010](0010-data-protection-and-migration.md)), so its password is as sensitive as the whole server. The other engines' default users get no replication rights from the app.
- Redis, Valkey and Sentinel rewrite their config files, which hold the password, so they keep owner-and-group-only permissions.

## Alternatives considered

- **A per-server SSH key:** not chosen, one key keeps provisioning simple and never leaves the app.

## Evidence

- `app/Casts/AsDatabaseSettings.php`
- `app/Casts/AsMigrationSource.php`
- `app/Casts/AsSecret.php`
- `app/Casts/AsCredentials.php`
- `app/Services/Ssh/AppSshKey.php`
- `app/Models/Server.php`
- `app/Models/SshKey.php`
- `app/Services/Ssh/Ssh.php`
