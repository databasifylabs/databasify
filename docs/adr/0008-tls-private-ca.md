# 0008. TLS on every engine: a private CA per database, a public wildcard for key-value engines

- Status: Accepted
- Date: 2026-11-17

## Context

Every engine must serve TLS. Public certificates need a domain and a DNS provider, which not every user has. Some clients, notably Redis and Valkey clients, handle custom CAs poorly. Common SQL clients such as Laravel's MySQL driver connect without TLS unless extra options or a CA file are set.

## Decision

- **Private CA per database.** The app issues each database its own CA (EC P-256, 10 years) and a certificate per node (EC P-256, 825 days, `serverAuth` and `clientAuth`) with PHP's openssl extension (`OwnCertificateAuthority`). A node's certificate names the database hostnames and the node's own addresses and hostnames. It is installed through the driver (`driver()->nodes()->installTls()`) and stored in the encrypted database settings ([0009](0009-secrets-and-ssh-keys.md)).
- **TLS served on fixed client ports** (`ClientPorts`): 25060 for SQL engines and MongoDB, 25061 for PgBouncer pools and for Valkey and Redis.
- **SQL clients choose.** PostgreSQL accepts client connections through `host` rules and PgBouncer through `client_tls_sslmode = prefer`. MySQL and MariaDB users are created and saved with `REQUIRE NONE`; users created before this decision keep `REQUIRE SSL` until they are saved again, with no migration. Connection details still show TLS as required (`ssl-mode=REQUIRED`, `sslmode=require`), so clients that copy them encrypt, while the server itself does not insist; they explain how to verify the server with the CA.
- **Everything else stays TLS-only.** MongoDB runs `requireTLS`, Valkey and Redis serve TLS only, and internal links (PostgreSQL replication `hostssl`, MySQL and MariaDB replication users with `REQUIRE SSL`, Group Replication and Galera traffic, PgBouncer to PostgreSQL) keep requiring it.
- **Public wildcard for key-value engines.** Engines registered with `publicCertificate()` (Valkey and Redis) use a Let's Encrypt `*.db.<zone>` wildcard issued over ACME DNS-01 whenever the database has a DNS zone; until it is issued and served by every node, they keep the private CA.
- **Renewal.** A daily check at 02:00 renews certificates that expire within 30 days or no longer match the database's hostnames.

## Consequences

- SQL clients that copy the connection details use TLS; clients left on their defaults, such as Laravel's MySQL driver, connect without it. Whether a connection is encrypted is the client's choice, and the firewall and trusted sources ([0007](0007-networking-and-firewall.md)) remain the guard on who can reach the port.
- MongoDB, Valkey and Redis clients connect over TLS without the CA by default (for example `tlsAllowInvalidCertificates=true`); Valkey and Redis clients verify a public wildcard with no flags.
- MongoDB keeps the private CA even with a DNS zone, because its members verify each other with it on the client port.
- PostgreSQL databases pick up the new `pg_hba.conf` and PgBouncer setting on their next configure.

## Alternatives considered

- **TLS required for SQL clients:** replaced on 2026-11-17; clients that connect without TLS by default failed until they were given a CA file or extra driver options.
- **Loosening existing MySQL and MariaDB users automatically:** rejected, the owner did not want a backward-compatibility step.
- **Let's Encrypt for every engine:** dropped, it made a domain and DNS provider mandatory. Kept only for key-value engines.

## Evidence

- `app/Support/Tls/OwnCertificateAuthority.php`
- `app/Support/Databases/ClientPorts.php`
- `app/Actions/Databases/Certificates/InstallNodeCertificate.php`
- `app/Actions/Databases/Certificates/IssueNodeCertificates.php`
- `app/Actions/Databases/Certificates/RenewCertificates.php`
- `app/DTOs/Databases/IssuedCertificate.php`
- `app/Services/Acme/LetsEncrypt.php`
- `app/Actions/Dns/IssueZoneCertificate.php`
- `app/Engines/Drivers/Postgres/PostgresFiles.php`
- `app/Engines/Drivers/MongoDb/MongoDbFiles.php`
- `app/Providers/EngineServiceProvider.php`
- `resources/views/engines/postgres/files/pgbouncer-ini.blade.php`
- `resources/views/engines/mysql-family/accounts/create-user.blade.php`
- `resources/views/engines/mysql-family/accounts/set-password.blade.php`
