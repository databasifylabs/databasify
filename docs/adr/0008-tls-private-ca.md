# 0008. TLS on every engine: a private CA per database, a public wildcard for key-value engines

- Status: Accepted
- Date: 2026-11-15

## Context

Every engine must serve TLS. Public certificates need a domain and a DNS provider, which not every user has. Some clients, notably Redis and Valkey clients, handle custom CAs poorly.

## Decision

- **Private CA per database.** The app issues each database its own CA (EC P-256, 10 years) and a certificate per node (EC P-256, 825 days, `serverAuth` and `clientAuth`) with PHP's openssl extension (`OwnCertificateAuthority`). A node's certificate names the database hostnames and the node's own addresses and hostnames. It is installed through the driver (`driver()->nodes()->installTls()`) and stored in the encrypted database settings ([0009](0009-secrets-and-ssh-keys.md)).
- **TLS on fixed client ports** (`ClientPorts`): 25060 for SQL engines and MongoDB, 25061 for PgBouncer pools and for Valkey and Redis. PostgreSQL accepts only `hostssl` connections, MongoDB runs `requireTLS`, and MySQL and MariaDB users are created with `REQUIRE SSL`.
- **Public wildcard for key-value engines.** Engines registered with `publicCertificate()` (Valkey and Redis) use a Let's Encrypt `*.db.<zone>` wildcard issued over ACME DNS-01 whenever the database has a DNS zone; until it is issued and served by every node, they keep the private CA.
- **Renewal.** A daily check at 02:00 renews certificates that expire within 30 days or no longer match the database's hostnames.

## Consequences

- Clients connect over TLS without the CA by default (for example `sslmode=require`, `tlsAllowInvalidCertificates=true`); the connection details explain how to verify the server with the CA. Valkey and Redis clients verify a public wildcard with no flags.
- MongoDB keeps the private CA even with a DNS zone, because its members verify each other with it on the client port.
- A client that cannot do TLS cannot connect; Databasify fixes clients instead of allowing plaintext.

## Alternatives considered

- **Let's Encrypt for every engine:** dropped, it made a domain and DNS provider mandatory. Kept only for key-value engines.
- **Allowing unencrypted SQL connections, as some hosted services do:** rejected.

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
- `resources/views/engines/mysql-family/accounts/create-user.blade.php`
- `routes/console.php`
